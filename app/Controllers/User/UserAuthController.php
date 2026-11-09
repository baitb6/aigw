<?php
/**
 * 用户端 · 注册 / 登录 / 会话 / 资料 / 独立 API Key
 *
 * 安全要点：
 *   - 密码 password_hash(PASSWORD_DEFAULT) 存储，绝不回传
 *   - 注册开关由管理端「开放注册」控制
 *   - 登录失败锁定策略可配置（默认 5 次 / 900 秒），IP + 账号双维度持久化，防清 cookie 绕过
 *   - 登录/注册可开启图形验证码（管理端开关）
 *   - 会话 session_regenerate_id(true) 防固定攻击
 */

declare(strict_types=1);

namespace App\Controllers\User;

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
use App\Support\LoginGuard;
use App\Support\MailDomain;
use App\Support\MailService;
use App\Support\PasswordPolicy;
use App\Support\PublicGuard;

class UserAuthController
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{3,32}$/';
    private const PASSWORD_MIN = 8;

    public function __construct(
        private UserRepository $userRepository,
        private GatewayRepository $gatewayRepository,
        private LoginGuard $loginGuard,
        private MailService $mailService,
        private EmailCodeRepository $codeRepository,
        private CaptchaGate $captchaGate,
        private PublicGuard $publicGuard,
        private PasswordPolicy $passwordPolicy,
    ) {}

    /** GET /api/user/register-config —— 供前端决定是否展示注册入口 */
    public function registerConfig(Request $request): Response
    {
        $mailVerify = $this->mailVerifyRequired();
        $captcha = $this->captchaRequired();

        return Response::json([
            'ok'   => true,
            'data' => [
                'allow_register'  => (bool) $this->gatewayRepository->get('allow_register', true),
                'captcha_enabled' => $captcha,
                // 发邮箱验证码前的图形验证码开关（与登录/注册开关相互独立）
                'mail_code_captcha' => $this->mailCodeCaptchaRequired(),
                // 前端「注册/登录页是否展示图形验证码输入框」的最终判据：
                //   登录提交需要 captcha_enabled；
                //   注册提交仅在没有邮箱验证码时才需要（有邮箱验证时会被跳过，见 register()）；
                //   发邮箱验证码需要 mail_code_captcha。
                // 三者任一为真，注册页就得展示图框，否则会出现「服务端要、页面不给填」的死路。
                'register_captcha_needed' => $captcha || $this->mailCodeCaptchaRequired(),
                // 邮箱验证开关：仅在 SMTP 配置可用时才要求验证，避免把注册卡死
                'mail_verify'     => $mailVerify,
                'mail_ready'      => $this->mailService->ready(),
                // 注册邮箱域名白名单（前端据此做输入提示 / 预校验）。
                // 空串 = 不限域名；非空 = 只有这些域可注册。前端只做提示，
                // **真正的拦截在服务端**（前端提示可被绕过，服务端是唯一闸门）。
                'register_email_domains' => $this->emailDomainRule(),
                // 密码安全能力开关（前端据此决定是否展示入口 / 是否要求验证码）。
                // 两者都要求 SMTP 就绪 —— 没有邮件通道时这两个开关无意义，
                // 若不联动，用户会看到一个点进去必然失败的入口。
                'password_change_verify'  => $this->passwordChangeVerifyRequired(),
                'password_reset_enabled'  => $this->passwordResetEnabled(),
                'app_name'        => (string) $this->gatewayRepository->get('app_name', config('app.name', 'AI 聚合网关')),
            ],
        ]);
    }

    /** POST /api/user/register */
    public function register(Request $request): Response
    {
        if (!(bool) $this->gatewayRepository->get('allow_register', true)) {
            return $this->fail('管理员已关闭开放注册', 403);
        }

        // IP 维度突发频控 —— 上线当天防「脚本无脑刷注册」的第一道闸。
        //
        // 为什么必须有这一层：注册是**对外、免登录、写库**的接口，而下面的人机闸门
        // （图形验证码 / 邮箱验证码）在管理端被关掉、或 SMTP 未配好（mailVerifyRequired()
        // 会自动放行）时，注册接口就是完全裸奔的。频控不依赖任何开关，恒定生效。
        //
        // 放在验证码之前：被频控时直接 429，不消耗用户已取到的图形验证码挑战。
        // CLI / 取不到 IP 时自动放行（见 PublicGuard::hit），自检脚本不受影响。
        $rateIp = ClientIp::resolve($request);
        $rate = $this->publicGuard->hit('register', $rateIp);
        if (!$rate['allowed']) {
            Audit::fail('register.fail', 429, [
                'actor_type' => 'guest',
                'summary'    => '注册被拒（IP 频控）',
                'detail'     => ['reason' => 'ip_rate_limit', 'retry_after' => $rate['retry_after'], 'ip' => $rateIp],
            ]);
            return $this->fail('操作过于频繁，请 ' . max(1, $rate['retry_after']) . ' 秒后再试', 429);
        }

        // 先做「便宜且必然拒绝」的纯字段校验，再做图形验证码。
        //
        // 🔴 为什么顺序必须是「域名/格式 → 验证码」而不是反过来：
        //    Captcha::verify() 是**一次性消费**（校验即销毁）。若把域名白名单放在
        //    验证码之后，用户填了非白名单邮箱时会：
        //      答对验证码 → 才被告知域名不支持 → 验证码已被销毁 → 必须重新取图再答一次。
        //    这是纯粹的 UX 损伤，且与项目里已经踩过的「一次性消费死结」同构。
        //    与下面 IP 频控「放在验证码之前，不消耗用户已取到的挑战」是同一个原则。
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $nickname = trim((string) $request->input('nickname', ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('请输入正确的邮箱地址');
        }
        // 注册邮箱域名白名单（默认只放 QQ 邮箱；后台留空 = 不限制）。
        // 放在查重之前：不合法域名无需再查库，也不泄露「该邮箱是否已注册」。
        $domainRule = $this->emailDomainRule();
        if (!MailDomain::allows($email, $domainRule)) {
            Audit::fail('register.fail', 400, [
                'actor_type' => 'guest',
                'summary'    => '注册被拒（邮箱域名不在白名单）',
                'detail'     => ['email' => $email, 'domain' => MailDomain::domainOf($email), 'rules' => $domainRule],
            ]);
            return $this->fail(MailDomain::message($domainRule));
        }

        // 图形验证码 —— 仅在「没有邮箱验证码这一关」时才作为注册的主要人机闸门。
        //
        // 为什么加了 !mailVerifyRequired()：
        //   Captcha::verify() 是**一次性消费**（校验即销毁）。开了邮箱验证时，
        //   用户点「获取验证码」已经消耗掉一次图形验证码，提交注册时再要求图形验证码
        //   就必然报「验证码已过期」—— 这是个必然踩的 UX 死结。
        //   而邮箱验证码本身已是更强的人机 + 成本门槛（要拿到邮件才行），
        //   此时图形验证码没有额外安全收益，故只在「无邮箱验证」时才作为注册闸门。
        $captchaNeeded = $this->captchaRequired() && !$this->mailVerifyRequired();
        if ($captchaNeeded) {
            $captchaError = $this->captchaGate->check($request);
            if ($captchaError !== null) {
                Audit::fail('register.fail', 400, [
                    'actor_type' => 'guest',
                    'summary'    => '注册被拒（图形验证码错误）',
                    'detail'     => ['reason' => 'captcha', 'message' => $captchaError],
                ]);
                return $this->fail($captchaError);
            }
        }

        if (strlen($password) < self::PASSWORD_MIN) {
            return $this->fail('密码至少 ' . self::PASSWORD_MIN . ' 位');
        }
        if (mb_strlen($nickname) > 32) {
            return $this->fail('昵称过长');
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            // 统一提示，降低账号枚举风险
            return $this->fail('该邮箱已被注册', 409);
        }

        // 邮箱验证码（管理端开启且 SMTP 可用时才强制校验）
        $emailCode = trim((string) $request->input('email_code', ''));
        if ($this->mailVerifyRequired()) {
            if ($emailCode === '') {
                return $this->fail('请输入邮箱验证码');
            }
            $check = $this->codeRepository->verify($email, 'register', $emailCode);
            if (!$check['ok']) {
                Audit::fail('register.fail', 400, [
                    'actor_type' => 'guest',
                    'summary'    => '注册失败（邮箱验证码' . $check['reason'] . '）：' . $email,
                    'detail'     => ['email' => $email, 'reason' => $check['reason']],
                ]);
                return $this->fail($this->codeMessage($check['reason']));
            }
        }

        try {
            $user = $this->userRepository->create($email, $password, $nickname);
        } catch (\Throwable $e) {
            Logger::warn('用户注册失败', ['email' => $email, 'error' => $e->getMessage()]);
            // 邮箱唯一索引兜底：并发注册撞库时同样返回 409
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->fail('该邮箱已被注册', 409);
            }
            return $this->fail('注册失败，请稍后重试', 500);
        }

        UserAuthMiddleware::login($user);
        $this->userRepository->touchLogin((int) $user['id'], $this->ip($request));
        Logger::info('新用户注册', ['user_id' => (int) $user['id'], 'email' => $email, 'ip' => $this->ip($request)]);
        Audit::ok('register.success', [
            'user_id'     => (int) $user['id'],
            'actor_type'  => 'user',
            'actor_id'    => (int) $user['id'],
            'actor_name'  => $nickname !== '' ? $nickname : $email,
            'target_type' => 'user',
            'target_id'   => (string) $user['id'],
            'summary'     => '注册并登录：' . ($nickname !== '' ? $nickname . '（' . $email . '）' : $email),
            'detail'      => ['email' => $email, 'nickname' => $nickname],
        ]);

        return Response::json(['ok' => true, 'data' => $this->publicInfo($user)]);
    }

    /** POST /api/user/login */
    public function login(Request $request): Response
    {
        // 兼容两种字段名：username（历史）/ email（邮箱注册）
        $identifier = trim((string) $request->input('username', ''));
        if ($identifier === '') {
            $identifier = trim((string) $request->input('email', ''));
        }
        $password = (string) $request->input('password', '');

        if ($identifier === '' || $password === '') {
            return $this->fail('请输入邮箱与密码');
        }

        $ip = $this->ip($request);

        // 持久化防爆破：IP + 账号双维度，清 cookie 换会话也无法绕过
        $locked = $this->loginGuard->lockedSeconds('user', $ip, $identifier);
        if ($locked > 0) {
            Audit::fail('login.locked', 429, [
                'actor_type' => 'guest',
                'summary'    => '登录被拒（尝试过于频繁）：' . mb_substr($identifier, 0, 64),
                'detail'     => ['identifier' => mb_substr($identifier, 0, 128), 'retry_after' => $locked],
            ]);
            return $this->fail('尝试过于频繁，请 ' . $locked . ' 秒后再试', 429);
        }

        // 图形验证码（管理端可在设置中关闭）
        if ($this->captchaRequired()) {
            $captchaError = $this->captchaGate->check($request);
            if ($captchaError !== null) {
                Audit::fail('login.fail', 400, [
                    'actor_type' => 'guest',
                    'summary'    => '登录失败（验证码错误）：' . mb_substr($identifier, 0, 64),
                    'detail'     => ['identifier' => mb_substr($identifier, 0, 128), 'reason' => 'captcha'],
                ]);
                return $this->fail($captchaError);
            }
        }

        $user = $this->userRepository->attempt($identifier, $password);
        if ($user === null) {
            $this->loginGuard->recordFailure('user', $ip, $identifier, $this->maxAttempts(), $this->lockSeconds());

            Logger::warn('用户登录失败', ['identifier' => $identifier, 'ip' => $ip]);
            Audit::fail('login.fail', 401, [
                'actor_type' => 'guest',
                'summary'    => '登录失败（邮箱或密码错误）：' . mb_substr($identifier, 0, 64),
                'detail'     => ['identifier' => mb_substr($identifier, 0, 128), 'reason' => 'bad_credential'],
            ]);
            return $this->fail('邮箱或密码错误', 401);
        }

        $uid = (int) $user['id'];
        $this->loginGuard->clear('user', $ip, $identifier);
        UserAuthMiddleware::login($user);
        $this->userRepository->touchLogin($uid, $ip);

        Audit::ok('login.success', [
            'user_id'    => $uid,
            'actor_type' => 'user',
            'actor_id'   => $uid,
            'actor_name' => (string) ($user['nickname'] ?? '') !== '' ? (string) $user['nickname'] : (string) $user['username'],
            'summary'    => '登录成功：' . ((string) ($user['nickname'] ?? '') !== '' ? $user['nickname'] : $user['username']),
        ]);

        return Response::json(['ok' => true, 'data' => $this->publicInfo($user)]);
    }

    /** POST /api/user/logout */
    public function logout(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $uid = (int) ($session['id'] ?? 0);
        $name = (string) ($session['nickname'] ?? '') !== '' ? (string) $session['nickname'] : (string) ($session['username'] ?? '');

        UserAuthMiddleware::logout();

        if ($uid > 0) {
            Audit::ok('logout', [
                'user_id'    => $uid,
                'actor_type' => 'user',
                'actor_id'   => $uid,
                'actor_name' => $name,
                'summary'    => '退出登录：' . ($name !== '' ? $name : ('用户 #' . $uid)),
            ]);
        }

        return Response::json(['ok' => true]);
    }

    /** GET /api/user/me */
    public function me(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $id = (int) ($session['id'] ?? 0);
        $user = $id > 0 ? $this->userRepository->find($id) : null;

        if ($user === null) {
            return $this->fail('账号不存在', 404);
        }

        return Response::json(['ok' => true, 'data' => $this->publicInfo($user)]);
    }

    /** POST /api/user/profile */
    public function updateProfile(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $id = (int) ($session['id'] ?? 0);
        $user = $id > 0 ? $this->userRepository->find($id) : null;
        if ($user === null) {
            return $this->fail('账号不存在', 404);
        }

        $nickname = trim((string) $request->input('nickname', (string) $user['nickname']));
        $email = trim((string) $request->input('email', (string) $user['email']));

        if (mb_strlen($nickname) > 32) {
            return $this->fail('昵称过长');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('请输入正确的邮箱地址');
        }
        // 邮箱域名白名单 —— 仅在**真的要换地址**时拦截。
        //
        // 为什么不无条件拦：白名单是「注册口子」的策略，而历史存量用户里可能有
        // 非白名单域名（改成只放 QQ 之前注册的）。他们只是来改昵称、邮箱原样提交，
        // 若无条件拦截，这批人连昵称都改不了 —— 把策略变更误伤成了功能故障。
        if (strcasecmp($email, (string) $user['email']) !== 0) {
            $domainRule = $this->emailDomainRule();
            if (!MailDomain::allows($email, $domainRule)) {
                Audit::fail('profile.update', 400, [
                    'user_id'    => $id,
                    'actor_type' => 'user',
                    'actor_id'   => $id,
                    'summary'    => '改绑邮箱被拒（域名不在白名单）',
                    'detail'     => ['to' => $email, 'domain' => MailDomain::domainOf($email), 'rules' => $domainRule],
                ]);
                return $this->fail(MailDomain::message($domainRule));
            }
        }
        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null && (int) ($existing['id'] ?? 0) !== $id) {
            return $this->fail('该邮箱已被其他账号使用', 409);
        }

        try {
            $this->userRepository->updateProfile($id, $nickname, $email);
        } catch (\Throwable $e) {
            Logger::warn('用户资料更新失败', ['user_id' => $id, 'error' => $e->getMessage()]);
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->fail('该邮箱已被其他账号使用', 409);
            }
            return $this->fail('资料更新失败，请稍后重试', 500);
        }

        UserAuthMiddleware::bootSession();
        if (isset($_SESSION['aigw_user'])) {
            $_SESSION['aigw_user']['nickname'] = $nickname;
        }

        Audit::ok('profile.update', [
            'user_id'    => $id,
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'    => '更新个人资料（昵称 / 邮箱）',
            'detail'     => [
                'nickname' => ['from' => (string) $user['nickname'], 'to' => $nickname],
                'email'    => ['from' => (string) $user['email'], 'to' => $email],
            ],
        ]);

        return Response::json(['ok' => true, 'message' => '资料已更新']);
    }

    /**
     * POST /api/user/password —— 已登录态修改密码（需邮箱验证码）
     *
     * 三重校验，缺一不可：① 当前密码正确；② 邮箱验证码正确；③ 新密码合规。
     *
     * 为什么拿到会话还不够、还要再收一次邮件验证码：
     *   会话 cookie 是**静态凭据**，一旦被窃（XSS 偷走、共用设备忘记退出、恶意插件）
     *   攻击者即可直接改密并永久接管账号 —— 而原主人连「自己怎么丢的号」都看不到。
     *   邮箱验证码把门槛从「持有 cookie」抬到「持有 cookie + 能收信」，
     *   且**改密成功会立刻发一封通知**，让账号异常变更变得可见。
     */
    public function changePassword(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $id = (int) ($session['id'] ?? 0);
        $user = $id > 0 ? $this->userRepository->find($id) : null;
        if ($user === null) {
            return $this->fail('账号不存在', 404);
        }

        $current = (string) $request->input('current_password', '');
        $next = (string) $request->input('new_password', '');

        if (strlen($next) < self::PASSWORD_MIN) {
            return $this->fail('新密码至少 ' . self::PASSWORD_MIN . ' 位');
        }
        if (!password_verify($current, (string) $user['password'])) {
            Audit::fail('password.change', 401, [
                'user_id'    => $id,
                'target_type' => 'user',
                'target_id'   => (string) $id,
                'summary'    => '修改密码失败（当前密码不正确）',
            ]);
            return $this->fail('当前密码不正确', 401);
        }

        // 邮箱验证码 —— 收件地址取**账号当前绑定的邮箱**，与发码接口的口径一致。
        //
        // ⚠️ 不接受前端传的邮箱：发码侧已把地址锁死为会话邮箱，
        //    若校验侧接受任意地址，就会出现「发到 A 邮箱的码用来给 B 账号改密」的错位。
        $email = EmailCodeRepository::normalize((string) $user['email']);
        if ($this->passwordChangeVerifyRequired()) {
            if ($email === '') {
                // 账号没绑邮箱 → 无法完成验证。此时**明确报错**而不是静默放行：
                // 静默放行等于「没邮箱的账号免验证」，恰恰是最需要保护的那批账号。
                Audit::fail('password.change', 400, [
                    'user_id'    => $id,
                    'target_type' => 'user',
                    'target_id'   => (string) $id,
                    'summary'    => '修改密码被拒（账号未绑定邮箱，无法完成邮箱验证）',
                ]);
                return $this->fail('账号未绑定邮箱，无法完成验证，请联系管理员');
            }

            $emailCode = trim((string) $request->input('email_code', ''));
            if ($emailCode === '') {
                return $this->fail('请输入邮箱验证码');
            }
            $check = $this->codeRepository->verify($email, 'change', $emailCode);
            if (!$check['ok']) {
                Audit::fail('password.change', 400, [
                    'user_id'    => $id,
                    'target_type' => 'user',
                    'target_id'   => (string) $id,
                    'summary'    => '修改密码失败（邮箱验证码' . $check['reason'] . '）',
                    'detail'     => ['reason' => $check['reason']],
                ]);
                return $this->fail($this->codeMessage($check['reason']));
            }
        }

        // 改密码 + 抬会话纪元：别处的旧会话立即失效（见 UserAuthMiddleware 的纪元校验）
        $epoch = $this->userRepository->changePasswordAndBumpEpoch($id, $next);
        UserAuthMiddleware::refreshEpoch($epoch);
        Logger::info('用户密码已修改', ['user_id' => $id]);

        Audit::ok('password.change', [
            'user_id'    => $id,
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'    => '修改登录密码' . ($this->passwordChangeVerifyRequired() ? '（邮箱验证通过）' : ''),
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '密码已更新，其他设备的登录状态已失效',
        ]);
    }

    /**
     * POST /api/user/reset-password —— 未登录态找回密码（邮箱验证码）
     *
     * body: { email, email_code, new_password }
     *
     * 与「改密码」的区别：这里没有会话可依，账号身份**完全由邮箱验证码证明** ——
     * 能收到发往该邮箱的六位码，即视为拥有该邮箱的控制权。因此本接口的
     * 全部安全性都压在「验证码是一次性、有时效、有错误次数上限」这几条上，
     * 它们由 EmailCodeRepository 在 SQL 条件里保证（不靠应用层判断）。
     *
     * 设计取舍：
     *   · 邮箱不存在 → 与「验证码错误」返回**同一条消息**（防账号枚举）。
     *     这里不做「静默成功」是因为它确实无法成功（要改的账号不存在），
     *     统一措辞即可，不会泄露存在性。
     *   · 不自动登录：重置完跳回登录页，让用户用新密码走一遍正常登录。
     *     既省掉「重置接口附带发会话」这一层越权面，也让用户确认新密码可用。
     */
    public function resetPassword(Request $request): Response
    {
        if (!$this->passwordResetEnabled()) {
            return $this->fail('站点未开启找回密码功能，请联系管理员', 403);
        }

        $email = EmailCodeRepository::normalize((string) $request->input('email', ''));
        $code = trim((string) $request->input('email_code', ''));
        $next = (string) $request->input('new_password', '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('请输入正确的邮箱地址');
        }
        if ($code === '') {
            return $this->fail('请输入邮箱验证码');
        }
        if (strlen($next) < self::PASSWORD_MIN) {
            return $this->fail('新密码至少 ' . self::PASSWORD_MIN . ' 位');
        }

        // IP + 邮箱双维度防爆破。
        //
        // 为什么这里也要限速：验证码本身有 5 次错误上限（单码层面），但攻击者
        // 可以「反复请求新码 + 每次猜 5 下」把总尝试次数放大 —— 单码限制挡不住
        // 这种跨码的穷举。LoginGuard 是持久化计数（清 cookie 无效），
        // 与登录口共用同一套策略，运维只需要理解一个概念。
        $ip = $this->ip($request);
        $locked = $this->loginGuard->lockedSeconds('reset', $ip, $email);
        if ($locked > 0) {
            Audit::fail('password.reset', 429, [
                'actor_type' => 'guest',
                'summary'    => '找回密码被拒（尝试过于频繁）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'retry_after' => $locked],
            ]);
            return $this->fail('尝试过于频繁，请 ' . $locked . ' 秒后再试', 429);
        }

        $user = $this->userRepository->findByEmail($email);
        $check = $this->codeRepository->verify($email, 'reset', $code);

        // 账号不存在与验证码错误**返回同一句话**，避免把本接口变成账号枚举器。
        if ($user === null || !$check['ok']) {
            if ($user !== null) {
                $this->loginGuard->recordFailure('reset', $ip, $email, $this->maxAttempts(), $this->lockSeconds());
            }
            Audit::fail('password.reset', 400, [
                'actor_type' => 'guest',
                'summary'    => '找回密码失败（' . ($user === null ? '邮箱未注册' : '验证码' . $check['reason']) . '）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email, 'reason' => $user === null ? 'no_user' : $check['reason']],
            ]);
            return $this->fail('邮箱验证码不正确或已过期');
        }

        // 账号被禁用时不放行重置：否则等于给已封禁账号一条自助复活路径
        if ((string) ($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            Audit::fail('password.reset', 403, [
                'actor_type' => 'guest',
                'summary'    => '找回密码被拒（账号已禁用）：' . mb_substr($email, 0, 64),
                'detail'     => ['email' => $email],
            ]);
            return $this->fail('账号已被禁用，请联系管理员', 403);
        }

        $uid = (int) $user['id'];
        $this->loginGuard->clear('reset', $ip, $email);
        // 抬会话纪元：找回密码同样意味着「凭据可能已泄露」，所有旧会话一并失效
        $this->userRepository->changePasswordAndBumpEpoch($uid, $next);
        Logger::info('用户通过邮箱找回密码', ['user_id' => $uid, 'email' => $email, 'ip' => $ip]);

        Audit::ok('password.reset', [
            'user_id'     => $uid,
            'actor_type'  => 'guest',
            'target_type' => 'user',
            'target_id'   => (string) $uid,
            'summary'     => '邮箱找回密码并重置：' . $email,
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '密码已重置，请使用新密码登录',
        ]);
    }

    /** POST /api/user/api-key/regenerate */
    public function regenerateApiKey(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $id = (int) ($session['id'] ?? 0);
        if ($id <= 0 || $this->userRepository->find($id) === null) {
            return $this->fail('账号不存在', 404);
        }

        $key = $this->userRepository->regenerateApiKey($id);
        Logger::info('用户 API Key 已重置', ['user_id' => $id]);

        Audit::ok('apikey.regenerate', [
            'user_id'    => $id,
            'target_type' => 'user',
            'target_id'   => (string) $id,
            'summary'    => '重置本人 API Key',
            'detail'     => ['key_head' => substr($key, 0, 8)],
        ]);

        return Response::json(['ok' => true, 'data' => ['api_key' => $key]]);
    }

    /**
     * 对外安全字段：不包含 password
     */
    private function publicInfo(array $user): array
    {
        return [
            'id'            => (int) ($user['id'] ?? 0),
            'username'      => (string) ($user['username'] ?? ''),
            'nickname'      => (string) ($user['nickname'] ?? ''),
            'email'         => (string) ($user['email'] ?? ''),
            'api_key'       => (string) ($user['api_key'] ?? ''),
            'status'        => (string) ($user['status'] ?? 'ACTIVE'),
            'last_login_at' => $user['last_login_at'] ?? null,
            'last_login_ip' => (string) ($user['last_login_ip'] ?? ''),
        ];
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }

    private function captchaRequired(): bool
    {
        // CLI（自检脚本 / 计划任务）没有浏览器会话，验证码无从输入，直接放行
        if (PHP_SAPI === 'cli') {
            return false;
        }

        return (bool) $this->gatewayRepository->get('captcha_enabled', true);
    }

    /**
     * 注册是否必须邮箱验证：开关打开 **且** SMTP 配置可用。
     * 只开开关却没配 SMTP 时自动放行，避免把注册入口彻底锁死。
     */
    private function mailVerifyRequired(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return (bool) $this->gatewayRepository->get('mail_verify_required', false)
            && $this->mailService->ready();
    }

    /** 发邮箱验证码前是否要图形验证码（与登录/注册开关独立） */
    private function mailCodeCaptchaRequired(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return (bool) $this->gatewayRepository->get('mail_code_captcha', true);
    }

    /**
     * 修改密码是否需要邮箱验证码。
     *
     * ⚠️ 判定逻辑**全部委托 PasswordPolicy（单一真源）**，本方法只做转接。
     *    后台设置页回显走的是同一个 PasswordPolicy —— 两处共用一份判定，
     *    才不会出现「后台显示开着、用户端实际不要求」的静默漂移。
     *
     * 语义：开关打开 **且** SMTP 可用。没配 SMTP 时验证码根本发不出去，
     * 若仍强制校验，用户会卡在「要验证码 → 点发送 → 报错 → 永远改不了密码」
     * 的死循环里。因此退回「仅校验当前密码」（与改造前行为一致），
     * 但这个退化状态会在后台设置页以告警形式显式告知管理员。
     */
    private function passwordChangeVerifyRequired(): bool
    {
        return $this->passwordPolicy->changeVerifyEffective();
    }

    /**
     * 是否开放「找回密码」：开关打开 **且** SMTP 可用（同上，避免死路）。
     * 同样委托 PasswordPolicy（单一真源）。
     */
    private function passwordResetEnabled(): bool
    {
        return $this->passwordPolicy->resetEffective();
    }

    /** 邮箱验证码失败原因 → 用户可读文案（发码/改密/重置三处共用同一份措辞） */
    private function codeMessage(string $reason): string
    {
        return match ($reason) {
            'expired'  => '验证码已过期，请重新获取',
            'too_many' => '验证码错误次数过多，请重新获取',
            'mismatch' => '邮箱验证码不正确',
            default    => '邮箱验证码无效',
        };
    }

    /**
     * 注册/改绑邮箱的域名白名单（逗号分隔；空串 = 不限制）。
     *
     * ⚠️ CLI 不做豁免：白名单是业务策略不是人机闸门，测试脚本也必须走真实策略
     *    （否则「测试全绿、线上全拦」）。测试要用别的域名时，自行把配置临时改掉。
     */
    private function emailDomainRule(): string
    {
        return (string) $this->gatewayRepository->get('register_email_domains', '');
    }

    private function maxAttempts(): int
    {
        return max(1, (int) $this->gatewayRepository->get('login_max_attempts', 5));
    }

    private function lockSeconds(): int
    {
        return max(1, (int) $this->gatewayRepository->get('login_lock_seconds', 900));
    }

    /**
     * 客户端真实 IP。
     *
     * 🔴 2026-10-02：这里原先直读 `$_SERVER['REMOTE_ADDR']`。
     *    走 EdgeOne CDN 后 **REMOTE_ADDR 是 EO 回源节点 IP**，
     *    于是「登录失败锁定」把**全部访客记在同一个桶**里 ——
     *    一个人连错 5 次，所有人被锁；正常人也会被自己的邻居连累。
     *    必须统一走 ClientIp（回源令牌 → EO-Connecting-IP）。
     */
    private function ip(?Request $request = null): string
    {
        return ClientIp::resolve($request ?? new Request());
    }
}
