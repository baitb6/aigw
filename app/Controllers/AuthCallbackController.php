<?php
/**
 * 授权回调落地页
 * 上游（Trae WebView / Google OAuth）重定向到 http://ai.com/authorize?...
 * 此处把 code / token 交给对应 Provider 落库，再渲染一个提示页
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Provider\ProviderManager;
use App\Repositories\ProviderRepository;
use App\Support\Logger;

class AuthCallbackController
{
    public function __construct(
        private ProviderManager $providerManager,
        private ProviderRepository $providerRepository,
    ) {}

    public function handle(Request $request): Response
    {
        $query = $request->allQuery();

        // 推断供应商：显式 provider 参数优先，其次按 state 前缀
        $providerKey = trim((string) ($query['provider'] ?? $query['provider_key'] ?? ''));
        if ($providerKey === '') {
            $providerKey = $this->guessProvider($query);
        }

        Logger::info('收到授权回调', ['provider' => $providerKey, 'query' => array_keys($query)]);

        if ($providerKey === '' || !$this->providerRepository->exists($providerKey)) {
            return $this->page('无法识别授权来源', '回调参数中缺少 provider 标识，请回到管理台重新发起登录。', false);
        }

        try {
            $provider = $this->providerManager->get($providerKey);
            $result = $provider->handleCallback($query);
        } catch (\Throwable $e) {
            Logger::error('授权回调处理失败', ['provider' => $providerKey, 'error' => $e->getMessage()]);
            return $this->page('授权失败', $e->getMessage(), false);
        }

        if (empty($result['ok'])) {
            return $this->page('授权失败', (string) ($result['message'] ?? '未知错误'), false);
        }

        return $this->page(
            '授权成功',
            (string) ($result['message'] ?? '账号已加入账号池，可以关闭本页面返回管理台。'),
            true
        );
    }

    private function guessProvider(array $query): string
    {
        $state = (string) ($query['state'] ?? '');
        foreach ($this->providerRepository->keys() as $key) {
            if ($state !== '' && str_starts_with($state, $key . ':')) {
                return $key;
            }
        }

        // Trae 回环回调（http://127.0.0.1:<port>/authorize）不带 provider 标识，
        // 仅携带 refreshToken / userJwt / userInfo / loginTraceID，据此判定。
        if (
            isset($query['refreshToken']) || isset($query['refresh_token'])
            || isset($query['userJwt']) || isset($query['user_jwt'])
            || isset($query['userInfo']) || isset($query['loginTraceID'])
        ) {
            // trae / trae_intl 共用同一回环回调地址，按登录会话归属区分区域
            foreach ($this->providerRepository->keys() as $key) {
                try {
                    $provider = $this->providerManager->get($key);
                } catch (\Throwable) {
                    continue;
                }
                if (method_exists($provider, 'resolveLoginProvider') && $provider->resolveLoginProvider($query)) {
                    return $key;
                }
            }

            return 'trae';
        }

        // Google OAuth 回调特征
        if (isset($query['code']) && isset($query['scope'])) {
            return 'antigravity';
        }

        return '';
    }

    /**
     * 极简结果页（深色卡片风，与前端视觉一致）
     */
    private function page(string $title, string $message, bool $ok): Response
    {
        $accent = $ok ? '#00b894' : '#e17055';
        $icon = $ok ? '&#10003;' : '&#10005;';
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $messageEsc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        // 兜底引导：本页恰好在回环地址上渲染时，说明浏览器已经能访问回环回调页，
        // 但用户可能是通过远程浏览器授权、或需要在本机管理台完成入库，因此始终提供
        // 「复制当前完整回调地址」的能力，配合管理台的「粘贴回调地址」入口使用。
        $selfUrl = $this->currentUrl();
        $selfUrlEsc = htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8');
        $fallbackBlock = $this->fallbackBlock($selfUrlEsc);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$titleEsc} · AI 聚合网关</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: grid; place-items: center;
    font-family: -apple-system, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
    background: #f4f1ec;
    background-image: radial-gradient(circle at 12% 18%, rgba(255,159,67,.16), transparent 42%),
                      radial-gradient(circle at 88% 82%, rgba(0,184,148,.16), transparent 46%);
    padding: 24px;
  }
  .card {
    width: 100%; max-width: 460px; background: #fff; border-radius: 20px;
    padding: 40px 32px; text-align: center;
    box-shadow: 0 18px 50px rgba(28, 30, 34, .1), 0 2px 6px rgba(28,30,34,.05);
    border: 1px solid rgba(28,30,34,.06);
  }
  .badge {
    width: 64px; height: 64px; margin: 0 auto 20px; border-radius: 20px;
    display: grid; place-items: center; font-size: 30px; color: #fff;
    background: {$accent};
  }
  h1 { margin: 0 0 10px; font-size: 20px; color: #1c1e22; letter-spacing: -.01em; }
  p { margin: 0; color: #6b6f76; line-height: 1.7; font-size: 14px; }
  a {
    display: inline-block; margin-top: 26px; padding: 11px 24px; border-radius: 12px;
    background: #1c1e22; color: #fff; text-decoration: none; font-size: 14px; font-weight: 600;
  }
  .fallback {
    margin-top: 24px; padding-top: 20px; border-top: 1px dashed rgba(28,30,34,.14); text-align: left;
  }
  .fallback__title { font-size: 13px; font-weight: 700; color: #1c1e22; margin-bottom: 6px; }
  .fallback__desc { font-size: 12px; color: #8b8f97; line-height: 1.6; margin-bottom: 10px; }
  .fallback__row { display: flex; gap: 8px; }
  .fallback__url {
    flex: 1; min-width: 0; padding: 9px 12px; border-radius: 10px; font-size: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    color: #4a4e56; background: #f4f1ec; border: 1px solid rgba(28,30,34,.1);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .fallback__btn {
    flex: none; padding: 9px 16px; border-radius: 10px; border: 0; cursor: pointer;
    background: {$accent}; color: #fff; font-size: 12px; font-weight: 600;
  }
  .fallback__hint { margin-top: 8px; font-size: 11px; color: #9aa0a8; line-height: 1.6; }
</style>
</head>
<body>
  <div class="card">
    <div class="badge">{$icon}</div>
    <h1>{$titleEsc}</h1>
    <p>{$messageEsc}</p>
    <a href="/">返回管理台</a>
    {$fallbackBlock}
  </div>
  <script>
    (function () {
      var btn = document.getElementById('copy-cb');
      if (!btn) { return; }
      var raw = document.getElementById('cb-url').getAttribute('data-url') || location.href;
      var done = function () { btn.textContent = '已复制 ✓'; };
      var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = raw;
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); }
        catch (e) { btn.textContent = '请手动复制'; }
        document.body.removeChild(ta);
      };
      btn.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(raw).then(done, fallback);
        } else {
          fallback();
        }
      });
    })();
  </script>
</body>
</html>
HTML;

        return Response::html($html, $ok ? 200 : 400);
    }

    /**
     * 还原浏览器地址栏里的完整回调 URL（用于结果页的「复制回调地址」兜底）。
     *
     * ⚠️ 安全（审计 SEC-08）：**不再无条件信任 `HTTP_HOST`** —— 它可被客户端
     * 伪造，用于在结果页展示攻击者域名、诱导用户把授权码粘到伪造地址上。
     * 现改为：仅接受「站点基准地址 / CORS 白名单」中的主机，或回环地址
     * （OAuth 回环回调 http://127.0.0.1:51120|51121 必须放行）；其余一律
     * 回落到 `config('app.url')` 的主机。
     */
    private function currentUrl(): string
    {
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        }

        $host = $this->safeHost();
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $scheme . '://' . $host . $uri;
    }

    /**
     * 取一个可信的主机名（含端口）。不可信时回落到站点基准地址。
     */
    private function safeHost(): string
    {
        $requested = (string) ($_SERVER['HTTP_HOST'] ?? '');

        // 去掉端口，得到纯 host 用于比对
        $hostOnly = strtolower((string) preg_replace('/:\d+$/', '', $requested));

        // 0) 语法校验：拒绝含非法字符（防 "evil.com/path"、"a@b" 之类）
        if ($requested === '' || !preg_match('/^[A-Za-z0-9._\-\[\]:]+$/', $requested)) {
            $requested = '';
        }

        // 1) 回环地址直接放行（OAuth 回环回调桥接）
        if (in_array($hostOnly, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            return $requested;
        }

        // 2) 站点基准地址 + CORS 白名单中的主机放行
        $allowed = [];
        $base = (string) config('app.url', '');
        $baseHost = parse_url($base, PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '') {
            $allowed[] = strtolower($baseHost);
        }
        foreach ((array) config('app.cors_origins', []) as $origin) {
            $h = parse_url((string) $origin, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $allowed[] = strtolower($h);
            }
        }

        if ($requested !== '' && in_array($hostOnly, $allowed, true)) {
            return $requested;
        }

        // 3) 兜底：回落站点基准地址的主机
        if (is_string($baseHost) && $baseHost !== '') {
            return $baseHost;
        }

        return '127.0.0.1';
    }

    /**
     * 兜底引导块：展示可复制的完整回调地址，配合管理台 /api/admin/auth/{provider}/callback 使用。
     */
    private function fallbackBlock(string $selfUrlEsc): string
    {
        return <<<HTML
      <div class="fallback">
        <div class="fallback__title">在别的电脑上完成授权？</div>
        <div class="fallback__desc">回调页打不开也没关系：复制浏览器地址栏里的完整回调链接，回到管理台用「粘贴回调地址」即可完成入库。</div>
        <div class="fallback__row">
          <span class="fallback__url" id="cb-url" data-url="{$selfUrlEsc}">{$selfUrlEsc}</span>
          <button type="button" class="fallback__btn" id="copy-cb">复制回调地址</button>
        </div>
        <div class="fallback__hint">请尽快粘贴，避免授权码过期。</div>
      </div>
HTML;
    }
}