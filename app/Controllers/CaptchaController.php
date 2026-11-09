<?php
/**
 * 图形验证码 · 公开接口
 *
 * GET /api/captcha —— 返回一张 PNG 验证码图片，答案写入 Session。
 * 登录 / 注册 / 发送邮箱验证码提交时携带 captcha 字段，由 Captcha::verify() 一次性校验。
 *
 * ⚠️ 历史坑（2026-10-02）：旧实现返回 SVG，`<text>` 节点直接带明文答案，
 *    一条正则即可还原 → 图形验证码形同虚设。现改为服务端光栅化的 PNG，
 *    响应体里没有任何明文。同时接入 CaptchaGuard 做 IP 维度取图限流。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Support\Captcha;
use App\Support\CaptchaGuard;
use App\Support\ClientIp;

class CaptchaController
{
    public function __construct(private CaptchaGuard $captchaGuard) {}

    /** GET /api/captcha */
    public function image(Request $request): Response
    {
        $ip = ClientIp::resolve($request);

        // 取图限流：防「批量拉图喂 OCR」。超限直接返回 429（前端会提示稍后再试）。
        if (!$this->captchaGuard->allowDraw($ip)) {
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '请求过于频繁，请稍后再试'],
            ], 429);
        }

        // 长度钳制（审计 SEC-07）：防止攻击者传入极大值放大图片生成开销（DoS）。
        // Captcha::make() 内部亦有钳制，此处双保险并让边界显式可见。
        $length = max(3, min(6, (int) $request->query('length', 4)));
        $png = Captcha::makePng($length);

        return Response::html($png)
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }
}
