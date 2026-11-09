<?php
/**
 * ZCode 客户端请求签名 V4（Client Request Signing V4）
 *
 * 参考实现：https://github.com/TriDefender/zcode-api
 *   - src/proxy/client-signing.ts （签名 V4：HKDF 握手 / Ed25519 业务签名 / SHA-256 PoW）
 *   - src/proxy/identity.ts        （buildLlmIdentityHeaders：gate 探测请求头）
 *   - src/proxy/upstream.ts        （x-session-id 与上游头集合）
 *
 * 协议三层（所有模板字段以「字面换行」拼接，已逐字节核对）：
 *   1) HKDF 握手（HMAC）
 *        ts    = 毫秒时间戳字符串
 *        nonce = 16 字节随机数的 hex
 *        sig   = HMAC-SHA256(HKDF(secret, "getSignKey_hmac"), "get_sign_key\n{apiKeyId}\n{ts}\n{nonce}")
 *        POST {origin}/api/paas/c1f3a7e2/v2/client
 *          headers: Authorization: {apiKeyId}.{apiKeySecret} / Content-Type: application/json
 *          body:    {"apiKey":.., "nonce":.., "sig":.., "ts":..}
 *        → envelope.data.privateCipher（base64）
 *        privateKey 明文 = AES-256-GCM(
 *            HKDF(secret, "ed25519_priv"), iv = cipher[0:12],
 *            additionalData = apiKeyId 的 UTF-8, tagLength = 128
 *        )[cipher[12:]]
 *        明文是 base64 编码的 PKCS8 DER，载入 Ed25519。
 *   2) 业务签名（Ed25519，输出 base64 而非 hex）
 *        ts      = 当前毫秒
 *        nonce   = 16 字节随机数 hex
 *        session = 上游请求头中的 x-session-id
 *        sig     = Ed25519(" {apiKeyId}\n{ts}\n{clientVersion}\n{sessionId}\n{nonce}")
 *        注入头：X-Client-Ts / X-Client-Version / X-Client-Sig / X-Session-Id /
 *                X-Client-Nonce / X-App-Id / X-Client-Pow
 *   3) SHA-256 工作量证明（PoW，前导 8 个 0 bit）
 *        seed      = sha256("{apiKeyId}\nzcode\n{sessionId}\n{ts}") hex 前 32 字符
 *        candidate = "{nonce12hex}{counter hex 补足 8 位}"
 *        校验 sha256("{seed}\n{candidate}") 前导 0 bit ≥ POW_BITS
 *
 * 容错（与真实客户端一致，fail-open）：
 *   - 豁免路径（不签名）：/api/v1/zcode-plan/anthropic/v1/messages、
 *     /api/v1/zcode-plan/chat/completions、/api/v1/off-peak/anthropic/v1/messages
 *   - 凭据无 `{apiKeyId}.{apiKeySecret}` 分隔 → 跳过
 *   - 上游头缺少 x-session-id → 跳过
 *   - gate 关闭 / 探测失败 / 握手失败 → 不签名直发
 *   - 连续两次 401 VERIFY_* → 该 (origin, credential) 永久绕过
 *
 * 实现依赖 PHP sodium 扩展（Ed25519）与 hash_hkdf（HKDF）。
 * openssl 无法做 Ed25519，故本类不做 openssl 回退。
 */

declare(strict_types=1);

namespace App\Support;

class ClientSigning
{
    private const DEFAULT_ORIGIN = 'https://zcode.z.ai';
    private const GATE_PATH = '/api/v1/agent/configs';
    private const HANDSHAKE_PATH = '/api/paas/c1f3a7e2/v2/client';
    private const APP_ID = 'zcode';
    private const POW_BITS = 8;
    private const NONCE_BYTES = 16;
    private const POW_NONCE_BYTES = 12;
    private const KDF_SALT = 'WD_CLIENT_SIGN_KDF_SALT';
    private const KDF_INFO_HMAC = 'getSignKey_hmac';
    private const KDF_INFO_ED25519 = 'ed25519_priv';
    private const HANDSHAKE_METHOD = 'get_sign_key';

    /** gate 探测结果缓存时长 */
    private const GATE_TTL_MS = 3_600_000;
    /** gate 网络失败后的负缓存冷却 */
    private const GATE_FAILURE_COOLDOWN_MS = 60_000;
    /** gate 返回 unavailable 后的负缓存冷却 */
    private const GATE_UNAVAILABLE_COOLDOWN_MS = 30_000;
    private const GATE_TIMEOUT_MS = 15_000;
    private const HANDSHAKE_TIMEOUT_MS = 10_000;

    private const VERIFY_SIGNATURE_INVALID = 'VERIFY_SIGNATURE_INVALID';
    private const VERIFY_APIKEY_EXPIRED = 'VERIFY_APIKEY_EXPIRED';

    /** 真实客户端永不签名的路径（已去除尾部斜杠） */
    private const UNSIGNED_PATHS = [
        '/api/v1/zcode-plan/anthropic/v1/messages',
        '/api/v1/zcode-plan/chat/completions',
        '/api/v1/off-peak/anthropic/v1/messages',
    ];

    /** 签名前需从上游头集合中剥离的名字 */
    private const SIGNING_HEADER_NAMES = [
        'x-client-ts',
        'x-client-version',
        'x-client-sig',
        'x-client-nonce',
        'x-app-id',
        'x-client-pow',
        'x-client-sign-verified',
    ];

    /** @var array<string, array{gateEnabled:bool,gateExpiresAt:int,gateNegUntil:int,privKey:?string,epoch:int,bypass:bool}> */
    private array $states = [];

    /** @var array<string, bool> 已提示过的 key，避免日志重复 */
    private array $noted = [];

    /** @var callable|null */
    private $onEvent;

    private HttpClient $http;

    public function __construct(
        ?HttpClient $http = null,
        ?callable $onEvent = null,
    ) {
        $this->http = $http ?? new HttpClient();
        $this->onEvent = $onEvent;
    }

    /**
     * 是否具备签名能力（sodium / hash_hkdf）。缺任一则整体降级为不签名。
     */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_detached')
            && function_exists('sodium_crypto_sign_seed_keypair')
            && function_exists('hash_hkdf');
    }

    /**
     * 为上游头集合注入签名头。任何不适用情形都原样返回，绝不抛异常。
     *
     * @param array<string, string> $headers 上游请求头（name => value）
     * @return array{pairs: array<string, string>, signed: bool}
     */
    public function signWithStatus(string $url, array $headers, string $credential, string $appVersion): array
    {
        if (!self::available()) {
            return ['pairs' => $headers, 'signed' => false];
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return ['pairs' => $headers, 'signed' => false];
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return ['pairs' => $headers, 'signed' => false];
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';

        if ($this->isUnsignedPath($path)) {
            return ['pairs' => $headers, 'signed' => false];
        }
        if ($this->findHeader($headers, 'x-client-sig') !== null) {
            return ['pairs' => $headers, 'signed' => false];
        }

        $stateKey = $origin . "\n" . $credential;
        $state = &$this->stateFor($stateKey);
        if ($state['bypass']) {
            return ['pairs' => $headers, 'signed' => false];
        }

        $parsedCred = $this->parseCredential($credential);
        if ($parsedCred === null) {
            $this->noteOnce($stateKey, '凭据不含 {apiKeyId}.{apiKeySecret} 分隔符 —— 跳过签名');
            return ['pairs' => $headers, 'signed' => false];
        }

        $sessionId = trim((string) $this->findHeader($headers, 'x-session-id'));
        if ($sessionId === '') {
            $this->noteOnce($stateKey, '上游请求缺少 x-session-id —— 跳过签名');
            return ['pairs' => $headers, 'signed' => false];
        }

        if (!$this->gateEnabled($stateKey, $origin, $credential)) {
            return ['pairs' => $headers, 'signed' => false];
        }

        try {
            $privateKey = $this->ensurePrivateKey($stateKey, $parsedCred, $origin);
        } catch (\Throwable $e) {
            $this->noteOnce($stateKey, '签名握手失败 —— 以未签名方式发送：' . $e->getMessage());
            $this->invalidateState($stateKey);
            return ['pairs' => $headers, 'signed' => false];
        }

        $signed = $this->buildSignedHeaders($stateKey, $headers, $privateKey, $parsedCred, $sessionId, $appVersion);
        if ($signed === null) {
            return ['pairs' => $headers, 'signed' => false];
        }

        return ['pairs' => $signed, 'signed' => true];
    }

    /**
     * 判断 401 响应是否为签名校验失败（可触发重试阶梯）。
     */
    public function isVerifyFailure(int $status, string $body): bool
    {
        if ($status !== 401) {
            return false;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return false;
        }
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $candidates = [
            $decoded['msg'] ?? null,
            $decoded['reason'] ?? null,
            $data['reason'] ?? null,
            $error['reason'] ?? null,
            $error['message'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === self::VERIFY_SIGNATURE_INVALID || $candidate === self::VERIFY_APIKEY_EXPIRED) {
                return true;
            }
        }

        return false;
    }

    /** 校验失败后丢弃该 (origin, credential) 的缓存密钥，下次重新握手。 */
    public function invalidate(string $url, string $credential): void
    {
        $origin = $this->originOf($url);
        if ($origin === null) {
            return;
        }
        $this->invalidateState($origin . "\n" . $credential);
    }

    /** 永久停止该 (origin, credential) 的签名（连续两次 VERIFY 拒绝）。 */
    public function setBypass(string $url, string $credential): void
    {
        $origin = $this->originOf($url);
        if ($origin === null) {
            return;
        }
        $stateKey = $origin . "\n" . $credential;
        $state = &$this->stateFor($stateKey);
        $state['bypass'] = true;
        $this->invalidateState($stateKey);
        $this->emit('client-signing: VERIFY 拒绝已固化 —— 该凭据后续请求跳过签名');
    }

    // ---------------------------------------------------------
    // 构建签名头
    // ---------------------------------------------------------

    /**
     * @param array{apiKeyId:string, apiKeySecret:string} $parsedCred
     * @param array<string, string> $headers
     * @return array<string, string>|null
     */
    private function buildSignedHeaders(
        string $stateKey,
        array $headers,
        string $privateKey,
        array $parsedCred,
        string $sessionId,
        string $appVersion,
    ): ?array {
        $ts = (string) now_ms();
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        try {
            $pow = $this->createProofOfWork($parsedCred['apiKeyId'], $sessionId, $ts);
            $message = $parsedCred['apiKeyId'] . "\n" . $ts . "\n" . $appVersion . "\n" . $sessionId . "\n" . $nonce;
            $sig = base64_encode(sodium_crypto_sign_detached($message, $privateKey));
        } catch (\Throwable $e) {
            $this->noteOnce($stateKey, '签名计算失败（' . $e->getMessage() . '）—— 以未签名方式发送');
            return null;
        }

        // 剥离旧签名头与 x-session-id，再按客户端顺序追加
        $cleaned = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, self::SIGNING_HEADER_NAMES, true) || $lower === 'x-session-id') {
                continue;
            }
            $cleaned[$name] = $value;
        }

        $cleaned['X-Client-Ts'] = $ts;
        $cleaned['X-Client-Version'] = $appVersion;
        $cleaned['X-Client-Sig'] = $sig;
        $cleaned['X-Session-Id'] = $sessionId;
        $cleaned['X-Client-Nonce'] = $nonce;
        $cleaned['X-App-Id'] = self::APP_ID;
        $cleaned['X-Client-Pow'] = $pow;

        return $cleaned;
    }

    // ---------------------------------------------------------
    // 握手：HKDF + HMAC → privateCipher → AES-GCM → Ed25519
    // ---------------------------------------------------------

    /**
     * @param array{apiKeyId:string, apiKeySecret:string} $parsedCred
     * @return string Ed25519 secret key（sodium 64 字节）
     */
    private function ensurePrivateKey(string $stateKey, array $parsedCred, string $origin): string
    {
        $state = &$this->stateFor($stateKey);
        if (is_string($state['privKey'])) {
            return $state['privKey'];
        }

        $cipher = $this->performHandshake($parsedCred, $origin);
        $privateKey = $this->decryptPrivateKey($parsedCred['apiKeyId'], $parsedCred['apiKeySecret'], $cipher);
        $state['privKey'] = $privateKey;

        return $privateKey;
    }

    /**
     * POST /api/paas/c1f3a7e2/v2/client → 返回 privateCipher（base64）
     *
     * @param array{apiKeyId:string, apiKeySecret:string} $parsedCred
     */
    private function performHandshake(array $parsedCred, string $origin): string
    {
        $credential = $parsedCred['apiKeyId'] . '.' . $parsedCred['apiKeySecret'];
        $ts = (string) now_ms();
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        $message = self::HANDSHAKE_METHOD . "\n" . $parsedCred['apiKeyId'] . "\n" . $ts . "\n" . $nonce;
        $sig = $this->handshakeSignature($parsedCred['apiKeySecret'], $message);

        $url = $origin . self::HANDSHAKE_PATH;
        $body = json_encode([
            'apiKey' => $credential,
            'nonce'  => $nonce,
            'sig'    => $sig,
            'ts'     => $ts,
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->http
            ->withHeaders([
                'Authorization' => $credential,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(15, 10)
            ->postJson($url, (string) $body);

        if ($response->status() !== 200) {
            throw new \RuntimeException('握手返回 HTTP ' . $response->status());
        }

        $envelope = $response->json();
        $code = $envelope['code'] ?? null;
        if ((int) $code === 500) {
            throw new \RuntimeException('握手服务端错误（code=500）');
        }
        if ((int) $code !== 200) {
            throw new \RuntimeException('握手被拒绝（code=' . var_export($code, true) . '）');
        }

        $cipher = $envelope['data']['privateCipher'] ?? null;
        if (!is_string($cipher) || $cipher === '') {
            throw new \RuntimeException('握手响应缺少 privateCipher');
        }

        return $cipher;
    }

    /**
     * HKDF-SHA256(secret, salt=KDF_SALT, info) → 32 字节
     */
    private function hkdfBytes(string $secret, string $info): string
    {
        return hash_hkdf('sha256', $secret, 32, $info, self::KDF_SALT);
    }

    /**
     * 握手签名：HMAC-SHA256(HKDF(secret, "getSignKey_hmac"), message) → base64
     */
    private function handshakeSignature(string $secret, string $message): string
    {
        $key = $this->hkdfBytes($secret, self::KDF_INFO_HMAC);

        return base64_encode(hash_hmac('sha256', $message, $key, true));
    }

    /**
     * privateCipher → Ed25519 secret key
     * AES-256-GCM(HKDF(secret, "ed25519_priv"), iv=cipher[0:12], aad=apiKeyId)
     */
    private function decryptPrivateKey(string $apiKeyId, string $secret, string $privateCipher): string
    {
        $cipher = base64_decode($privateCipher, true);
        if ($cipher === false) {
            throw new \RuntimeException('privateCipher 非法 base64');
        }
        if (strlen($cipher) <= 12 + 16) {
            throw new \RuntimeException('privateCipher 长度不足');
        }

        $aesKey = $this->hkdfBytes($secret, self::KDF_INFO_ED25519);
        $iv = substr($cipher, 0, 12);
        $tag = substr($cipher, -16);
        $payload = substr($cipher, 12, -16);

        $plain = openssl_decrypt($payload, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, $apiKeyId);
        if ($plain === false) {
            throw new \RuntimeException('privateCipher 解密失败');
        }

        $pkcs8 = base64_decode($plain, true);
        if ($pkcs8 === false) {
            throw new \RuntimeException('私钥明文非合法 base64');
        }

        return $this->importEd25519PrivateKey($pkcs8);
    }

    /**
     * PKCS8 DER（Ed25519）→ sodium 64 字节 secret key。
     *
     * sodium 需要 seed(32) + public(32)。Ed25519 的 PKCS8 结构为：
     *   SEQUENCE { INTEGER 0, SEQUENCE { OID 1.3.101.112 }, OCTET STRING { OCTET STRING seed[32] } }
     * 故直接从尾部取 32 字节 seed，用 seed 重建 keypair 即可。
     */
    private function importEd25519PrivateKey(string $pkcs8): string
    {
        if (strlen($pkcs8) < 32) {
            throw new \RuntimeException('PKCS8 私钥长度不足');
        }

        $seed = substr($pkcs8, -32);
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return sodium_crypto_sign_secretkey($keypair);
    }

    // ---------------------------------------------------------
    // PoW
    // ---------------------------------------------------------

    /**
     * 工作量证明：sha256("{seed}\n{nonce+counter}") 前导 0 bit ≥ 8
     */
    private function createProofOfWork(string $apiKeyId, string $sessionId, string $ts): string
    {
        $seedDigest = hash('sha256', $apiKeyId . "\n" . self::APP_ID . "\n" . $sessionId . "\n" . $ts);
        $seed = substr($seedDigest, 0, 32);
        $nonce = bin2hex(random_bytes(self::POW_NONCE_BYTES));

        for ($counter = 0; $counter <= 4_294_967_295; $counter++) {
            $candidate = $nonce . str_pad(dechex($counter), 8, '0', STR_PAD_LEFT);
            $digest = hash('sha256', $seed . "\n" . $candidate, true);
            if ($this->hasLeadingZeroBits($digest, self::POW_BITS)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('无法求解客户端请求工作量证明');
    }

    private function hasLeadingZeroBits(string $bytes, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord($bytes[$i]) !== 0) {
                return false;
            }
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (255 << (8 - $remainder)) & 255;
        $byte = $fullBytes < strlen($bytes) ? ord($bytes[$fullBytes]) : 255;

        return ($byte & $mask) === 0;
    }

    // ---------------------------------------------------------
    // gate 探测
    // ---------------------------------------------------------

    private function gateEnabled(string $stateKey, string $origin, string $credential): bool
    {
        $state = &$this->stateFor($stateKey);
        $now = now_ms();

        if ($state['gateExpiresAt'] > $now) {
            return $state['gateEnabled'];
        }
        if ($state['gateNegUntil'] > $now) {
            return false;
        }

        try {
            $result = $this->fetchGate($origin, $credential);
        } catch (\Throwable $e) {
            $state['gateNegUntil'] = $now + self::GATE_FAILURE_COOLDOWN_MS;
            $state['gateEnabled'] = false;
            return false;
        }

        if ($result === null) {
            $state['gateNegUntil'] = $now + self::GATE_UNAVAILABLE_COOLDOWN_MS;
            $state['gateEnabled'] = false;
            return false;
        }

        $state['gateEnabled'] = $result;
        $state['gateExpiresAt'] = $now + self::GATE_TTL_MS;

        if ($result) {
            $this->emit('client-signing: 服务端已启用 codingPlanSignature');
        }

        return $result;
    }

    /**
     * GET {origin}/api/v1/agent/configs
     * @return bool|null true=启用 / false=关闭 / null=unavailable
     */
    private function fetchGate(string $origin, string $credential): ?bool
    {
        $url = $origin . self::GATE_PATH;

        $response = $this->http
            ->withHeaders([
                'x-api-key' => $credential,
                'Accept'    => 'application/json',
            ])
            ->timeout(20, 10)
            ->get($url);

        if (!$response->ok()) {
            return null;
        }

        $parsed = $response->json();
        if ((int) ($parsed['code'] ?? -1) !== 0) {
            return null;
        }

        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        if (!array_key_exists('codingPlanSignature', $data)) {
            return false;
        }

        $signature = $data['codingPlanSignature'];
        if (!is_array($signature)) {
            return false;
        }

        return ($signature['enable'] ?? false) === true;
    }

    // ---------------------------------------------------------
    // 内部工具
    // ---------------------------------------------------------

    private function isUnsignedPath(string $path): bool
    {
        $decoded = $path;
        $raw = rawurldecode($path);
        if ($raw !== '' || $path === '') {
            $decoded = $raw;
        }
        $decoded = rtrim($decoded, '/');

        return in_array($decoded, self::UNSIGNED_PATHS, true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array{apiKeyId:string, apiKeySecret:string}|null
     */
    private function parseCredential(string $credential): ?array
    {
        $dot = strpos($credential, '.');
        if ($dot === false || $dot <= 0 || $dot !== strrpos($credential, '.')) {
            return null;
        }

        $apiKeyId = substr($credential, 0, $dot);
        $apiKeySecret = substr($credential, $dot + 1);
        if (trim($apiKeyId) === '' || trim($apiKeySecret) === '') {
            return null;
        }

        return ['apiKeyId' => $apiKeyId, 'apiKeySecret' => $apiKeySecret];
    }

    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @return array{gateEnabled:bool,gateExpiresAt:int,gateNegUntil:int,privKey:?string,epoch:int,bypass:bool}
     */
    private function &stateFor(string $stateKey): array
    {
        if (!isset($this->states[$stateKey])) {
            $this->states[$stateKey] = [
                'gateEnabled'   => false,
                'gateExpiresAt' => 0,
                'gateNegUntil'  => 0,
                'privKey'       => null,
                'epoch'         => 0,
                'bypass'        => false,
            ];
        }

        return $this->states[$stateKey];
    }

    private function invalidateState(string $stateKey): void
    {
        if (!isset($this->states[$stateKey])) {
            return;
        }
        $this->states[$stateKey]['epoch']++;
        $this->states[$stateKey]['privKey'] = null;
    }

    private function noteOnce(string $stateKey, string $message): void
    {
        if (isset($this->noted[$stateKey])) {
            return;
        }
        $this->noted[$stateKey] = true;
        $this->emit('client-signing: ' . $message);
    }

    private function emit(string $message): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($message);
            return;
        }
        Logger::info($message);
    }
}