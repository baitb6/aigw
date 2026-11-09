<?php
/**
 * 邮件接口
 * ------------------------------------------------------------
 *   POST /api/user/email-code         发送验证码（注册/找回密码/改密码，公开接口 + 冷却配额）
 *   POST /api/admin/mail/test         测试发信（管理员，回传 SMTP 原始会话便于排查）
 *   POST /api/admin/mail/test-connect 仅测试连接与认证（不发信）
 *
 * 三个 purpose 的鉴权与策略**各不相同**，改动前先看清这张表：
 *   register —— 公开；受注册邮箱域名白名单约束；邮箱已注册直接拒（防枚举浪费）
 *   reset    —— 公开；**不受域名白名单约束**（存量非白名单用户必须能找回密码）；
 *               邮箱未注册时**静默成功**（不泄露账号是否存在）
 *   change   —— **必须已登录**；收件地址强制取会话里的账号邮箱（忽略前端传值）；
 *               不受域名白名单约束（改的是自己账号）；免图形验证码（已鉴权）
 *
 * 端口说明：SMTP 端口不做白名单限制，80 / 2525 等非常规端口可直接使用；
 * 加密方式支持 none（明文）、ssl（连接即 TLS）、tls（STARTTLS）、auto（按端口推断）。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Middleware\UserAuthMiddleware;
use App\Repositories\EmailCodeRepository;
use App\Repositories\GatewayRepository;
use App\Repositories\UserRepository;
use App\Support\Audit;
use App\Support\CaptchaGate;
use App\Support\ClientIp;
use App\Support\Logger;
use App\Support\MailDomain;
use App\Support\PublicGuard;
use App\Support\MailService;
use App\Support\Mailer;

class MailController
{
    /** 验证码允许的用途（防止前端传任意 purpose 写脏数据） */
    private const PURPOSES = ['register', 'reset', 'change'];

    public function __construct(
        private GatewayRepository $gatewayRepository,
        private EmailCodeRepository $codeRepository,
        private MailService $mailService,
        private UserRepository $userRepository,
        private CaptchaGate $captchaGate,
        private PublicGuard $publicGuard,
    ) {}

    /**
     * POST /api/user/email-code
     * body: { email?, purpose?, captcha? }
     *
     * purpose=change 时 email 由会话决定，前端传什么都会被忽略。
     */
    public function sendUserCode(Request $request): Response
    {
        $purpose = trim((string) $request->input('purpose', 'register'));
        if (!in_array($purpose, self::PURPOSES, true)) {
            $purpose = 'register';
        }

        // 改密码场景：**收件地址只能来自会话**。
        //
        // 🔴 若沿用前端传的 email，就等于开放了一个「用别人的邮箱收验证码」的入口：
        //    攻击者拿会话请求 change 码、把 email 填成受害者的地址 —— 只要后续校验
        //    也看这个 email，就能把别人的邮箱验证码套到自己的改密流程上。
        //    地址必须由服务端根据登录态推导，前端无从干预。
        $user = null;
        if ($purpose === 'change') {
            $user = $this->currentUser();
            if ($user === null) {
                Audit::fail('email.code', 401, [
                    'actor_type' => 'guest',
                    'summary'    => '发送改密码验证码被拒（未登录）',
                    'detail'     => ['purpose' => $purpose],
                ]);
                return $this->fail('请先登录', 401);
            }
            $email = EmailCodeRepository::normalize((string) ($user['email'] ?? ''));
        } else {
            $email = EmailCodeRepository::normalize((string) $request->input('email', ''));
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('请输入正确的邮箱地址');
        }

        // 邮箱域名白名单 —— 与注册口子共用同一条规则（单一真源 MailDomain）。
        //
        // ⚠️ 这一处**必须**有，不能只拦注册：
        //   「发验证码」是免费给任意地址发信的入口。若不拦，攻击者仍可用
        //   一次性邮箱刷本域的发信配额，把站点 IP/域名的发信信誉烧掉
        //   （表现为后续真实用户收不到信、甚至被上游 SMTP 拉黑）。
        //   放在格式校验之后、SMTP 就绪判定之前：域名不合法就没必要再往下走。
        //
        // ⚠️ 只对 purpose=register 生效，**reset / change 必须豁免**：
        //   白名单是「新注册口子」的策略。存量用户里一定有人是改策略之前用
        //   非白名单邮箱注册的 —— 若连 reset 也按白名单拦，这批人**永远收不到
        //   找回密码邮件**，账号直接变成不可恢复。这是策略误伤成功能故障的典型，
        //   故 reset 只受「邮箱格式 + 频控 + 日配额」约束，不受域名白名单约束。
        //   change 改的是自己账号、地址取自会话，同理不受约束。
        if ($purpose === 'register') {
            $domainRule = (string) $this->gatewayRepository->get('register_email_domains', '');
            if (!MailDomain::allows($email, $domainRule)) {
                Audit::fail('email.code', 400, [
                    'actor_type' => 'guest',
                    'summary'    => '发送邮箱验证码被拒（域名不在白名单）：' . mb_substr($email, 0, 64),
                    'detail'     => ['email' => $email, 'purpose' => $purpose, 'domain' => MailDomain::domainOf($email), 'rules' => $domainRule],
                ]);
                return $this->fail(MailDomain::message($domainRule));
            }
        }

        if (!$this->mailService->ready()) {
            return $this->fail('站点尚未开启邮件服务，请联系管理员', 503);
        }

        // IP 维度频控 —— 放在图形验证码**之前**：
        //   ① 被频控时直接 429，不消耗「取图 + 答图」这一步，也不消耗用户已拿到的挑战；
        //   ② 挡住的是「同一 IP 短时间反复请求」，而不是「猜错答案」，语义与 CaptchaGate 不重叠。
        // 这一层是上线当天的第一道闸：日配额（canSend）秒级挡不住，图形验证码挡不了不答题的灌请求。
        $ip = ClientIp::resolve($request);
        $rate = $this->publicGuard->hit('email_code', $ip);
        if (!$rate['allowed']) {
            Audit::fail('email.code', 429, [
                'actor_type' => 'guest',
                'summary'    => '发送邮箱验证码被拒（IP 频控）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'purpose' => $purpose, 'reason' => 'ip_rate_limit', 'retry_after' => $rate['retry_after']],
            ]);
            return Response::json([
                'ok'    => false,
                'error' => [
                    'message'     => '操作过于频繁，请 ' . max(1, $rate['retry_after']) . ' 秒后再试',
                    'retry_after' => $rate['retry_after'],
                ],
            ], 429);
        }

        // 图形验证码 —— 发验证码是**对外可被刷**的入口，默认强制人机校验
        // （CLI 自检自动放行，否则测试脚本没法跑）。
        // 校验 + 失败锁定统一走 CaptchaGate（IP + 会话双维度，清 cookie 绕不过）。
        //
        // purpose=change 例外：该分支已要求登录（会话本身即是人机凭证），
        // 再叠一次图形验证码只是纯摩擦 —— 而这里要防的「匿名刷信」已经不成立。
        if ($purpose !== 'change' && $this->mailCodeCaptchaRequired()) {
            $captchaError = $this->captchaGate->check($request);
            if ($captchaError !== null) {
                Audit::fail('email.code', 400, [
                    'actor_type' => 'guest',
                    'summary'    => '发送邮箱验证码被拒（图形验证码）：' . mb_substr($email, 0, 64),
                    'detail'     => ['email' => $email, 'purpose' => $purpose, 'reason' => 'captcha', 'message' => $captchaError],
                ]);
                return $this->fail($captchaError);
            }
        }

        // 注册场景：邮箱已存在则不必发码（统一提示，降低枚举风险）
        if ($purpose === 'register' && $this->userRepository->findByEmail($email) !== null) {
            return $this->fail('该邮箱已被注册', 409);
        }

        // 🔴 找回密码：账号不存在时**静默成功**（不建码、不发信，但返回与成功完全一致的响应）。
        //
        // 为什么必须这样：若回「该邮箱未注册」，这个免登录接口就成了**账号枚举器** ——
        // 攻击者可批量跑一遍邮箱名单，筛出哪些在本站有账号，再对这些目标定向撞库/钓鱼。
        // 统一响应把「是否存在」从接口语义里彻底移除；真正的兜底是下一步的登录限流。
        //
        // ⚠️ 但**仍要占用配额**：下面不做 return，而是继续走 canSend 与 create，
        //    只有在真正发信那一步才跳过。否则攻击者只用不存在的邮箱就能绕过
        //    per-IP 日配额（那些请求不落库），等于给了个免费的枚举通道。
        $resetUnknown = $purpose === 'reset' && $this->userRepository->findByEmail($email) === null;

        $s = $this->gatewayRepository->current();
        $cooldown = (int) ($s['mail_code_cooldown'] ?? 60);
        $dailyEmail = (int) ($s['mail_code_daily_email'] ?? 10);
        $dailyIp = (int) ($s['mail_code_daily_ip'] ?? 30);
        $ttl = (int) ($s['mail_code_ttl'] ?? 300);

        $gate = $this->codeRepository->canSend($email, $purpose, $ip, $cooldown, $dailyEmail, $dailyIp);
        if (!$gate['ok']) {
            $retry = 0;
            if (preg_match('/(\d+)s/', $gate['reason'], $m)) {
                $retry = (int) $m[1];
            }
            Audit::fail('email.code', 429, [
                'actor_type' => 'guest',
                'summary'    => '发送邮箱验证码被拒（触发限流）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'purpose' => $purpose, 'reason' => $gate['reason']],
            ]);
            return Response::json([
                'ok'    => false,
                'error' => [
                    'message'     => $retry > 0 ? ('发送过于频繁，请 ' . $retry . ' 秒后再试') : '发送过于频繁，请稍后再试',
                    'retry_after' => $retry,
                ],
            ], 429);
        }

        // 生成 6 位数字码
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->codeRepository->create($email, $purpose, $code, $ttl, $ip);

        // 账号不存在：码已入库（配额已消耗、响应时序一致），但邮件不发。
        if ($resetUnknown) {
            Logger::info('找回密码：邮箱未注册，静默返回', ['email' => $email, 'ip' => $ip]);
            Audit::ok('email.code', [
                'actor_type' => 'guest',
                'summary'    => '找回密码请求（邮箱未注册，未发信）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'purpose' => $purpose, 'silent' => true],
            ]);
            return Response::json([
                'ok'      => true,
                'message' => '验证码已发送，请查收邮件',
                'data'    => ['ttl' => $ttl, 'cooldown' => $cooldown],
            ]);
        }

        $result = $this->mailService->sendVerifyCode($email, $code, $ttl, $purpose);
        if (!$result['ok']) {
            Logger::warn('验证码邮件发送失败', [
                'email' => $email,
                'purpose' => $purpose,
                'error' => $result['error'],
            ]);
            Audit::fail('email.code', 502, [
                'actor_type' => 'guest',
                'summary'    => '验证码邮件发送失败：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'purpose' => $purpose, 'error' => $result['error']],
            ]);
            $hint = $this->smtpHint((string) $result['error']);
            return $this->fail('验证码发送失败' . ($hint !== '' ? '：' . $hint : '，请稍后重试'), 502);
        }

        Logger::info('验证码邮件已发送', ['email' => $email, 'purpose' => $purpose, 'ip' => $ip]);
        Audit::ok('email.code', [
            'actor_type' => $purpose === 'change' ? 'user' : 'guest',
            'actor_id'   => $user !== null ? (int) $user['id'] : null,
            'user_id'    => $user !== null ? (int) $user['id'] : null,
            'summary'    => '发送邮箱验证码：' . $email,
            'detail'     => ['email' => $email, 'purpose' => $purpose, 'ttl' => $ttl],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '验证码已发送，请查收邮件',
            'data'    => ['ttl' => $ttl, 'cooldown' => $cooldown],
        ]);
    }

    /**
     * POST /api/admin/mail/test
     * body: { to? }  —— 不传则发到 mail_from_email
     */
    public function test(Request $request): Response
    {
        $to = EmailCodeRepository::normalize((string) $request->input('to', ''));
        if ($to === '') {
            $to = EmailCodeRepository::normalize((string) $this->gatewayRepository->get('mail_from_email', ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('请提供有效的收件邮箱');
        }
        if (!$this->mailService->ready()) {
            return $this->fail('邮件服务未开启或 SMTP 配置不完整（服务器地址/端口必填）');
        }

        $result = $this->mailService->sendTest($to);

        Audit::ok('mail.test', [
            'target_type' => 'gateway',
            'target_id'   => 'smtp',
            'summary'     => ($result['ok'] ? 'SMTP 测试发信成功 → ' : 'SMTP 测试发信失败 → ') . $to,
            'detail'      => [
                'to'         => $to,
                'server'     => $this->mailService->serverDesc(),
                'ok'         => $result['ok'],
                'error'      => $result['error'],
            ],
        ]);

        return Response::json([
            'ok'    => $result['ok'],
            'error' => $result['ok'] ? null : ['message' => $result['error']],
            'data'  => [
                'to'         => $to,
                'server'     => $this->mailService->serverDesc(),
                'transcript' => $this->maskTranscript($result['transcript']),
            ],
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * POST /api/admin/mail/test-connect —— 只握手不发信
     */
    public function testConnect(Request $request): Response
    {
        if (!$this->mailService->ready()) {
            return $this->fail('邮件服务未开启或 SMTP 配置不完整（服务器地址/端口必填）');
        }

        $mailer = $this->mailService->mailer();
        $ok = $mailer->testConnection();

        Audit::ok('mail.test', [
            'target_type' => 'gateway',
            'target_id'   => 'smtp',
            'summary'     => ($ok ? 'SMTP 连接测试成功：' : 'SMTP 连接测试失败：') . $mailer->lastError(),
            'detail'      => ['server' => $this->mailService->serverDesc(), 'ok' => $ok, 'error' => $mailer->lastError()],
        ]);

        return Response::json([
            'ok'    => $ok,
            'error' => $ok ? null : ['message' => $mailer->lastError()],
            'data'  => [
                'server'     => $this->mailService->serverDesc(),
                'encryption' => (string) $this->gatewayRepository->get('mail_encryption', 'auto'),
                'resolved'   => Mailer::guessEncryption((int) $this->gatewayRepository->get('mail_port', 465)),
                'transcript' => $this->maskTranscript($mailer->transcript()),
            ],
        ], $ok ? 200 : 502);
    }

    /**
     * GET /api/admin/mail/config —— 邮件设置回显（含诊断信息，不含口令）
     */
    public function show(Request $request): Response
    {
        $s = $this->gatewayRepository->current();
        $port = (int) ($s['mail_port'] ?? 465);

        return Response::json([
            'ok'   => true,
            'data' => [
                'mail_enabled'          => (bool) ($s['mail_enabled'] ?? false),
                'mail_host'             => (string) ($s['mail_host'] ?? ''),
                'mail_port'             => $port,
                'mail_username'         => (string) ($s['mail_username'] ?? ''),
                'mail_password_set'     => !empty($s['mail_password_set']),
                'mail_encryption'       => (string) ($s['mail_encryption'] ?? 'auto'),
                'mail_from_email'       => (string) ($s['mail_from_email'] ?? ''),
                'mail_from_name'        => (string) ($s['mail_from_name'] ?? ''),
                'mail_connect_timeout'  => (int) ($s['mail_connect_timeout'] ?? 10),
                'mail_read_timeout'     => (int) ($s['mail_read_timeout'] ?? 20),
                'mail_allow_insecure'   => (bool) ($s['mail_allow_insecure'] ?? true),
                'mail_verify_required'  => (bool) ($s['mail_verify_required'] ?? false),
                'mail_code_ttl'         => (int) ($s['mail_code_ttl'] ?? 300),
                'mail_code_cooldown'    => (int) ($s['mail_code_cooldown'] ?? 60),
                'mail_code_daily_email' => (int) ($s['mail_code_daily_email'] ?? 10),
                'mail_code_daily_ip'    => (int) ($s['mail_code_daily_ip'] ?? 30),
                'ready'                 => $this->mailService->ready(),
                'resolved_encryption'   => Mailer::guessEncryption($port),
                'enable_hint'           => $this->enableHint(),
            ],
        ]);
    }

    /* ── 内部 ────────────────────────────────────────────── */

    /**
     * 当前登录用户（取库记录，以便拿到最新邮箱）。
     *
     * 该接口未挂 UserAuthMiddleware（注册/找回密码必须免登录），所以不能依赖
     * 中间件注入的登录态 —— 但 purpose=change 又必须确认「谁在请求」。
     * 这里显式读会话 + 回库校验账号仍存在且启用，避免仅凭一份旧 session
     * 就给已禁用账号发码。
     */
    private function currentUser(): ?array
    {
        UserAuthMiddleware::bootSession();
        $session = UserAuthMiddleware::current();
        if ($session === null && session_status() === PHP_SESSION_ACTIVE) {
            $session = $_SESSION['aigw_user'] ?? null;
        }
        if (!is_array($session) || empty($session['id'])) {
            return null;
        }

        $user = $this->userRepository->find((int) $session['id']);
        if ($user === null || (string) ($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return null;
        }

        return $user;
    }

    /**
     * 把 SMTP 会话里的敏感行打码（AUTH 之后的客户端凭据行）
     *
     * 注意：AUTH LOGIN 是「服务端 334 提示 → 客户端 base64 凭据」交替进行，
     * 服务端提示行不消耗隐藏额度，只有客户端 `>>` 行需要被打码。
     */
    private function maskTranscript(array $lines): array
    {
        $out = [];
        $pendingCreds = 0;

        foreach ($lines as $line) {
            $isOut = str_starts_with($line, '>>');

            if (preg_match('/^>>\s*AUTH LOGIN/i', $line)) {
                $pendingCreds = 2; // 接下来两个客户端行是 base64 用户名、口令
                $out[] = $line;
                continue;
            }
            if (preg_match('/^>>\s*AUTH PLAIN/i', $line)) {
                $out[] = '>> AUTH PLAIN [已隐藏凭据]';
                continue;
            }
            if ($pendingCreds > 0 && $isOut) {
                $out[] = '>> [已隐藏凭据]';
                $pendingCreds--;
                continue;
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * 失败时给一句「最可能的原因」——避免管理员对着一串 errno 发呆
     *
     * 归因顺序：**先看服务端原话，再看配置，最后才猜端口**。
     * 反过来的话，一个「口令没保存」的故障会被提示成「换个端口试试」，
     * 管理员越排查越远。
     */
    private function smtpHint(string $error = ''): string
    {
        // ① 服务端已经明说是空凭据（阿里云 `530 Username or passwd is NULL`）
        if ($error !== '' && stripos($error, '530') !== false && stripos($error, 'NULL') !== false) {
            return 'SMTP 用户名或口令为空 —— 口令很可能没有保存成功，'
                . '请到「设置 → 邮件」重新填写 SMTP 密码并确认保存';
        }
        // ② Mailer 在发出请求前就拦住的口令缺失
        if ($error !== '' && strpos($error, 'SMTP 口令为空') !== false) {
            return 'SMTP 口令未设置：请在「设置 → 邮件」填写 SMTP 密码并保存后再试';
        }

        $s = $this->gatewayRepository->current();

        // ③ 配置层自查：邮件开着、但库里根本没有口令 —— 这是最容易被忽略的静默漂移
        if ((bool) ($s['mail_enabled'] ?? false) && !(bool) ($s['mail_password_set'] ?? false)) {
            return '邮件服务已开启，但 SMTP 口令从未保存成功：请到「设置 → 邮件」填写口令并保存';
        }

        $port = (int) ($s['mail_port'] ?? 0);
        $enc = (string) ($s['mail_encryption'] ?? 'auto');

        if ($port === 25) {
            return '25 端口常被运营商封禁，建议改用 465/587，或把 SMTP 服务挂到 80 端口';
        }
        if ($port === 80 && $enc === 'ssl') {
            return '80 端口通常不是隐式 TLS，请把加密方式改为「明文」或「STARTTLS」';
        }
        return '';
    }

    /** 端口 → PHP 扩展需求提示 */
    private function enableHint(): string
    {
        $port = (int) $this->gatewayRepository->get('mail_port', 465);
        if (in_array($port, [465, 587, 25, 80, 2525], true)) {
            return '当前端口无需额外扩展，使用 PHP 原生 stream 套接字发送';
        }
        return '非常规端口 ' . $port . '：请确认防火墙/安全组已放行出站';
    }

    private function captchaRequired(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return (bool) $this->gatewayRepository->get('captcha_enabled', true);
    }

    /**
     * 发码前是否强制图形验证码。
     * 默认开（对外可刷入口必须有人机闸门）；管理员可用 mail_code_captcha 显式关闭
     * 以应对验证码服务故障，不必改代码。
     */
    private function mailCodeCaptchaRequired(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return (bool) $this->gatewayRepository->get('mail_code_captcha', true);
    }

    /**
     * 客户端真实 IP（CDN 场景必须走 ClientIp，直读 REMOTE_ADDR 拿到的是回源节点）。
     * 目前无调用点，保留为统一出口，避免将来有人又直读 $_SERVER。
     */
    private function ip(?Request $request = null): string
    {
        return ClientIp::resolve($request ?? new Request());
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
