<?php
/**
 * 登录会话：承载各供应商的异步登录流程
 * 对应 LoginType：WEBVIEW_CALLBACK / DEVICE_CODE / SMS_CODE / OAUTH_LOOPBACK / API_KEY / NONE
 */

declare(strict_types=1);

namespace App\Provider\Dto;

class LoginSession
{
    public function __construct(
        public string $sessionId,
        public string $providerKey,
        public string $loginType,
        /** pending | waiting | success | failed | expired */
        public string $state = 'pending',
        public string $message = '',
        /** 前端需要打开的授权 URL */
        public ?string $authUrl = null,
        /** 设备码 / 用户码 */
        public ?string $userCode = null,
        public ?string $deviceCode = null,
        public int $intervalSeconds = 3,
        public int $expiresInSeconds = 600,
        public string $accountUid = '',
        public string $nickname = '',
        public ?string $error = null,
        /** @var array<string, mixed> 附加展示数据 */
        public array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'session_id'   => $this->sessionId,
            'provider'     => $this->providerKey,
            'login_type'   => $this->loginType,
            'state'        => $this->state,
            'message'      => $this->message,
            'auth_url'     => $this->authUrl,
            'user_code'    => $this->userCode,
            'device_code'  => $this->deviceCode,
            'interval'     => $this->intervalSeconds,
            'expires_in'   => $this->expiresInSeconds,
            'account_uid'  => $this->accountUid,
            'nickname'     => $this->nickname,
            'error'        => $this->error,
            'extra'        => $this->extra,
        ];
    }
}