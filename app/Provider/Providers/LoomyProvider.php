<?php
/**
 * Loomy 适配器（讯飞 Loomy）
 * 登录方式：SMS_CODE —— 手机号 + 短信验证码
 * 主机：xfinfr.com / xunfei.cn
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Provider\ErrorCategory;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\Logger;

class LoomyProvider extends AbstractProvider
{
    private const HOST = 'https://loomyad.xunfei.cn';
    private const HOST_ACCOUNT = 'https://account.xfinfr.com';
    private const VERSION = '0.9.38';

    /** 账号中心（讯飞开放平台）HMAC-SHA1 签名凭据，取自桌面客户端 la0.s 常量 */
    private const ACCOUNT_ACCESS_KEY_ID = '2thryby66wxi53sk';
    private const ACCOUNT_ACCESS_KEY_SECRET = 'zsak6eadrbawz683wf5r3m2snrwj868r';
    private const ACCOUNT_APP_ID = 'GM3LOOMY';
    /** 登录态有效期（毫秒），对应客户端 0x48190800 = 14 天 */
    private const ACCOUNT_SESSION_TTL_MS = 1209600000;

    /** 内置静态模型（对应原 App 硬编码列表） */
    public const BUILTIN_MODELS = [
        'MiniMax-M2.5',
        'doubao-seed-2.0-pro',
        '豆包 Seed 2.0 Pro',
        'DeepSeek-v3.2',
        'DeepSeek v3.2',
    ];

    /** 新手任务 ID（必须与上游 onboarding/tasks 返回的 key 一致） */
    public const ONBOARDING_TASKS = [
        'configure_remote',
        'create_soul',
        'first_message',
        'generate_ppt',
        'install_skill',
        'pick_skill',
        'set_schedule',
        'share_soul',
    ];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ModelRepository $modelRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'loomy';
    }

    public function displayName(): string
    {
        return 'Loomy';
    }

    public function loginType(): string
    {
        return 'SMS_CODE';
    }

    public function hosts(): array
    {
        return $this->providerRepository->find('loomy')['hosts'] ?? ['loomyad.xunfei.cn', 'account.xfinfr.com'];
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = (string) $account->cred('token', '');
        if ($token === '') {
            throw new \RuntimeException('Loomy 账号缺少 token，请重新登录');
        }

        $url = self::HOST . '/api/v1/chat/completions';
        $headers = $this->apiHeaders($account);
        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        return $this->parseOpenAiSse($url, $payload, $headers, $account, $onDelta);
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        return array_map(fn(array $m) => (string) $m['model_id'], $this->modelCatalog($account));
    }

    /**
     * 拉取模型目录（含上游倍率与详情）
     *
     * 倍率不在独立字段里，而是拼在模型展示名末尾，形如
     * 「DeepSeek V4 Flash 0731（x3.0）」「Qwen 3.8 Max (x12.0)」「GLM 5.3 Flash(x0.8)」。
     * 括号可能是全角（）或半角 ()，x 可能为小写；数值即积分倍率。
     *
     * 其余详情取自 context_length / max_output_tokens / capabilities。
     *
     * @return list<array<string,mixed>>
     */
    public function modelCatalog(Account $account): array
    {
        $res = $this->http()->withHeaders($this->apiHeaders($account))->timeout(20)
            ->get(self::HOST . '/api/v1/models');

        if (!$res->ok()) {
            return $this->builtinCatalog();
        }

        $data = $res->json();
        $items = $data['data'] ?? $data['models'] ?? $data['list'] ?? [];

        if (!is_array($items) || $items === []) {
            return $this->builtinCatalog();
        }

        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $id = $item;
                $name = $item;
                $meta = [];
            } elseif (is_array($item)) {
                $id = trim((string) ($item['id'] ?? $item['name'] ?? ''));
                $name = trim((string) ($item['name'] ?? $id));
                $meta = $item;
            } else {
                continue;
            }
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $rate = $this->parseRateFromName($name);

            $ctx = null;
            if (isset($meta['context_length']) && is_numeric($meta['context_length'])) {
                $ctx = (int) $meta['context_length'];
            }
            $maxTokens = null;
            if (isset($meta['max_output_tokens']) && is_numeric($meta['max_output_tokens'])) {
                $maxTokens = (int) $meta['max_output_tokens'];
            }

            $caps = is_array($meta['capabilities'] ?? null) ? $meta['capabilities'] : [];
            $multimodal = !empty($caps['vision'])
                || (is_array($caps['input_modalities'] ?? null) && count(array_diff($caps['input_modalities'], ['text'])) > 0);
            $reasoning = !empty($caps['reasoning']);
            $isImage = (string) ($meta['type'] ?? '') === 'image'
                || (!empty($caps['output_modalities']) && in_array('image', (array) $caps['output_modalities'], true));

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }
            if ($isImage) {
                $capabilities[] = 'image';
            }

            $out[] = [
                'model_id'         => $id,
                'display_name'     => $name !== '' ? $name : $id,
                'rate'             => $rate,
                'rate_source'      => $rate !== null ? 'name 后缀（xN.N）' : '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $ctx,
                'max_tokens'       => $maxTokens,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => array_values(array_unique($capabilities)),
            ];
        }

        return $out ?: $this->builtinCatalog();
    }

    /** 从展示名末尾解析倍率，如「…（x3.0）」/「(x12.0)」/「…(x0.8)」；解析不到返回 null */
    private function parseRateFromName(string $name): ?float
    {
        if (preg_match('/[（(]\s*x\s*([0-9]+(?:\.[0-9]+)?)\s*[）)]\s*$/iu', $name, $m) === 1) {
            $rate = (float) $m[1];
            return $rate >= 0 ? $rate : null;
        }
        return null;
    }

    /** 拉取失败时回退的内置模型目录（无倍率） */
    private function builtinCatalog(): array
    {
        $out = [];
        foreach (self::BUILTIN_MODELS as $id) {
            $out[] = [
                'model_id'         => $id,
                'display_name'     => $id,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => null,
                'max_tokens'       => null,
                'multimodal'       => false,
                'reasoning'        => false,
                'capabilities'     => ['stream'],
            ];
        }
        return $out;
    }

    /**
     * 按**上游真实目录**同步库内模型行。
     *
     * ⚠️ 早前登录/导入/额度三处调用的是 `sync($this->id(), self::BUILTIN_MODELS)` ——
     * 把内置硬编码表**直接灌进库**，而对外 `/v1/models` 只读库（`GatewayService::listModels`），
     * 于是那份过时表（含 `MiniMax-M2.5`/`DeepSeek v3.2` 等上游已不返回的 id）会长期外露。
     * 这与 Qoder §十二/ZCode §十五 是同一类缺陷：**目录的权威只能来自上游**，内置表仅作离线兜底。
     *
     * 修法：一律经 `modelCatalog()`（上游优先）后再入库；上游不可达时它自会回退内置表，
     * 此时不写库（避免把兜底表当成真实目录固化）。
     */
    private function syncUpstreamCatalog(int $accountId): void
    {
        $account = $this->accountRepository->find($accountId);
        if ($account === null) {
            return;
        }

        $catalog = $this->modelCatalog($account);
        if ($catalog === []) {
            return;
        }

        $this->modelRepository->syncCatalog($this->id(), $catalog);
    }

    // ---------------------------------------------------------
    // 额度：积分体系（个人积分 / 团队积分 / 当日赠送）
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        $headers = $this->apiHeaders($account);
        $packs = [];
        $balance = 0.0;
        $known = false;

        // 积分流水 + 个人余额 / 当日额度（对应 App「points/records」）
        $records = $this->http()->withHeaders($headers)->timeout(20)
            ->get(self::HOST . '/api/v1/points/records', [
                'pageNo'     => 1,
                'pageSize'   => 20,
                'recordType' => 'all',
            ]);

        if ($records->status() === 401 || $records->status() === 403) {
            return CreditInfo::failure('登录已失效，请重新登录');
        }

        $personal = null;
        $dailyBalance = null;
        $available = null;

        if ($records->ok()) {
            $data = $this->dataOf($records->json());
            $personal = $this->numOrNull($data, ['balance']);
            $dailyBalance = $this->numOrNull($data, ['dailyBalance']);
            $available = $this->numOrNull($data, ['availableBalance']);
            // 借首次鉴权成功的机会，用**上游真实目录**刷新库内模型行（不再灌内置表，见 syncUpstreamCatalog）
            $this->syncUpstreamCatalog($account->id);
            $known = $known || isset($data['total']) || isset($data['list']);
        }

        // 团队积分余额（对应 App「team-points/balance」，失败可容忍）
        $team = null;
        try {
            $tb = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::HOST . '/api/v1/team-points/balance');
            if ($tb->ok()) {
                $tdata = $this->dataOf($tb->json());
                $team = $this->numOrNull($tdata, ['currentBalance', 'balance']);
            }
        } catch (\Throwable) {
        }

        foreach (['个人积分' => $personal, '团队积分' => $team, '当日赠送' => $dailyBalance] as $name => $value) {
            if ($value !== null) {
                $packs[] = [
                    'name'   => $name,
                    'group'  => 'points',
                    'limit'  => (float) $value,
                    'used'   => 0.0,
                    'remain' => (float) $value,
                ];
                $balance += (float) $value;
                $known = true;
            }
        }

        if ($available !== null) {
            $packs[] = [
                'name'   => '可用额度',
                'group'  => 'points',
                'limit'  => (float) $available,
                'used'   => 0.0,
                'remain' => (float) $available,
            ];
        }

        return new CreditInfo(
            known: $known,
            balance: $known ? round($balance, 2) : null,
            detail: $known ? '积分合计 ' . round($balance, 2) : '未获取到积分信息',
            packs: $packs
        );
    }

    /** 取接口响应中的 data 节点（无 data 时退回根节点） */
    private function dataOf(array $json): array
    {
        return isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    }

    /** 依次查找键，返回第一个数值型字段，找不到返回 null */
    private function numOrNull(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return null;
    }

    // ---------------------------------------------------------
    // 动作：新手任务 / 兑换码
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        $headers = $this->apiHeaders($account);

        // 自检：用模型接口做真实连通性探测（token 无效会 401/403）
        if ($action === 'test' || $action === 'connectivity') {
            $token = (string) $account->cred('token', '');
            if ($token === '') {
                return ['ok' => false, 'message' => '缺少登录 token，请重新登录', 'data' => null];
            }

            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::HOST . '/api/v1/models');

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => null];
            }
            if (!$res->ok()) {
                return ['ok' => false, 'message' => '连通失败（HTTP ' . $res->status() . '）', 'data' => null];
            }

            $models = $this->models($account);

            return [
                'ok'      => true,
                'message' => '连通正常，可用模型 ' . count($models) . ' 个',
                'data'    => ['models' => $models, 'count' => count($models)],
            ];
        }

        if ($action === 'onboarding_tasks') {
            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->get(self::HOST . '/api/v1/onboarding/tasks');
            return ['ok' => $res->ok(), 'message' => $res->ok() ? '任务列表获取成功' : '获取失败', 'data' => $res->json()];
        }

        if ($action === 'complete_tasks') {
            $targets = $params['tasks'] ?? self::ONBOARDING_TASKS;
            $done = [];
            $failed = [];
            foreach ((array) $targets as $taskKey) {
                $res = $this->http()->withHeaders($headers)->timeout(20)
                    ->postJson(self::HOST . '/api/v1/onboarding/tasks/complete', ['key' => (string) $taskKey]);
                $json = $res->json();
                $code = isset($json['code']) ? (string) $json['code'] : '';
                if ($res->status() === 401 || $res->status() === 403) {
                    $failed[] = $taskKey;
                    continue;
                }
                if ($res->ok() && $code === '000000') {
                    $done[] = $taskKey;
                } else {
                    $failed[] = $taskKey;
                }
            }
            $message = sprintf('完成 %d 个任务，失败 %d 个', count($done), count($failed));
            return ['ok' => empty($failed), 'message' => $message, 'data' => ['done' => $done, 'failed' => $failed]];
        }

        if ($action === 'redeem') {
            $code = (string) ($params['code'] ?? '');
            if ($code === '') {
                return ['ok' => false, 'message' => '请填写兑换码', 'data' => null];
            }
            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->postJson(self::HOST . '/api/v1/points/redemption-codes/redeem', ['code' => $code]);
            $data = $res->json();
            return [
                'ok'      => $res->ok(),
                'message' => $res->ok()
                    ? ('兑换成功' . (isset($data['points']) ? "，获得 {$data['points']} 积分" : ''))
                    : $this->extractMessage($res->body()),
                'data'    => $data,
            ];
        }

        if ($action === 'activation') {
            $code = (string) ($params['code'] ?? $params['inviteCode'] ?? '');
            if ($code === '') {
                return ['ok' => false, 'message' => '请填写邀请码', 'data' => null];
            }
            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->postJson(self::HOST . '/api/v1/points/activation', ['inviteCode' => $code]);
            $data = $res->json();
            $activated = !isset($data['activated']) || (bool) $data['activated'];
            return [
                'ok'      => $res->ok() && $activated,
                'message' => $res->ok()
                    ? ($activated ? '激活成功' : '激活失败')
                    : $this->extractMessage($res->body()),
                'data'    => $data,
            ];
        }

        return parent::action($account, $action, $params);
    }

    // ---------------------------------------------------------
    // 登录：短信验证码
    // ---------------------------------------------------------

    /**
     * 发送验证码（对应 /login/phone/sendMsgCode，需讯飞 HMAC-SHA1 签名）
     *
     * @return array{ok:bool, message:string, msgid?:string, data?:array}
     */
    public function sendSmsCode(string $phone): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['ok' => false, 'message' => '手机号格式不正确'];
        }

        $res = $this->signedAccountRequest('/login/phone/sendMsgCode', [
            'ccode'  => '86',
            'phone'  => $phone,
            'expire' => 300,
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'message' => $res['message']];
        }

        // 上游返回 msgid，校验验证码时需原样回传
        $msgid = (string) ($res['data']['msgid'] ?? $res['data']['msgId'] ?? '');
        if ($msgid === '') {
            return ['ok' => false, 'message' => '验证码已发送但未返回 msgid'];
        }

        return [
            'ok'      => true,
            'message' => '验证码已发送',
            'msgid'   => $msgid,
            'data'    => $res['data'],
        ];
    }

    /**
     * 校验验证码并登录（对应 /login/phone/checkCode，需讯飞 HMAC-SHA1 签名）
     *
     * @return array{ok:bool, message:string, account_uid?:string, nickname?:string, account_id?:int}
     */
    public function checkSmsCode(string $phone, string $code, string $msgid = ''): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['ok' => false, 'message' => '手机号格式不正确'];
        }
        if ($code === '') {
            return ['ok' => false, 'message' => '请填写验证码'];
        }
        if ($msgid === '') {
            return ['ok' => false, 'message' => '缺少 msgid，请重新获取验证码'];
        }

        $res = $this->signedAccountRequest('/login/phone/checkCode', [
            'ccode'  => '86',
            'phone'  => $phone,
            'mcode'  => $code,
            'msgid'  => $msgid,
            'expire' => 1209600,
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'message' => $res['message']];
        }

        // 上游用 session 作为业务接口（loomyad）的 token 头
        $session = (string) ($res['data']['session'] ?? '');
        if ($session === '') {
            return ['ok' => false, 'message' => '登录成功但未返回 session'];
        }

        $uid = (string) ($res['data']['userid'] ?? $res['data']['userId'] ?? $phone);
        $profile = $this->fetchProfile($session);
        $nickname = $profile['nickname'];
        $avatar = $profile['avatar'];
        if ($nickname === '') {
            $nickname = 'Loomy-' . substr($phone, -4);
        }

        $meta = ['phone' => $phone, 'logged_in_at' => now_ms()];
        if ($avatar !== '') {
            $meta['avatar'] = $avatar;
        }

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            [
                'token'        => $session,
                'phone'        => $phone,
                'expiresAt'    => now_ms() + self::ACCOUNT_SESSION_TTL_MS,
                'refreshToken' => '',
            ],
            $meta,
            $nickname
        );

        // 首次登录顺便按**上游真实目录**同步模型（不再灌内置硬编码表，见 syncUpstreamCatalog）
        $this->syncUpstreamCatalog($accountId);

        return ['ok' => true, 'message' => '登录成功', 'account_id' => $accountId, 'account_uid' => $uid, 'nickname' => $nickname];
    }

    /** 查询昵称与头像（/userinfo/query/baseInfo），失败不阻断登录 */
    private function fetchProfile(string $session): array
    {
        $empty = ['nickname' => '', 'avatar' => ''];
        try {
            $res = $this->signedAccountRequest('/userinfo/query/baseInfo', ['session' => $session]);
        } catch (\Throwable) {
            return $empty;
        }
        if (!$res['ok']) {
            return $empty;
        }
        $data = $res['data'];
        return [
            'nickname' => (string) ($data['nickname'] ?? $data['nickName'] ?? $data['name'] ?? ''),
            'avatar'   => (string) ($data['avatar'] ?? $data['avatarUrl'] ?? $data['icon'] ?? $data['headUrl'] ?? ''),
        ];
    }

    // ---------------------------------------------------------
    // 账号中心签名（讯飞开放平台 HMAC-SHA1）
    // ---------------------------------------------------------

    /** 随机 UUID v4（与客户端 UUID.randomUUID 一致） */
    private function loomyUuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** 规范化签名路径：补前导斜杠、去尾部斜杠、按 / 分段重组 */
    private function canonicalPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1 && substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }
        return implode('/', explode('/', $path));
    }

    /** 账号中心请求体信封：{base:{appid,modelid,version,devid,ua,traceid}, param:{...}} */
    private function buildAccountBody(array $param): string
    {
        $base = [
            'appid'   => self::ACCOUNT_APP_ID,
            'modelid' => 'Web',
            'version' => '1.0.0',
            'devid'   => 'web',
            'ua'      => 'Loomy|Desktop|Electron|macOS',
            'traceid' => str_replace('-', '', $this->loomyUuid()),
        ];

        return (string) json_encode(
            ['base' => $base, 'param' => $param],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 发起带讯飞 HMAC-SHA1 签名的账号中心请求。
     *
     * 签名串为 9 段以 \n 连接：[POST, 规范路径, "", Content-MD5, "application/json", Date, Nonce, "", ""]；
     * 签名 = Base64(HMAC-SHA1(签名串, accessKeySecret))；Authorization = "account {accessKeyId}:{签名}"。
     *
     * @return array{ok:bool, status:int, data:array, message:string}
     */
    private function signedAccountRequest(string $path, array $param): array
    {
        $body = $this->buildAccountBody($param);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $nonce = $this->loomyUuid();
        $contentMd5 = base64_encode(md5($body, true));

        $stringToSign = implode("\n", [
            'POST',
            $this->canonicalPath($path),
            '',
            $contentMd5,
            'application/json',
            $date,
            $nonce,
            '',
            '',
        ]);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, self::ACCOUNT_ACCESS_KEY_SECRET, true));

        $headers = [
            'Authorization' => 'account ' . self::ACCOUNT_ACCESS_KEY_ID . ':' . $signature,
            'Date'          => $date,
            'Nonce'         => $nonce,
            'Content-Type'  => 'application/json',
            'Content-MD5'   => $contentMd5,
        ];

        $res = $this->http()->timeout(30)->postRaw(self::HOST_ACCOUNT . $path, $body, $headers);

        $json = $res->json();
        $code = (string) ($json['code'] ?? '');
        $ok = $res->ok() && in_array($code, ['', '0', '000000', '200'], true);

        return [
            'ok'      => $ok,
            'status'  => $res->status(),
            'data'    => isset($json['data']) && is_array($json['data']) ? $json['data'] : [],
            'message' => $ok
                ? '成功'
                : (string) ($json['desc'] ?? $json['message'] ?? $this->extractMessage($res->body()) ?: ('请求失败（HTTP ' . $res->status() . '）')),
        ];
    }

    public function startLogin(array $params = []): LoginSession
    {
        $phone = trim((string) ($params['phone'] ?? ''));
        if ($phone === '') {
            return new LoginSession(
                sessionId: uuid(),
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'pending',
                message: '请输入手机号获取验证码',
                extra: ['need_phone' => true]
            );
        }

        $result = $this->sendSmsCode($phone);

        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: $result['ok'] ? 'waiting' : 'failed',
            message: $result['message'],
            error: $result['ok'] ? null : 'sms_send_failed',
            extra: [
                'need_code' => true,
                'phone'     => $phone,
                'msgid'     => (string) ($result['msgid'] ?? ''),
            ],
            expiresInSeconds: 300
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $phone = trim((string) ($params['phone'] ?? ''));
        $code = trim((string) ($params['code'] ?? ''));
        $msgid = trim((string) ($params['msgid'] ?? ''));

        $result = $this->checkSmsCode($phone, $code, $msgid);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: $result['ok'] ? 'success' : 'failed',
            message: $result['message'],
            accountUid: (string) ($result['account_uid'] ?? ''),
            nickname: (string) ($result['nickname'] ?? ''),
            error: $result['ok'] ? null : 'sms_code_invalid'
        );
    }

    // ---------------------------------------------------------
    // 手工导入登录态
    // ---------------------------------------------------------

    /**
     * 把手工粘贴的 Loomy 登录态（session）校验后落库。
     *
     * 这是短信登录之外的兜底通道：从桌面客户端取出 session 直接入库。
     * 业务接口（loomyad.xunfei.cn）只需 token 头，所以该登录态可正常用于后续调用。
     *
     * @param array<string,mixed> $credential 至少含 session / token / accessToken 之一
     * @return array{ok:bool, message:string, account_uid:string, nickname:string, account_id:int}
     */
    public function ingestCredential(array $credential, string $uid = '', string $nickname = ''): array
    {
        $token = trim((string) ($credential['session'] ?? $credential['token']
            ?? $credential['accessToken'] ?? $credential['access_token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'message' => '请提供 session（桌面客户端登录态）', 'account_uid' => '', 'nickname' => '', 'account_id' => 0];
        }

        $probe = $this->probeSession($token);
        if (!$probe['ok']) {
            return ['ok' => false, 'message' => $probe['message'], 'account_uid' => '', 'nickname' => '', 'account_id' => 0];
        }

        $phone = trim((string) ($credential['phone'] ?? ''));
        $uid = $uid !== '' ? $uid : (string) ($credential['uid'] ?? '');
        if ($uid === '') {
            // 上游不回 uid：优先用手机号，再退化为 token 指纹，避免重复粘贴产生多条账号
            $uid = $phone !== '' ? $phone : 'loomy_' . substr(hash('sha256', $token), 0, 16);
        }
        if ($nickname === '') {
            $nickname = (string) ($credential['nickname'] ?? '');
        }
        if ($nickname === '') {
            $nickname = $phone !== '' ? 'Loomy-' . substr($phone, -4) : 'Loomy';
        }

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            [
                'token'        => $token,
                'phone'        => $phone,
                'expiresAt'    => (int) ($credential['expiresAt'] ?? 0) ?: (now_ms() + 86400 * 7 * 1000),
                'refreshToken' => (string) ($credential['refreshToken'] ?? ''),
            ],
            ['phone' => $phone, 'logged_in_at' => now_ms(), 'imported' => true],
            $nickname
        );

        // 导入账号后按**上游真实目录**同步（不再灌内置硬编码表，见 syncUpstreamCatalog）
        $this->syncUpstreamCatalog($accountId);

        return ['ok' => true, 'message' => '账号已保存', 'account_uid' => $uid, 'nickname' => $nickname, 'account_id' => $accountId];
    }

    /**
     * 用 /api/v1/models 校验登录态（该接口只需 token 头，无需签名）。
     *
     * @return array{ok:bool, message:string}
     */
    private function probeSession(string $token): array
    {
        try {
            // 双头并存：见 apiHeaders() 的端点分治说明（/models 认 token 头）
            $res = $this->http()->withHeaders([
                'Accept'        => 'application/json',
                'loomy-version' => self::VERSION,
                'token'         => $token,
                'Authorization' => 'Bearer ' . $token,
            ])->timeout(20)->get(self::HOST . '/api/v1/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '无法连接 Loomy 校验登录态：' . $e->getMessage()];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => '登录态无效或已过期，请重新从桌面客户端获取'];
        }
        if (!$res->ok()) {
            return ['ok' => false, 'message' => 'Loomy 拒绝了该登录态：HTTP ' . $res->status()];
        }

        // 上游以 HTTP 200 + code 表达失败（100002 = 登录已失效，请重新登录）
        $data = $res->json();
        $code = (string) ($data['code'] ?? '');
        if ($code !== '' && !in_array($code, ['0', '000000', '200'], true)) {
            return ['ok' => false, 'message' => (string) ($data['desc'] ?? $data['message'] ?? '登录态无效')];
        }

        return ['ok' => true, 'message' => '登录态有效'];
    }

    // ---------------------------------------------------------
    // 错误分类
    // ---------------------------------------------------------

    /**
     * Loomy 的错误信封是 `{"code":"100002","desc":"…"}`，**业务码是字符串**且
     * 常配 HTTP **200** 下发。父类 parseError 的状态码分支（401/403）接不住它，
     * 会掉到 `$status >= 400`（200 不满足）→ 一路 return null → 被上层当空响应。
     *
     * ⚠️ 约定 18：`code` 可能是字符串，**严禁裸 `(int)` 白名单**
     *    （`(int)"100002"` 虽为 100002，但 `(int)"invalid_model_error"` = 0 这类
     *    才是真陷阱）。这里统一先取字符串再比对。
     *
     * 已知业务码（实测）：
     *   100001 → 当前未切换到团队（非错误，业务状态）
     *   100002 → 缺少 token / 登录已失效，请重新登录  ← 必须归 SESSION_DEAD
     *   200002 → 邀请码不存在（业务校验失败，非账号故障）
     */
    public function parseError(int $status, string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $code = $decoded['code'] ?? null;
            $codeStr = is_scalar($code) ? (string) $code : '';
            $desc = (string) ($decoded['desc'] ?? $decoded['message'] ?? '');

            // 100002：令牌缺失/失效。归 SESSION_DEAD —— 不重试、不惩罚账号、
            // 直接提示重新登录。若放任它落到 UPSTREAM 会累加错误数，
            // 若落到空响应分支会被误报成 CONTENT_BLOCKED。
            if ($codeStr === '100002') {
                return [
                    'code'    => ErrorCategory::SESSION_DEAD,
                    'message' => $desc !== '' ? $desc : 'Loomy 登录已失效，请重新登录',
                ];
            }
        }

        return parent::parseError($status, $body);
    }

    // ---------------------------------------------------------
    // Token 刷新
    // ---------------------------------------------------------
    public function refreshToken(Account $account): array
    {
        $expiresAt = (int) $account->cred('expiresAt', 0);
        if ($expiresAt > 0 && $expiresAt - 600000 > now_ms()) {
            return [];
        }

        $refreshToken = (string) $account->cred('refreshToken', '');
        if ($refreshToken === '') {
            return [];
        }

        $res = $this->http()->withHeaders($this->baseHeaders())->timeout(20)
            ->postJson(self::HOST_ACCOUNT . '/login/token/refresh', ['refresh_token' => $refreshToken]);

        if (!$res->ok()) {
            Logger::warn('Loomy 刷新 Token 失败', ['uid' => $account->uid, 'status' => $res->status()]);
            return [];
        }

        $data = $res->json();
        $token = (string) ($data['token'] ?? $data['data']['token'] ?? '');
        if ($token === '') {
            return [];
        }

        return [
            'token'     => $token,
            'expiresAt' => now_ms() + ((int) ($data['expires_in'] ?? 86400)) * 1000,
        ];
    }

    private function baseHeaders(): array
    {
        return [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'loomy-version'  => self::VERSION,
            'traceparent'    => '00-' . bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(8)) . '-01',
        ];
    }

    private function apiHeaders(Account $account): array
    {
        $token = (string) $account->cred('token', '');

        // ⚠️ 两种鉴权头**必须同时在**（2026-10-01 实测，缺一即静默失败）：
        //
        //  Loomy 上游对鉴权头做了**按端点分治**，同一个 session 令牌，不同端点认不同的头名：
        //    · 业务元数据端点（/api/v1/models、/points/*、/team-points/*、/onboarding/*）
        //      只认 `token: <session>`
        //    · 推理端点（/api/v1/chat/completions）只认 `Authorization: Bearer <session>`
        //
        //  用错头的后果是**最恶心的那种**：上游回 HTTP **200** + 业务码，
        //  形如 {"code":"100002","desc":"缺少 token"}，且响应体没有 `data:` 前缀。
        //  于是：
        //    · 拿 token 头调 /models → 正常，账号进池、目录也有模型（**表面全绿**）
        //    · 拿 token 头调 /chat/completions → 100002，且响应体全被丢进 rawBody、
        //      status=200 又绕过所有错误分支 → parseOpenAiSse 静默返回空 Chunk
        //    · 网关层只看到「HTTP 200 + 零帧 + 空正文」→ 判成「上游出口安全层抑制」
        //      → 原地重采样 9~40 次 → 最终报 CONTENT_BLOCKED 400
        //  用户看到的是「内容被拦截」，真实原因是**鉴权头用错**，排查方向被彻底带偏。
        //  （历史证据：storage/logs/app-2026-09-30.log 连续 40+ 条 glm-5.3-flash 空内容重采样）
        //
        //  实测矩阵（tools/probe-loomy-auth-matrix.php）：
        //    ┌────────────────────────┬──────────┬──────────┬──────────┐
        //    │ 端点                   │ token 头 │ Bearer   │ 双头并存 │
        //    ├────────────────────────┼──────────┼──────────┼──────────┤
        //    │ GET  /models           │    ✓     │    ✗     │    ✓     │
        //    │ GET  /points/records   │    ✓     │    ✗     │    ✓     │
        //    │ GET  /onboarding/tasks │    ✓     │    ✗     │    ✓     │
        //    │ POST /chat/completions │    ✗     │    ✓     │    ✓     │
        //    └────────────────────────┴──────────┴──────────┴──────────┘
        //  只有双头并存能全端点通吃，故固定为双头。
        return array_merge($this->baseHeaders(), [
            'token'         => $token,
            'Authorization' => 'Bearer ' . $token,
        ]);
    }
}