<?php
/**
 * 自研 SMTP 发信器（无第三方依赖）
 * ------------------------------------------------------------
 * 设计要点：
 *   1. **端口不限**：默认允许 25 / 80 / 465 / 587 / 2525 等任意端口。
 *      80 端口常见于「运营商封 25 段」的自建邮局，或把 SMTP 挂在 80/8080
 *      反向代理后端的场景；本类不做端口白名单，只按连接方式处理。
 *   2. **加密三态**：
 *        - none ：明文直连（80 端口通常是这种；连接后仍可 STARTTLS 升级）
 *        - ssl  ：连接即 TLS（SMTPS，一般 465）
 *        - tls  ：明文连接后 STARTTLS 升级（一般 587 / 25）
 *      另外提供 auto：按端口猜（465→ssl，其余→tls），并在 STARTTLS 不被
 *      支持时自动退化为明文，避免 80 端口发信直接失败。
 *   3. **认证方式**：AUTH LOGIN / AUTH PLAIN，二者都失败时按配置允许匿名投递。
 *   4. **超时与错误**：连接/读写分别超时，任何一步失败都把上游原始回包
 *      带回来（`lastError()`），便于后台「测试发信」直接显示原因。
 *   5. 支持 HTML / 纯文本、多收件人、抄送、附件（base64）。
 */

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class Mailer
{
    /** 允许的加密方式 */
    private const ENCRYPTIONS = ['none', 'ssl', 'tls', 'auto'];

    private string $host = '';
    private int $port = 25;
    private string $encryption = 'auto';
    private string $username = '';
    private string $password = '';
    private string $fromEmail = '';
    private string $fromName = '';
    private int $connectTimeout = 10;
    private int $readTimeout = 20;
    private bool $allowInsecureFallback = true;

    /** @var resource|null */
    private $socket = null;

    private string $lastError = '';

    /** 最近一次会话日志（逐行原始回包，供测试发信回显） */
    private array $transcript = [];

    /**
     * 全量配置（一般直接喂网关设置数组）
     */
    public function configure(array $config): self
    {
        $this->host = trim((string) ($config['mail_host'] ?? ''));
        $this->port = (int) ($config['mail_port'] ?? 25);
        $enc = strtolower(trim((string) ($config['mail_encryption'] ?? 'auto')));
        $this->encryption = in_array($enc, self::ENCRYPTIONS, true) ? $enc : 'auto';
        $this->username = trim((string) ($config['mail_username'] ?? ''));
        // 密码可能被加密存储，交由调用方解密后传入；这里只做原样接收
        $this->password = (string) ($config['mail_password'] ?? '');
        $this->fromEmail = trim((string) ($config['mail_from_email'] ?? ''));
        $this->fromName = trim((string) ($config['mail_from_name'] ?? ''));
        $this->connectTimeout = max(1, (int) ($config['mail_connect_timeout'] ?? 10));
        $this->readTimeout = max(1, (int) ($config['mail_read_timeout'] ?? 20));
        $this->allowInsecureFallback = (bool) ($config['mail_allow_insecure'] ?? true);

        if ($this->port <= 0) {
            $this->port = 25;
        }

        return $this;
    }

    /** 最近一次错误（含上游原始回包） */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /** 最近一次 SMTP 会话日志 */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * 端口 → 加密方式的推断（encryption = auto 时生效）
     * 465 是隐式 TLS；80 / 8080 等非常规端口优先明文 + STARTTLS。
     */
    public static function guessEncryption(int $port): string
    {
        if ($port === 465) {
            return 'ssl';
        }
        if ($port === 80 || $port === 8080 || $port === 25) {
            return 'tls';
        }
        return 'tls';
    }

    /**
     * 连接并握手（含 EHLO / STARTTLS / AUTH）
     *
     * @return bool 成功否；失败时用 lastError() 取原因
     */
    public function connect(): bool
    {
        $this->lastError = '';
        $this->transcript = [];

        if ($this->host === '') {
            $this->lastError = '未配置 SMTP 服务器地址（mail_host）';
            return false;
        }

        $enc = $this->encryption === 'auto'
            ? self::guessEncryption($this->port)
            : $this->encryption;

        // 1) 建连。ssl 用 ssl:// 直连；none / tls 先明文
        $transport = $enc === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $this->log('CONNECT ' . $transport . $this->host . ':' . $this->port . ' (' . $enc . ')');

        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if ($socket === false) {
            $this->lastError = sprintf(
                '无法连接 %s:%d（%s）— %s',
                $this->host,
                $this->port,
                $enc,
                $errstr !== '' ? $errstr : ('errno=' . $errno)
            );
            return false;
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->readTimeout);

        // 2) 读 banner
        $banner = $this->readLine();
        if ($banner === null || $banner[0] !== '2') {
            $this->fail('SMTP 服务端未正常问候：' . ($banner ?? '无响应'));
            return false;
        }

        // 3) EHLO（失败退 HELO）
        $ehlo = $this->command('EHLO ' . $this->ehloName());
        if ($ehlo === null || $ehlo[0] !== '2') {
            $helo = $this->command('HELO ' . $this->ehloName());
            if ($helo === null || $helo[0] !== '2') {
                $this->fail('EHLO/HELO 被拒绝：' . ($ehlo ?? $helo ?? '无响应'));
                return false;
            }
        }
        $capabilities = $ehlo ?? '';

        // 4) STARTTLS 升级（tls 模式）
        if ($enc === 'tls') {
            if (stripos($capabilities, 'STARTTLS') !== false) {
                $res = $this->command('STARTTLS');
                if ($res === null || $res[0] !== '2') {
                    if ($this->allowInsecureFallback) {
                        $this->log('STARTTLS 未被接受，按明文继续（allow_insecure）');
                    } else {
                        $this->fail('STARTTLS 失败：' . ($res ?? '无响应'));
                        return false;
                    }
                } else {
                    $ok = @stream_socket_enable_crypto(
                        $this->socket,
                        true,
                        STREAM_CRYPTO_METHOD_TLS_CLIENT
                            | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                            | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    );
                    if ($ok !== true) {
                        if ($this->allowInsecureFallback) {
                            $this->log('TLS 协商失败，按明文继续（allow_insecure）');
                        } else {
                            $this->fail('TLS 加密协商失败');
                            return false;
                        }
                    } else {
                        $this->log('TLS 已建立');
                        // 加密后需要重新 EHLO
                        $again = $this->command('EHLO ' . $this->ehloName());
                        if ($again !== null && $again[0] === '2') {
                            $capabilities = $again;
                        }
                    }
                }
            } else {
                if (!$this->allowInsecureFallback) {
                    $this->fail('服务端不支持 STARTTLS，且已禁止明文投递');
                    return false;
                }
                $this->log('服务端不支持 STARTTLS，按明文继续');
            }
        }

        // 5) 认证（配置了账号才认证）
        if ($this->username !== '') {
            // 口令为空时必须**立刻停**，不能把空串发出去。两个理由：
            //   ① 归因会被带偏：阿里云 DirectMail 收到空凭据回
            //      `530 Username or passwd is NULL`，兜底的 AUTH LOGIN 又回
            //      `535 Authentication failure` —— 两条叠起来会被读成「密码错了」，
            //      而真因是「口令压根没存进来」，管理员改一百次口令也没用。
            //   ② 反复用空凭据打服务端会累积失败计数，有触发风控锁号的风险。
            if ($this->password === '') {
                $this->fail(
                    'SMTP 口令为空：用户名（' . $this->username . '）已配置，但口令没有保存成功。'
                    . '请到后台「设置 → 邮件」重新填写 SMTP 密码并确认保存'
                );
                return false;
            }
            if (!$this->authenticate($capabilities)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 发送一封邮件（内部自动连接 → MAIL FROM → RCPT TO → DATA → QUIT）
     *
     * @param array{
     *   to: string|array, subject: string, html?: string, text?: string,
     *   cc?: string|array, bcc?: string|array, reply_to?: string,
     *   attachments?: array<int, array{name:string, data:string, mime?:string}>
     * } $mail
     */
    public function send(array $mail): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $to = $this->normalizeAddresses($mail['to'] ?? []);
        $cc = $this->normalizeAddresses($mail['cc'] ?? []);
        $bcc = $this->normalizeAddresses($mail['bcc'] ?? []);

        if (!$to) {
            $this->close();
            $this->lastError = '收件人不能为空';
            return false;
        }

        $from = $this->fromEmail !== '' ? $this->fromEmail : $this->username;
        if ($from === '') {
            $this->close();
            $this->lastError = '未配置发件邮箱（mail_from_email）';
            return false;
        }
        // CWE-93：信封地址同样会进入 SMTP 命令，剥除 CR/LF 防命令注入
        $from = $this->cleanHeader($from);

        // MAIL FROM
        $res = $this->command('MAIL FROM:<' . $from . '>');
        if ($res === null || $res[0] !== '2') {
            $this->fail('MAIL FROM 被拒绝：' . ($res ?? '无响应'));
            $this->close();
            return false;
        }

        // RCPT TO（to + cc + bcc 全部作为信封收件人）
        foreach (array_merge($to, $cc, $bcc) as $rcpt) {
            $rcpt = $this->cleanHeader($rcpt);
            $res = $this->command('RCPT TO:<' . $rcpt . '>');
            if ($res === null || !in_array($res[0], ['2', '3'], true)) {
                $this->fail('RCPT TO:<' . $rcpt . '> 被拒绝：' . ($res ?? '无响应'));
                $this->close();
                return false;
            }
        }

        // DATA
        $res = $this->command('DATA');
        if ($res === null || $res[0] !== '3') {
            $this->fail('DATA 被拒绝：' . ($res ?? '无响应'));
            $this->close();
            return false;
        }

        $body = $this->buildMessage($from, $to, $cc, $mail, $from);
        if (!$this->write($body)) {
            $this->close();
            return false;
        }
        $this->log('>> [DATA 报文 ' . strlen($body) . ' 字节，已隐藏]');

        $res = $this->readLine();
        if ($res === null || $res[0] !== '2') {
            $this->fail('邮件正文未被接受：' . ($res ?? '无响应'));
            $this->close();
            return false;
        }

        $this->command('QUIT');
        $this->close();

        return true;
    }

    /** 仅做连接与认证握手，用于后台「测试连接」 */
    public function testConnection(): bool
    {
        $ok = $this->connect();
        if ($ok) {
            $this->command('QUIT');
        }
        $this->close();
        return $ok;
    }

    /* ── 内部实现 ────────────────────────────────────────── */

    private function authenticate(string $capabilities): bool
    {
        $capUpper = strtoupper($capabilities);

        // AUTH PLAIN 优先（一次往返，兼容性最好）
        if (strpos($capUpper, 'AUTH') !== false && strpos($capUpper, 'PLAIN') !== false) {
            $token = base64_encode("\0" . $this->username . "\0" . $this->password);
            $res = $this->command('AUTH PLAIN ' . $token);
            if ($res !== null && $res[0] === '2') {
                $this->log('AUTH PLAIN 成功');
                return true;
            }
            // `530 ... Username or passwd is NULL` 与 `535 Authentication failure`
            // 是**两类完全不同的故障**，绝不能都读成「密码错」：
            //   530 NULL → 服务端解 base64 后发现用户名或口令是**空串**（配置丢失）
            //   535      → 两个字段都非空，只是不匹配（账号/口令本身有问题）
            // 判错方向会让人去反复改口令，而真正要修的是「口令没保存进去」。
            if ($this->isNullCredential($res)) {
                $this->fail(
                    'SMTP 用户名或口令为空串（服务端原话：' . $res . '）'
                    . ' —— 请到后台「设置 → 邮件」重新填写 SMTP 口令并确认保存'
                );
                return false;
            }
            $this->log('AUTH PLAIN 失败，尝试 LOGIN');
        }

        // AUTH LOGIN
        $res = $this->command('AUTH LOGIN');
        if ($res !== null && $res[0] === '3') {
            $u = $this->command(base64_encode($this->username));
            if ($u !== null && $u[0] === '3') {
                $p = $this->command(base64_encode($this->password));
                if ($p !== null && $p[0] === '2') {
                    $this->log('AUTH LOGIN 成功');
                    return true;
                }
                if ($this->isNullCredential($p)) {
                    $this->fail(
                        'SMTP 用户名或口令为空串（服务端原话：' . $p . '）'
                        . ' —— 请到后台「设置 → 邮件」重新填写 SMTP 口令并确认保存'
                    );
                    return false;
                }
                $this->fail('SMTP 密码被拒绝：' . ($p ?? '无响应'));
                return false;
            }
            if ($this->isNullCredential($u)) {
                $this->fail(
                    'SMTP 用户名或口令为空串（服务端原话：' . $u . '）'
                    . ' —— 请到后台「设置 → 邮件」补全 SMTP 用户名（须为完整发信地址）与口令'
                );
                return false;
            }
            $this->fail('SMTP 用户名被拒绝：' . ($u ?? '无响应'));
            return false;
        }

        $this->fail('SMTP 认证失败（服务端未提供可用的 AUTH 方式）：' . ($res ?? '无响应'));
        return false;
    }

    /**
     * 服务端是否在说「解出来的用户名/口令是空串」。
     *
     * 与「凭据不匹配」严格区分：前者是配置丢失，后者是账号或口令本身的问题。
     * 阿里云 DirectMail 的原文形如 `530 Username or passwd is NULL [@sm050103]`。
     */
    private function isNullCredential(?string $res): bool
    {
        if ($res === null || $res === '') {
            return false;
        }
        return strpos($res, '530') === 0 && stripos($res, 'NULL') !== false;
    }

    /** 组一封 MIME 邮件（multipart/alternative，带附件时再包一层 mixed） */
    private function buildMessage(string $from, array $to, array $cc, array $mail, string $envelopeFrom): string
    {
        $subject = (string) ($mail['subject'] ?? '(无主题)');
        $html = (string) ($mail['html'] ?? '');
        $text = (string) ($mail['text'] ?? '');
        if ($text === '' && $html !== '') {
            $text = trim(strip_tags(preg_replace('#<(br|/p|/div|/tr)[^>]*>#i', "\n", $html)) ?? '');
        }
        if ($html === '') {
            $html = '<pre style="font:14px/1.6 -apple-system,Segoe UI,sans-serif">'
                . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }

        $boundaryAlt = 'alt_' . bin2hex(random_bytes(8));
        $boundaryMix = 'mix_' . bin2hex(random_bytes(8));
        $attachments = is_array($mail['attachments'] ?? null) ? $mail['attachments'] : [];

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->formatAddress($envelopeFrom, $this->fromName);
        $headers[] = 'To: ' . implode(', ', $to);
        if ($cc) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        $replyTo = trim((string) ($mail['reply_to'] ?? ''));
        if ($replyTo !== '') {
            // CWE-93：防邮件头注入，必须剥掉 CR/LF
            $headers[] = 'Reply-To: ' . $this->cleanHeader($replyTo);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->messageIdDomain() . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: AigwMailer';

        // 正文部分
        $body =
            '--' . $boundaryAlt . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: base64' . "\r\n\r\n" .
            chunk_split(base64_encode($text !== '' ? $text : $subject), 76, "\r\n") . "\r\n" .
            '--' . $boundaryAlt . "\r\n" .
            'Content-Type: text/html; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: base64' . "\r\n\r\n" .
            chunk_split(base64_encode($html), 76, "\r\n") . "\r\n" .
            '--' . $boundaryAlt . '--';

        if (!$attachments) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $payload = $body;
        } else {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMix . '"';
            $payload =
                '--' . $boundaryMix . "\r\n" .
                'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n" .
                $body . "\r\n";

            foreach ($attachments as $att) {
                $name = (string) ($att['name'] ?? 'attachment.bin');
                $mime = (string) ($att['mime'] ?? 'application/octet-stream');
                $payload .=
                    '--' . $boundaryMix . "\r\n" .
                    'Content-Type: ' . $mime . '; name="' . $this->encodeHeader($name) . '"' . "\r\n" .
                    'Content-Transfer-Encoding: base64' . "\r\n" .
                    'Content-Disposition: attachment; filename="' . $this->encodeHeader($name) . '"' . "\r\n\r\n" .
                    chunk_split(base64_encode((string) ($att['data'] ?? '')), 76, "\r\n") . "\r\n";
            }
            $payload .= '--' . $boundaryMix . '--';
        }

        // SMTP DATA 阶段的点转义（行首 "." → ".."）
        $lines = preg_split('/\r\n|\r|\n/', $payload) ?: [];
        $escaped = implode("\r\n", array_map(static function ($l) {
            return str_starts_with($l, '.') ? '.' . $l : $l;
        }, $lines));

        // 结束行必须是 CRLF + "." + CRLF，缺少末尾 CRLF 会让服务端一直等行结束
        return implode("\r\n", $headers) . "\r\n\r\n" . $escaped . "\r\n.\r\n";
    }

    private function formatAddress(string $email, string $name): string
    {
        // CWE-93：地址与显示名都可能来自配置 / 调用方，进头前统一剥除 CR/LF
        $email = $this->cleanHeader($email);
        if ($name === '') {
            return '<' . $email . '>';
        }
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    /**
     * 剥除邮件头注入载荷（CWE-93 / CWE-150）
     *
     * 邮件头以 CRLF 分行，若值里混入 `\r\n`，攻击者可凭空插入
     * 「Bcc: attacker@evil.com」「Content-Type: text/html」等额外头，
     * 造成邮件头注入 / 收件人劫持 / 正文类型篡改。
     * 这里把所有 CR / LF 直接删除，并顺带清掉其余不可打印控制字符。
     */
    private function cleanHeader(string $value): string
    {
        // 先删 CR/LF（含 CRLF、单独 CR、单独 LF），再删剩余 C0 控制字符（保留无害的 \t）
        $value = str_replace(["\r\n", "\r", "\n"], '', $value);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }

    /** RFC 2047 编码非 ASCII 头 */
    private function encodeHeader(string $text): string
    {
        // CWE-93：编码前先做注入清洗，保证所有调用方（主题 / 显示名 / 附件名）都受保护
        $text = $this->cleanHeader($text);
        if (preg_match('/[^\x20-\x7e]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }

    private function messageIdDomain(): string
    {
        $host = $this->fromEmail !== '' ? $this->fromEmail : $this->username;
        $at = strrpos($host, '@');
        $domain = $at !== false ? substr($host, $at + 1) : 'localhost';
        // CWE-93：域名进入 Message-ID 头，剥除 CR/LF 与非法字符
        $domain = $this->cleanHeader($domain);
        return $domain !== '' ? $domain : 'localhost';
    }

    private function ehloName(): string
    {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $host = 'localhost';
        }
        // CWE-93：EHLO 名进入 SMTP 命令，剥除 CR/LF
        return $this->cleanHeader($host);
    }

    /** @return array<int,string> */
    private function normalizeAddresses($input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[,;]/', $input) ?: [];
        }
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $addr) {
            $addr = trim((string) $addr);
            if ($addr === '') {
                continue;
            }
            // 允许 "名字 <a@b.c>" 形式，只取尖括号内的地址
            if (preg_match('/<([^>]+)>/', $addr, $m)) {
                $addr = trim($m[1]);
            }
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[] = $addr;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 发送一条命令并读取完整回包
     *
     * @return string|null 以状态码开头的一行（多行响应已合并为最后一行）
     */
    private function command(string $line): ?string
    {
        if (!$this->write($line . "\r\n")) {
            return null;
        }
        // 含凭据的行绝不进日志：AUTH 命令本身、以及 base64 用户名/口令行
        if (preg_match('/^AUTH\s/i', $line)) {
            $this->log('>> AUTH ***');
        } elseif (preg_match('#^[A-Z]+(\s|$)#', $line)) {
            $this->log('>> ' . $line);   // EHLO / MAIL FROM / RCPT TO / DATA / QUIT ...
        } else {
            $this->log('>> [已隐藏凭据]'); // AUTH LOGIN 后续的 base64 行
        }
        return $this->readLine();
    }

    /**
     * 读取一条完整 SMTP 响应
     *
     * 关键：SMTP 响应用 "250-XXX" 续行 + "250 YYY" 结束。这里把**全部行拼起来**
     * 返回，否则 EHLO 的能力列表（AUTH PLAIN / STARTTLS / SIZE…）会在折叠成
     * 最后一行时丢失，导致本该走 AUTH PLAIN 的服务器被迫降级。
     * 首字符仍是统一的状态码，调用方按 $res[0] 判断不受影响。
     *
     * @return string|null 多行以 \n 连接；无响应返回 null
     */
    private function readLine(): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $lines = [];
        $guard = 0;
        while (true) {
            if (++$guard > 200) {
                break;
            }
            $line = @fgets($this->socket, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    $this->lastError = 'SMTP 读取超时（' . $this->readTimeout . ' 秒）';
                }
                break;
            }
            $line = rtrim($line, "\r\n");
            $this->log('<< ' . $line);
            $lines[] = $line;

            // 第 4 个字符为空格，或行长度不足 4 → 响应结束
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        if (!$lines) {
            return null;
        }

        // 单行直接返回；多行用 \n 连接（状态码取首行字符）
        if (count($lines) === 1) {
            return $lines[0];
        }
        // 把续行的 "250-" 归一为 "250 "，便于调用方直接做子串匹配
        $normalized = [];
        foreach ($lines as $i => $l) {
            if ($i < count($lines) - 1 && strlen($l) >= 4 && $l[3] === '-') {
                $normalized[] = substr($l, 0, 3) . ' ' . substr($l, 4);
                continue;
            }
            $normalized[] = $l;
        }
        return implode("\n", $normalized);
    }

    private function write(string $data): bool
    {
        if (!is_resource($this->socket)) {
            $this->lastError = $this->lastError !== '' ? $this->lastError : 'SMTP 连接已断开';
            return false;
        }
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->lastError = 'SMTP 写入失败（连接可能被中断）';
                return false;
            }
            $written += $n;
        }
        return true;
    }

    private function fail(string $message): void
    {
        $this->lastError = $message;
        $this->log('!! ' . $message);
        Logger::warn('SMTP 发信失败', ['host' => $this->host, 'port' => $this->port, 'error' => $message]);
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function log(string $line): void
    {
        $this->transcript[] = $line;
        if (count($this->transcript) > 300) {
            array_shift($this->transcript);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
