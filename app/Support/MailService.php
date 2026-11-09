<?php
/**
 * 邮件服务：把「网关设置里的 SMTP 配置」变成一次真实发信
 * ------------------------------------------------------------
 * 职责：
 *   1. 从 GatewayRepository 读 SMTP 配置（端口不限，80 也能发）
 *   2. 口令用 Crypto 解密（存储为 AES-256-GCM 密文，明文不下发前端）
 *   3. 预置几种邮件模板：注册验证码 / 通用测试
 *   4. 统一回传 (ok, error, transcript)，供后台「测试发信」直接显示
 */

declare(strict_types=1);

namespace App\Support;

use App\Repositories\GatewayRepository;

class MailService
{
    public function __construct(private GatewayRepository $gatewayRepository) {}

    /** 邮件功能是否开启且配置完整 */
    public function ready(): bool
    {
        $s = $this->gatewayRepository->current();
        return (bool) ($s['mail_enabled'] ?? false)
            && trim((string) ($s['mail_host'] ?? '')) !== ''
            && (int) ($s['mail_port'] ?? 0) > 0;
    }

    /** 是否要求注册时邮箱验证 */
    public function verifyRequired(): bool
    {
        return (bool) $this->gatewayRepository->get('mail_verify_required', false);
    }

    /**
     * 构造一个配置好的 Mailer（口令已解密）
     */
    public function mailer(): Mailer
    {
        $s = $this->gatewayRepository->current();

        $password = '';
        // 口令不下发到前端，必须走 secret() 专用通道取密文
        $enc = $this->gatewayRepository->secret('mail_password');
        if ($enc !== '') {
            try {
                $password = (string) (Crypto::decrypt($enc) ?? '');
                // 兼容「早期直接存明文」的情况：解密失败则原样使用
                if ($password === '') {
                    $password = $enc;
                }
            } catch (\Throwable $e) {
                $password = $enc;
            }
        }

        return (new Mailer())->configure([
            'mail_host'            => $s['mail_host'] ?? '',
            'mail_port'            => $s['mail_port'] ?? 465,
            'mail_username'        => $s['mail_username'] ?? '',
            'mail_password'        => $password,
            'mail_encryption'      => $s['mail_encryption'] ?? 'auto',
            'mail_from_email'      => $s['mail_from_email'] ?? '',
            'mail_from_name'       => $s['mail_from_name'] ?? '',
            'mail_connect_timeout' => $s['mail_connect_timeout'] ?? 10,
            'mail_read_timeout'    => $s['mail_read_timeout'] ?? 20,
            'mail_allow_insecure'  => $s['mail_allow_insecure'] ?? true,
        ]);
    }

    /**
     * 发一封任意邮件
     *
     * @return array{ok:bool, error:string, transcript:array<int,string>}
     */
    public function send(string $to, string $subject, string $html, string $text = ''): array
    {
        $mailer = $this->mailer();
        $ok = $mailer->send([
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ]);

        return [
            'ok'         => $ok,
            'error'      => $ok ? '' : $mailer->lastError(),
            'transcript' => $mailer->transcript(),
        ];
    }

    /**
     * 发送邮箱验证码
     *
     * $purpose 决定邮件标题与正文措辞：register 注册 / reset 找回密码 / change 修改密码。
     * 三种文案必须能一眼分清 —— 用户收到的若是「修改密码验证码」却以为自己没操作过，
     * 才知道要去改密；若统称「验证码」，这封邮件就失去了它的告警价值。
     *
     * @return array{ok:bool, error:string, transcript:array<int,string>}
     */
    public function sendVerifyCode(string $to, string $code, int $ttl, string $purpose = 'register'): array
    {
        $brand = $this->brandName();
        $minutes = max(1, (int) ceil($ttl / 60));

        $meta = match ($purpose) {
            'reset'  => ['找回密码验证码', '找回密码'],
            'change' => ['修改密码验证码', '修改密码'],
            default  => ['注册验证码', '注册账号'],
        };
        $title = $meta[0];
        $action = $meta[1];

        $html = $this->wrap($brand, $title,
            '<p style="margin:0 0 16px">你正在' . $action . '，本次验证码为：</p>'
            . '<div style="font-size:30px;font-weight:700;letter-spacing:8px;color:#1f6feb;'
            . 'background:#f2f6ff;border-radius:10px;padding:16px 0;text-align:center;'
            . 'font-family:ui-monospace,Menlo,Consolas,monospace">' . htmlspecialchars($code, ENT_QUOTES) . '</div>'
            . '<p style="margin:16px 0 0;color:#666">验证码 ' . $minutes . ' 分钟内有效，请勿转发给他人。</p>'
            . '<p style="margin:8px 0 0;color:#999;font-size:12px">若非本人操作，请忽略本邮件。</p>'
        );

        $text = "【{$brand}】{$title}：{$code}（{$minutes} 分钟内有效）";

        return $this->send($to, "【{$brand}】{$title}：{$code}", $html, $text);
    }

    /**
     * 发送测试邮件（后台「测试发信」按钮）
     *
     * @return array{ok:bool, error:string, transcript:array<int,string>}
     */
    public function sendTest(string $to): array
    {
        $brand = $this->brandName();
        $html = $this->wrap($brand, 'SMTP 测试邮件',
            '<p style="margin:0 0 12px">如果你收到这封邮件，说明当前 SMTP 配置可以正常发信。</p>'
            . '<table style="border-collapse:collapse;font-size:13px">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#888">发信时间</td><td>' . date('Y-m-d H:i:s') . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#888">服务端</td><td>' . htmlspecialchars($this->serverDesc(), ENT_QUOTES) . '</td></tr>'
            . '</table>'
        );

        return $this->send($to, "【{$brand}】SMTP 测试邮件", $html, $brand . ' SMTP 测试邮件');
    }

    /** 当前 SMTP 目标描述（不含口令） */
    public function serverDesc(): string
    {
        $s = $this->gatewayRepository->current();
        return sprintf(
            '%s:%d (%s)',
            (string) ($s['mail_host'] ?? ''),
            (int) ($s['mail_port'] ?? 0),
            (string) ($s['mail_encryption'] ?? 'auto')
        );
    }

    private function brandName(): string
    {
        return (string) $this->gatewayRepository->get('app_name', config('app.name', 'AI 聚合网关'));
    }

    /** 统一邮件外壳（与站点品牌联动） */
    private function wrap(string $brand, string $heading, string $inner): string
    {
        $safeBrand = htmlspecialchars($brand, ENT_QUOTES);
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f5f6f8;'
            . 'font:14px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;color:#222">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:28px 26px;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.06)">'
            . '<div style="font-size:15px;font-weight:700;margin-bottom:18px">' . $safeBrand . '</div>'
            . '<div style="font-size:17px;font-weight:600;margin-bottom:14px">' . htmlspecialchars($heading, ENT_QUOTES) . '</div>'
            . $inner
            . '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #eee;color:#aaa;font-size:12px">'
            . '本邮件由系统自动发送，请勿直接回复。</div>'
            . '</div></body></html>';
    }
}
