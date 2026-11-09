<?php
/**
 * 管理台 · 登录与会话
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Middleware\AdminAuthMiddleware;
use App\Repositories\AdminRepository;
use App\Repositories\GatewayRepository;
use App\Support\CaptchaGate;
use App\Support\ClientIp;
use App\Support\Logger;
use App\Support\LoginGuard;

class AuthController
{
    public function __construct(
        private AdminRepository $adminRepository,
        private GatewayRepository $gatewayRepository,
        private LoginGuard $loginGuard,
        private CaptchaGate $captchaGate,
    ) {}

    /** POST /api/admin/login */
    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return $this->fail('请输入用户名与密码');
        }

        $ip = $this->ip();

        // 持久化防爆破：IP + 账号双维度，清 cookie 换会话也无法绕过
        $locked = $this->loginGuard->lockedSeconds('admin', $ip, $username);
        if ($locked > 0) {
            return $this->fail('尝试过于频繁，请 ' . $locked . ' 秒后再试', 429);
        }

        // 图形验证码（管理端可在设置中关闭）
        if ($this->captchaRequired()) {
            $captchaError = $this->captchaGate->check($request);
            if ($captchaError !== null) {
                return $this->fail($captchaError);
            }
        }

        $admin = $this->adminRepository->attempt($username, $password);
        if ($admin === null) {
            $this->loginGuard->recordFailure('admin', $ip, $username, $this->maxAttempts(), $this->lockSeconds());

            Logger::warn('管理台登录失败', ['username' => $username, 'ip' => $ip]);
            return $this->fail('用户名或密码错误', 401);
        }

        $this->loginGuard->clear('admin', $ip, $username);
        AdminAuthMiddleware::login($admin);
        $this->adminRepository->touchLogin((int) $admin['id'], $ip);

        return Response::json([
            'ok'   => true,
            'data' => [
                'id'       => (int) $admin['id'],
                'username' => (string) $admin['username'],
                'nickname' => (string) ($admin['nickname'] ?? ''),
            ],
        ]);
    }

    /** POST /api/admin/logout */
    public function logout(Request $request): Response
    {
        AdminAuthMiddleware::logout();
        return Response::json(['ok' => true]);
    }

    /** GET /api/admin/me */
    public function me(Request $request): Response
    {
        $admin = AdminAuthMiddleware::current() ?? [];
        $id = (int) ($admin['id'] ?? 0);
        $record = $id > 0 ? $this->adminRepository->find($id) : null;

        return Response::json([
            'ok'   => true,
            'data' => [
                'id'            => $id,
                'username'      => (string) ($admin['username'] ?? ''),
                'nickname'      => (string) ($admin['nickname'] ?? ''),
                'last_login_at' => $record['last_login_at'] ?? null,
                'last_login_ip' => (string) ($record['last_login_ip'] ?? ''),
            ],
        ]);
    }

    /** POST /api/admin/profile */
    public function updateProfile(Request $request): Response
    {
        $admin = AdminAuthMiddleware::current() ?? [];
        $nickname = trim((string) $request->input('nickname', ''));

        if (mb_strlen($nickname) > 32) {
            return $this->fail('昵称过长');
        }

        $id = (int) ($admin['id'] ?? 0);
        if ($id <= 0 || $this->adminRepository->find($id) === null) {
            return $this->fail('账号不存在', 404);
        }

        $this->adminRepository->updateProfile($id, $nickname);

        AdminAuthMiddleware::bootSession();
        if (isset($_SESSION['aigw_admin'])) {
            $_SESSION['aigw_admin']['nickname'] = $nickname;
        }

        return Response::json(['ok' => true, 'message' => '资料已更新']);
    }

    /** POST /api/admin/password */
    public function changePassword(Request $request): Response
    {
        $admin = AdminAuthMiddleware::current() ?? [];
        $id = (int) ($admin['id'] ?? 0);

        $current = (string) $request->input('current_password', '');
        $next = (string) $request->input('new_password', '');

        if (strlen($next) < 6) {
            return $this->fail('新密码至少 6 位');
        }

        $record = $this->adminRepository->find($id);
        if ($record === null) {
            return $this->fail('账号不存在', 404);
        }
        if (!password_verify($current, (string) $record['password'])) {
            return $this->fail('当前密码不正确', 401);
        }

        $this->adminRepository->updatePassword($id, $next);

        // 改密已抬会话纪元（其他端旧会话立即失效）；刷新本人会话水位避免误踢自己
        $epoch = $this->adminRepository->epoch($id);
        if ($epoch !== null) {
            AdminAuthMiddleware::refreshEpoch($epoch);
        }

        Logger::info('管理台密码已修改', ['admin_id' => $id]);

        return Response::json(['ok' => true, 'message' => '密码已更新，其他设备已强制下线']);
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

    private function maxAttempts(): int
    {
        return max(1, (int) $this->gatewayRepository->get('login_max_attempts', 5));
    }

    private function lockSeconds(): int
    {
        return max(1, (int) $this->gatewayRepository->get('login_lock_seconds', 900));
    }

    private function ip(): string
    {
        // 解析真实客户端 IP（CDN 回源时 REMOTE_ADDR 是回源节点，会误伤全部用户）
        return ClientIp::resolve(new \App\Core\Http\Request());
    }
}