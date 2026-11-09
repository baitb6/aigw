<?php
/**
 * 思维注入管理
 *  - 管理台：全局思维（user_id=0）全量 CRUD + 靶点开关 + 预演 + 修订还原 + 诊断
 *  - 用户端：仅本人自建思维（user_id>0）CRUD，全局思维只读；
 *            另可自选「在哪些平台接受注入」（用户级平台开关）与自测自证口令
 *
 * 接口分工（管理台）：
 *   GET    /api/admin/personas                     列表（含靶点矩阵与诊断告警）
 *   GET    /api/admin/personas/status              只读诊断（对应原工具 --status）
 *   POST   /api/admin/personas                     新建
 *   POST   /api/admin/personas/seed                一键灌入默认思维
 *   POST   /api/admin/personas/preview             预演（对应 --dry-run，不写盘）
 *   POST   /api/admin/personas/selfcheck           自证测试（对应「破甲自检」）
 *   GET    /api/admin/personas/{id}                详情（含正文）
 *   PUT    /api/admin/personas/{id}                保存
 *   DELETE /api/admin/personas/{id}                删除
 *   POST   /api/admin/personas/{id}/default        设为默认（自动注入）
 *   POST   /api/admin/personas/{id}/target         靶点开关
 *   GET    /api/admin/personas/{id}/revisions      修订快照列表
 *   POST   /api/admin/personas/{id}/restore        还原到某快照
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Middleware\UserAuthMiddleware;
use App\Repositories\PersonaRepository;
use App\Repositories\ProviderRepository;
use App\Services\PersonaInjector;
use App\Support\Audit;
use App\Support\Seal;
use App\Support\Tenant;

class PersonaController
{
    private const MODES = ['prepend', 'append', 'replace'];

    public function __construct(
        private PersonaRepository $personaRepository,
        private PersonaInjector $personaInjector,
        private ProviderRepository $providerRepository,
    ) {}

    /* ══════════════════════════════════════════════════════════
       管理台
       ══════════════════════════════════════════════════════════ */

    /** GET /api/admin/personas */
    public function index(Request $request): Response
    {
        $items = array_values(array_filter(
            $this->personaRepository->all(0, true),
            fn($p) => (int) $p['user_id'] === 0
        ));

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $this->decorate($items, null),
                'modes'   => $this->modeOptions(),
                'targets' => $this->targetOptions(null),
                'models'  => $this->modelOptions(),
                'status'  => $this->personaInjector->status(0),
                'gates'   => $this->personaInjector->gates(),
                'defaults' => [
                    'passphrase' => PersonaInjector::DEFAULT_PASSPHRASE,
                    'reply'      => PersonaInjector::DEFAULT_REPLY,
                ],
            ],
        ]);
    }

    /** GET /api/admin/personas/status —— 只读诊断，不写任何东西 */
    public function status(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => [
                'status'  => $this->personaInjector->status(0),
                'targets' => $this->targetOptions(null),
            ],
        ]);
    }

    /** POST /api/admin/personas */
    public function store(Request $request): Response
    {
        $payload = $request->json();

        $invalid = $this->validate($payload, $data);
        if ($invalid !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $invalid]], 400);
        }

        $data['user_id'] = 0;
        $id = $this->personaRepository->create($data);

        Audit::ok('persona.create', [
            'target_type' => 'persona',
            'target_id'   => (string) $id,
            'summary'     => '新建全局思维「' . (string) ($data['name'] ?? '') . '」',
            'detail'      => ['mode' => $data['mode'] ?? 'prepend', 'bytes' => mb_strlen((string) ($data['content'] ?? ''))],
        ]);

        return Response::json(['ok' => true, 'message' => '思维已创建', 'data' => ['id' => $id]]);
    }

    /** POST /api/admin/personas/seed —— 一键灌入默认思维 */
    public function seed(Request $request): Response
    {
        $result = $this->personaInjector->seedDefault(0);

        return Response::json([
            'ok'      => true,
            'message' => $result['reason'],
            'data'    => ['id' => $result['id'], 'created' => $result['created']],
        ]);
    }

    /** GET /api/admin/personas/{id} */
    public function show(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'persona'   => $persona,
                'targets'   => $this->personaRepository->targetStates($persona, $this->personaInjector->knownTargets(null)),
                'revisions' => $this->personaRepository->revisions((int) $persona['id'], 30),
                'seal_ok'   => Seal::matches((string) $persona['content'], (string) $persona['seal']),
            ],
        ]);
    }

    /** PUT /api/admin/personas/{id} */
    public function update(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $payload = $request->json();
        $invalid = $this->validate($payload, $data, true);
        if ($invalid !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $invalid]], 400);
        }
        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->personaRepository->update((int) $persona['id'], $data);
        $fresh = $this->findGlobal((int) $persona['id']);

        Audit::ok('persona.update', [
            'target_type' => 'persona',
            'target_id'   => (string) $persona['id'],
            'summary'     => '编辑全局思维「' . (string) ($fresh['name'] ?? $persona['name']) . '」',
            'detail'      => [
                'fields'  => array_keys($data),
                'version' => (int) ($fresh['version'] ?? 0),
                'mode'    => (string) ($fresh['mode'] ?? ''),
            ],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '思维已保存（旧版本已自动备份，可随时还原）',
            'data'    => [
                'version' => $fresh['version'] ?? 0,
                'seal'    => Seal::short((string) ($fresh['seal'] ?? '')),
                'bytes'   => (int) ($fresh['bytes'] ?? 0),
            ],
        ]);
    }

    /** DELETE /api/admin/personas/{id} */
    public function destroy(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $this->personaRepository->delete((int) $persona['id']);

        Audit::ok('persona.delete', [
            'target_type' => 'persona',
            'target_id'   => (string) $persona['id'],
            'summary'     => '删除全局思维「' . (string) $persona['name'] . '」',
            'detail'      => ['name' => (string) $persona['name']],
        ]);

        return Response::json(['ok' => true, 'message' => '思维已删除（修订快照与靶点状态一并清理）']);
    }

    /** POST /api/admin/personas/{id}/default */
    public function setDefault(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $isDefault = !empty($request->json()['is_default']);
        $this->personaRepository->update((int) $persona['id'], [
            'is_default' => $isDefault,
            'enabled'    => true,
        ]);

        Audit::ok('persona.default', [
            'target_type' => 'persona',
            'target_id'   => (string) $persona['id'],
            'summary'     => $isDefault
                ? '把「' . (string) $persona['name'] . '」设为默认思维（原默认自动取消）'
                : '取消「' . (string) $persona['name'] . '」的默认标位',
            'detail'      => ['is_default' => $isDefault],
        ]);

        return Response::json(['ok' => true, 'message' => '已更新默认思维']);
    }

    /** POST /api/admin/personas/{id}/target —— 单个靶点开关 */
    public function target(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $payload = $request->json();
        $target  = trim((string) ($payload['target'] ?? ''));
        if ($target === '') {
            return Response::json(['ok' => false, 'error' => ['message' => '缺少 target']], 400);
        }
        if (!in_array($target, $this->personaInjector->knownTargets(null), true)) {
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '未知靶点：' . $target . '（靶点必须是已启用的供应商）'],
            ], 400);
        }

        $this->personaRepository->setTarget((int) $persona['id'], $target, !empty($payload['enabled']));

        Audit::ok('persona.target', [
            'target_type' => 'persona',
            'target_id'   => (string) $persona['id'],
            'summary'     => (!empty($payload['enabled']) ? '开启' : '关闭') . '靶点「' . $target . '」（思维「' . (string) $persona['name'] . '」）',
            'detail'      => ['target' => $target, 'enabled' => !empty($payload['enabled'])],
        ]);

        return Response::json(['ok' => true, 'message' => '靶点已更新']);
    }

    /** GET /api/admin/personas/{id}/revisions */
    public function revisions(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['items' => $this->personaRepository->revisions((int) $persona['id'], 50)],
        ]);
    }

    /** POST /api/admin/personas/{id}/restore */
    public function restore(Request $request): Response
    {
        $persona = $this->findGlobal((int) $request->routeParam('id', '0'));
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $revisionId = (int) ($request->json()['revision_id'] ?? 0);
        if ($revisionId <= 0) {
            return Response::json(['ok' => false, 'error' => ['message' => '缺少 revision_id']], 400);
        }

        if (!$this->personaRepository->restore((int) $persona['id'], $revisionId)) {
            return Response::json(['ok' => false, 'error' => ['message' => '快照不存在或不属于该思维']], 404);
        }

        Audit::ok('persona.restore', [
            'target_type' => 'persona',
            'target_id'   => (string) $persona['id'],
            'summary'     => '把「' . (string) $persona['name'] . '」还原到快照 #' . $revisionId,
            'detail'      => ['revision_id' => $revisionId],
        ]);

        return Response::json(['ok' => true, 'message' => '已还原到所选快照（还原前的版本也已留档）']);
    }

    /**
     * POST /api/admin/personas/preview —— 预演，不写任何东西
     *
     * 入参三选一优先级：explicit content > persona_id > 默认思维。
     * 允许直接传 content，是为了「还没保存就想看看注入后长什么样」。
     */
    public function preview(Request $request): Response
    {
        $payload = $request->json();

        $persona = null;
        if (!empty($payload['persona_id'])) {
            $persona = $this->findGlobal((int) $payload['persona_id']);
        }
        if ($persona === null) {
            $persona = $this->personaRepository->active(0);
        }

        $draft = [
            'id'      => $persona['id'] ?? 0,
            'name'    => $persona['name'] ?? '未保存草稿',
            'version' => $persona['version'] ?? 0,
            'content' => (string) ($payload['content'] ?? ($persona['content'] ?? '')),
            'mode'    => (string) ($payload['mode'] ?? ($persona['mode'] ?? 'prepend')),
            'seal'    => Seal::of((string) ($payload['content'] ?? ($persona['content'] ?? ''))),
        ];

        if (trim((string) $draft['content']) === '') {
            return Response::json(['ok' => false, 'error' => ['message' => '思维正文为空，无法预演']], 400);
        }

        $result = $this->personaInjector->preview(
            $draft,
            (string) ($payload['sample_system'] ?? '')
        );

        return Response::json([
            'ok'   => true,
            'data' => $result + [
                'mode_label' => $this->modeLabel((string) $draft['mode']),
                // 二次注入不会堆叠：这里直接给出「再注入一遍」的结果供对照
                'idempotent' => $this->previewTwice($draft, (string) ($payload['sample_system'] ?? '')),
            ],
        ]);
    }

    /** 同一份思维连注两次后的 system —— 与一次注入结果一致即证明幂等 */
    private function previewTwice(array $persona, string $sampleSystem): array
    {
        $once = $this->personaInjector->preview($persona, $sampleSystem);
        $block = $once['block'];

        $messages = [['role' => 'system', 'content' => $once['system'] . "\n\n" . $block]];
        $again = $this->personaInjector->injectIntoMessages($messages, $persona);

        $system = (string) ($again['messages'][0]['content'] ?? '');
        $hits = substr_count($system, PersonaInjector::BEGIN);

        return [
            'system'    => $system,
            'block_hits' => $hits,
            'stable'    => $hits === 1,
        ];
    }

    /** POST /api/admin/personas/selfcheck —— 自证测试：给一段文本，看会不会命中 */
    public function selfcheck(Request $request): Response
    {
        $payload = $request->json();
        $text = (string) ($payload['text'] ?? '');
        $scope = 0;
        if (!empty($payload['persona_id'])) {
            $persona = $this->findGlobal((int) $payload['persona_id']);
            if ($persona === null) {
                return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
            }
            $scope = (int) $persona['user_id'];
            // 只测这一份思维：临时按其 user_id 作用域取首个思维更复杂，这里直接逐份比对
            $hit = $persona['enabled'] && $persona['selfcheck_on']
                && trim((string) $persona['selfcheck_pass']) !== ''
                && trim((string) $persona['selfcheck_pass']) === trim($text);

            return Response::json([
                'ok'   => true,
                'data' => [
                    'hit'   => $hit,
                    'reply' => $hit
                        ? (trim((string) $persona['selfcheck_reply']) !== ''
                            ? (string) $persona['selfcheck_reply']
                            : PersonaInjector::DEFAULT_REPLY)
                        : '',
                    'persona_id'   => (int) $persona['id'],
                    'persona_name' => (string) $persona['name'],
                    'passphrase'   => (string) $persona['selfcheck_pass'],
                ],
            ]);
        }

        $result = $this->personaInjector->selfcheck(
            [['role' => 'user', 'content' => $text]],
            $scope
        );

        return Response::json(['ok' => true, 'data' => $result]);
    }

    /* ══════════════════════════════════════════════════════════
       用户端：本人自建思维（全局思维只读）
       ══════════════════════════════════════════════════════════ */

    /** GET /api/user/personas */
    public function userIndex(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $gates = $this->personaInjector->gates();

        $mine = $this->personaRepository->all($uid);
        $global = array_values(array_filter(
            $this->personaRepository->all(0, true),
            fn($p) => (int) $p['user_id'] === 0
        ));

        // 用户级思维开关（默认全关）：自建思维看它自己的 enabled；全局思维看用户级开关表
        $globalIds    = array_map(fn($p) => (int) $p['id'], $global);
        $enableStates = $this->personaInjector->personaEnableStates($uid, $globalIds);

        // 靶点 / 平台清单一律按「本人可见范围」给（全局 + 本人自建）——
        // 他人私有的 custom_u<uid>_xxx 不得出现在本用户的界面上
        $mineOut = $this->decorate($mine, $uid);
        foreach ($mineOut as &$p) {
            // 自建思维：它自己的 enabled 就是「我是否开启」
            $p['user_on']         = !empty($p['enabled']);
            $p['user_switchable'] = true;
        }
        unset($p);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $mineOut,
                // 全局思维对用户只读：不回正文，避免把管理员配置整份下发；
                // 但自证口令要给出（普通用户靠它一眼验证链路通断）
                'global'  => array_map(fn($p) => [
                    'id'             => (int) $p['id'],
                    'name'           => (string) $p['name'],
                    'description'    => (string) $p['description'],
                    'mode'           => (string) $p['mode'],
                    'version'        => (int) $p['version'],
                    'enabled'        => (bool) $p['enabled'],
                    'is_default'     => (bool) $p['is_default'],
                    'bytes'          => (int) $p['bytes'],
                    'targets'        => $p['targets'] ?? [],
                    'models'         => $p['models'] ?? [],
                    'selfcheck_on'   => (bool) $p['selfcheck_on'],
                    'selfcheck_pass' => (string) $p['selfcheck_pass'],
                    'selfcheck_reply' => (string) $p['selfcheck_reply'],
                    // 用户级思维开关（默认关）：这份全局思维对「我」是否生效
                    'user_on'         => !empty($enableStates[(int) $p['id']]['enabled']),
                    'user_switchable' => true,
                ], $global),
                'modes'   => $this->modeOptions(),
                'targets' => $this->targetOptions($uid),
                'models'  => $this->modelOptions(),
                'status'  => $this->personaInjector->status($uid),
                // 用户级平台开关：普通用户自选在哪些平台上接受注入
                'platforms' => $this->personaInjector->platformStates($uid),
                // 当前开启的那一份思维（互斥：至多一份）—— 用户端「自证口令」跟着它走
                'active_persona' => $this->activeUserPersonaPayload($uid),
                // 治理闸门：普通用户是否被允许自定义思维 / 系统内置思维是否开启
                'custom_allowed' => $gates['user_enabled'],
                'system_enabled' => $gates['system_enabled'],
                'defaults' => [
                    'passphrase' => PersonaInjector::DEFAULT_PASSPHRASE,
                    'reply'      => PersonaInjector::DEFAULT_REPLY,
                ],
            ],
        ]);
    }

    /** POST /api/user/personas */
    public function userStore(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }
        if (!$this->personaInjector->gates()['user_enabled']) {
            return $this->customDisabled();
        }

        $payload = $request->json();
        $invalid = $this->validate($payload, $data, false, $uid);
        if ($invalid !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $invalid]], 400);
        }

        $data['user_id'] = $uid;
        // 用户端默认关闭：新建后不自动生效，需要用户在列表里显式开启
        $data['enabled'] = !empty($payload['enabled']);
        $id = $this->personaRepository->create($data);

        Audit::ok('persona.create', [
            'user_id'     => $uid,
            'target_type' => 'persona',
            'target_id'   => (string) $id,
            'summary'     => '新建本人思维「' . (string) ($data['name'] ?? '') . '」',
            'detail'      => ['mode' => $data['mode'] ?? 'prepend', 'bytes' => mb_strlen((string) ($data['content'] ?? ''))],
        ]);

        return Response::json(['ok' => true, 'message' => '思维已创建（默认关闭，需自行开启）', 'data' => ['id' => $id]]);
    }

    /** PUT /api/user/personas/{id} */
    public function userUpdate(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }
        if (!$this->personaInjector->gates()['user_enabled']) {
            return $this->customDisabled();
        }

        $id = (int) $request->routeParam('id', '0');
        if (!$this->personaRepository->ownedBy($id, $uid)) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $payload = $request->json();
        $invalid = $this->validate($payload, $data, true, $uid);
        if ($invalid !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $invalid]], 400);
        }
        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->personaRepository->update($id, $data);

        // 用户端：把 enabled 置为 true = 开启这一份 → 互斥关掉其它（同一时间只能开一份）
        if (array_key_exists('enabled', $data) && $data['enabled']) {
            $this->personaRepository->setUserActivePersona($uid, $id, true);
        }

        $freshName = (string) ($this->personaRepository->find($id)['name'] ?? '');
        Audit::ok('persona.update', [
            'user_id'     => $uid,
            'target_type' => 'persona',
            'target_id'   => (string) $id,
            'summary'     => '编辑本人思维「' . $freshName . '」',
            'detail'      => ['fields' => array_keys($data), 'enabled' => !empty($data['enabled'])],
        ]);

        return Response::json(['ok' => true, 'message' => '思维已保存（旧版本已自动备份）']);
    }

    /**
     * 用户端「当前开启的那一份思维」的公开字段（**不回正文**）
     *
     * @return array{id:int,name:string,layer:string,selfcheck_on:bool,selfcheck_pass:string,selfcheck_reply:string}|null
     */
    private function activeUserPersonaPayload(int $uid): ?array
    {
        $p = $this->personaRepository->userActivePersona($uid);
        if ($p === null) {
            return null;
        }

        return [
            'id'              => (int) $p['id'],
            'name'            => (string) $p['name'],
            'layer'           => (int) $p['user_id'] === 0 ? 'system' : 'user',
            'selfcheck_on'    => (bool) $p['selfcheck_on'],
            'selfcheck_pass'  => (string) $p['selfcheck_pass'],
            'selfcheck_reply' => (string) $p['selfcheck_reply'],
        ];
    }

    /** DELETE /api/user/personas/{id} */
    public function userDestroy(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }
        if (!$this->personaInjector->gates()['user_enabled']) {
            return $this->customDisabled();
        }

        $id = (int) $request->routeParam('id', '0');
        if (!$this->personaRepository->ownedBy($id, $uid)) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $doomed = $this->personaRepository->find($id);
        $this->personaRepository->delete($id);

        Audit::ok('persona.delete', [
            'user_id'     => $uid,
            'target_type' => 'persona',
            'target_id'   => (string) $id,
            'summary'     => '删除本人思维「' . (string) ($doomed['name'] ?? ('#' . $id)) . '」',
        ]);

        return Response::json(['ok' => true, 'message' => '思维已删除']);
    }

    /** 普通用户自定义思维被管理员关闭时的统一拒绝（403） */
    private function customDisabled(): Response
    {
        return Response::json([
            'ok'    => false,
            'error' => [
                'message' => '管理员已关闭「普通用户自定义思维」，当前只能使用系统自带思维',
                'code'    => 'USER_PERSONA_DISABLED',
            ],
        ], 403);
    }

    /**
     * POST /api/user/personas/platform —— 普通用户自选在哪些平台接受注入
     *
     * 这是「用户级平台开关」，不依赖于「是否允许自定义思维」：即便管理员关掉了自定义，
     * 用户依然可以决定自己在哪些平台上接受系统自带思维。
     */
    public function userSetPlatform(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $payload = $request->json();
        $target  = trim((string) ($payload['target'] ?? ''));
        if ($target === '') {
            return Response::json(['ok' => false, 'error' => ['message' => '缺少 target']], 400);
        }
        // ⚠️ 靶点白名单必须按**本人可见范围**校验：用全局表的话，用户能把
        //    别人自建供应商（custom_u<他人uid>_xxx）当成合法平台开关来操作（2026-10-02 血案）。
        if (!in_array($target, $this->personaInjector->knownTargets($uid), true)) {
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '未知平台：' . $target . '（平台必须是已启用、且对你可见的供应商）'],
            ], 400);
        }

        $this->personaRepository->setUserTarget($uid, $target, !empty($payload['enabled']));

        Audit::ok('persona.platform', [
            'user_id'     => $uid,
            'target_type' => 'platform',
            'target_id'   => $target,
            'summary'     => (!empty($payload['enabled']) ? '开启' : '关闭') . '平台注入开关：' . $target,
            'detail'      => ['target' => $target, 'enabled' => !empty($payload['enabled'])],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => !empty($payload['enabled']) ? '已在该平台启用注入' : '已在该平台关闭注入',
            'data'    => ['platforms' => $this->personaInjector->platformStates($uid)],
        ]);
    }

    /**
     * POST /api/user/personas/enable —— 普通用户开启 / 关闭某一份思维
     *
     * **同一时间只能开启一份**（互斥单选）：开启新的会自动关掉之前那份；
     * 关闭当前那份 = 一份都不开（用户端默认状态）。
     *  - 自己的思维（user_id = 本人）→ 受「允许自定义」约束
     *  - 系统（全局）思维（user_id = 0）→ 与「允许自定义」正交
     */
    public function userSetEnable(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $payload = $request->json();
        $pid     = (int) ($payload['persona_id'] ?? 0);
        if ($pid <= 0) {
            return Response::json(['ok' => false, 'error' => ['message' => '缺少 persona_id']], 400);
        }

        $persona = $this->personaRepository->find($pid);
        if ($persona === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }

        $owner = (int) $persona['user_id'];
        if ($owner !== 0 && $owner !== $uid) {
            return Response::json(['ok' => false, 'error' => ['message' => '思维不存在']], 404);
        }
        if ($owner === $uid && !$this->personaInjector->gates()['user_enabled']) {
            return $this->customDisabled();
        }

        $enabled = !empty($payload['enabled']);

        // 互斥：开启这一份 = 把该用户其它所有思维（含全局的用户级开关）一并关掉
        $this->personaRepository->setUserActivePersona($uid, $pid, $enabled);

        $active = $this->personaRepository->userActivePersona($uid);

        Audit::ok('persona.enable', [
            'user_id'     => $uid,
            'target_type' => 'persona',
            'target_id'   => (string) $pid,
            'summary'     => $enabled
                ? ('开启思维「' . (string) $persona['name'] . '」（互斥：自动关闭其它）')
                : ('关闭思维「' . (string) $persona['name'] . '」'),
            'detail'      => [
                'persona'   => (string) $persona['name'],
                'layer'     => $owner === 0 ? 'system' : 'user',
                'enabled'   => $enabled,
                'active_id' => $active !== null ? (int) $active['id'] : 0,
            ],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $enabled
                ? ('已切换到「' . (string) $persona['name'] . '」（同时只能开启一份）')
                : ('已关闭「' . (string) $persona['name'] . '」'),
            'data'    => [
                'persona_id'   => $pid,
                'user_on'      => $enabled,
                'enabled_ids'  => $this->personaRepository->userEnabledIds($uid),
                'active_id'    => $active !== null ? (int) $active['id'] : 0,
                'active_name'  => $active !== null ? (string) $active['name'] : '',
            ],
        ]);
    }

    /** POST /api/user/personas/selfcheck —— 普通用户自测：给一段文本，看会不会命中口令 */
    public function userSelfcheck(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $text = (string) ($request->json()['text'] ?? '');
        $result = $this->personaInjector->selfcheck([['role' => 'user', 'content' => $text]], $uid);

        Audit::ok('persona.selfcheck', [
            'user_id'     => $uid,
            'target_type' => 'persona',
            'summary'     => '自证自测（口令' . (!empty($result['hit']) ? '命中' : '未命中') . '）',
            'detail'      => [
                'hit'  => !empty($result['hit']),
                'text' => mb_substr($text, 0, 120),
            ],
        ]);

        return Response::json([
            'ok'   => true,
            'data' => $result,
        ]);
    }

    /* ══════════════════════════════════════════════════════════
       内部
       ══════════════════════════════════════════════════════════ */

    /**
     * 取一份「全局」思维（管理台用）。
     *
     * ⚠️ 必须真正限定 `user_id = 0`：本方法名与注释都声明是「全局思维」，
     *    但曾经只做了 `find($id)` —— 等于任何 id 都能取到，管理台的
     *    改/删/还原/默认位在语义上会作用到普通用户的私建思维上。
     *    用户端走的是 `ownedBy()`，不受影响。
     */
    private function findGlobal(int $id): ?array
    {
        $persona = $this->personaRepository->find($id);
        if ($persona === null || (int) $persona['user_id'] !== 0) {
            return null;
        }
        return $persona;
    }

    /**
     * 列表项补充展示字段：靶点、封条是否一致
     *
     * @param int|null $scope 靶点矩阵的作用域：null=管理台（全部供应商）；uid=仅全局 + 本人可见
     */
    private function decorate(array $items, ?int $scope = null): array
    {
        $known = $this->personaInjector->knownTargets($scope);
        $out = [];

        foreach ($items as $persona) {
            $states = $this->personaRepository->targetStates($persona, $known);
            $persona['target_states'] = $states;
            $persona['target_on'] = count(array_filter($states, fn($s) => $s['enabled']));
            $persona['target_total'] = count($states);
            $persona['seal_ok'] = Seal::matches((string) $persona['content'], (string) $persona['seal']);
            $persona['seal_short'] = Seal::short((string) $persona['seal']);
            $persona['mode_label'] = $this->modeLabel((string) $persona['mode']);
            $out[] = $persona;
        }

        return $out;
    }

    /**
     * 校验入参并产出待写字段
     *
     * @param array    $payload
     * @param array    $data     出参：可写字段
     * @param bool     $partial  true=更新（允许缺字段）
     * @param int|null $scope    靶点白名单的作用域：null=管理台（全部供应商）；
     *                           普通用户必须传本人 uid，否则可把靶点写成他人的私有供应商
     * @return string|null 错误信息，null 表示通过
     */
    private function validate(array $payload, ?array &$data, bool $partial = false, ?int $scope = null): ?string
    {
        $data = [];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return '思维名称不能为空';
            }
            $data['name'] = $name;
        } elseif (!$partial) {
            return '思维名称不能为空';
        }

        if (array_key_exists('description', $payload)) {
            $data['description'] = (string) $payload['description'];
        }
        if (array_key_exists('content', $payload)) {
            $data['content'] = (string) $payload['content'];
        }
        if (array_key_exists('mode', $payload)) {
            $mode = trim((string) $payload['mode']);
            if (!in_array($mode, self::MODES, true)) {
                return '未知的注入位置：' . $mode;
            }
            $data['mode'] = $mode;
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }
        if (array_key_exists('is_default', $payload)) {
            $data['is_default'] = !empty($payload['is_default']);
        }
        if (array_key_exists('sort_order', $payload)) {
            $data['sort_order'] = (int) $payload['sort_order'];
        }
        if (array_key_exists('targets', $payload)) {
            $targets = $payload['targets'];
            if (is_string($targets)) {
                $decoded = json_decode($targets, true);
                $targets = is_array($decoded) ? $decoded : [];
            }
            $targets = is_array($targets) ? $targets : [];
            $known = $this->personaInjector->knownTargets($scope);
            foreach ($targets as $t) {
                if (!in_array((string) $t, $known, true)) {
                    return '未知靶点：' . (string) $t . '（靶点必须是已启用、且对你可见的供应商）';
                }
            }
            $data['targets'] = $targets;
        }
        if (array_key_exists('models', $payload)) {
            $models = $payload['models'];
            if (is_string($models)) {
                $decoded = json_decode($models, true);
                $models = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $models);
            }
            $models = is_array($models) ? $models : [];
            $clean = [];
            foreach ($models as $m) {
                $m = strtolower(trim((string) $m));
                if ($m === '') {
                    continue;
                }
                if (mb_strlen($m) > 128) {
                    return '模型标识过长（最多 128 字符）：' . mb_substr($m, 0, 40) . '…';
                }
                $clean[] = $m;
            }
            // 模型目录会随上游变化，故不做成员校验；支持尾部 * 前缀通配（如 gpt-4*）
            $data['models'] = array_values(array_unique($clean));
        }
        if (array_key_exists('selfcheck_on', $payload)) {
            $data['selfcheck_on'] = !empty($payload['selfcheck_on']);
        }
        if (array_key_exists('selfcheck_pass', $payload)) {
            $pass = trim((string) $payload['selfcheck_pass']);
            if ($pass !== '' && mb_strlen($pass) < 2) {
                return '自证口令太短（至少 2 个字符），容易在日常对话中误命中';
            }
            $data['selfcheck_pass'] = $pass;
        }
        if (array_key_exists('selfcheck_reply', $payload)) {
            $data['selfcheck_reply'] = (string) $payload['selfcheck_reply'];
        }
        if (array_key_exists('note', $payload)) {
            $data['note'] = (string) $payload['note'];
        }

        return null;
    }

    private function modeOptions(): array
    {
        return [
            ['value' => 'prepend', 'label' => '前置（思维在最前，原有 system 保留）'],
            ['value' => 'append',  'label' => '后置（思维在最后，原有 system 保留）'],
            ['value' => 'replace', 'label' => '替换（丢弃原有 system，只留思维）'],
        ];
    }

    private function modeLabel(string $mode): string
    {
        foreach ($this->modeOptions() as $opt) {
            if ($opt['value'] === $mode) {
                return $opt['label'];
            }
        }
        return $mode;
    }

    /**
     * 靶点下拉项：key + 展示名 + 当前是否启用
     *
     * @param int|null $scope null=管理台（全部）；uid=普通用户（仅全局 + 本人自建）
     */
    private function targetOptions(?int $scope = null): array
    {
        // ⚠️ 必须用 allVisible() 而不是 all()：all() 含所有人的 custom 实例，
        //    直接下发会把别人自建供应商的名字与 key 泄给普通用户（2026-10-02 血案）。
        $all = $this->providerRepository->allVisible($scope);
        $out = [];

        foreach ($this->personaInjector->knownTargets($scope) as $key) {
            $out[] = [
                'value'       => $key,
                'label'       => (string) ($all[$key]['display_name'] ?? $key),
                'login_type'  => (string) ($all[$key]['login_type'] ?? ''),
            ];
        }

        return $out;
    }

    /** 模型下拉项：当前已启用的模型 ID（用于「按模型精准开启」） */
    private function modelOptions(): array
    {
        return array_map(
            fn(string $id) => ['value' => $id, 'label' => $id],
            $this->personaInjector->knownModels()
        );
    }

    private function currentUserId(): ?int
    {
        $session = UserAuthMiddleware::current() ?? [];
        $uid = (int) ($session['id'] ?? 0);
        return $uid > 0 ? $uid : null;
    }
}
