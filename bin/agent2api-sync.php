<?php
/**
 * Agent2API 网关 → AIGW 供应商目录同步（CLI）
 *
 * 用法：
 *   php bin/agent2api-sync.php \
 *       --base=http://127.0.0.1:3065 \
 *       --key=a2a_xxx \
 *       --panel-user=admin --panel-pass=****** \
 *       --mode=split            # split（默认，一个 provider 一行）| aggregate（全部合成一行）
 *
 *   php bin/agent2api-sync.php --help
 *
 * 干什么：
 *   1) 连上网关，读 /v1/models（模型目录）与 /api/plugins（两家插件合并后的 provider 目录）；
 *   2) 按 --mode 建/更新 AIGW 的供应商行：
 *        aggregate → 单行 `agent2api`，网关所有模型都挂在它下面；
 *        split     → 网关里每个 provider 各一行 `agent2api_<provider>`，
 *                    控制台里就能像内置供应商那样单独开关、单独定价；
 *   3) 把模型写进 aigw_model（复用 ModelRepository::syncCatalog，保留已有开关与倍率）。
 *
 * 为什么要 CLI 而不是后台点按钮：
 *   网关的 provider 清单随插件升级而变（3.4.x 时是 37 个），逐个手点容易漏；
 *   本脚本幂等，插件升级后重跑一次即可对齐。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/agent2api-sync.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Core\Application;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Support\Agent2ApiClient;

// ---------------------------------------------------------
// 参数
// ---------------------------------------------------------

/** @return array<string,string> */
function a2a_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = '1';
        }
    }

    return $out;
}

$args = a2a_args($argv);

if (isset($args['help'])) {
    echo <<<TXT
Agent2API → AIGW 供应商同步

  --base=URL          网关地址（默认 http://127.0.0.1:3065）
  --key=KEY           网关 Key（模型调用用；留空则只同步目录，调用会被网关拒）
  --panel-user=USER   面板账号（可选；填了才能读账号池/额度/签到）
  --panel-pass=PASS   面板密码
  --mode=split        同步模式：split（默认，逐 provider 建行）/ aggregate（合成一行）
  --prefix=agent2api  供应商 key 前缀
  --dry-run           只打印将要写入的内容，不落库

TXT;
    exit(0);
}

$base       = (string) ($args['base'] ?? 'http://127.0.0.1:3065');
$apiKey     = (string) ($args['key'] ?? '');
$panelUser  = (string) ($args['panel-user'] ?? '');
$panelPass  = (string) ($args['panel-pass'] ?? '');
$mode       = strtolower((string) ($args['mode'] ?? 'split')) === 'aggregate' ? 'aggregate' : 'split';
$prefix     = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($args['prefix'] ?? 'agent2api'))) ?: 'agent2api';
$dryRun     = isset($args['dry-run']);

// ---------------------------------------------------------
// 基础设施
// ---------------------------------------------------------

try {
    $app = new Application(AIGW_ROOT);
    $c   = $app->container();

    /** @var ProviderRepository $providers */
    $providers = $c->make(ProviderRepository::class);
    /** @var ModelRepository $models */
    $models = $c->make(ModelRepository::class);
} catch (\Throwable $e) {
    fwrite(STDERR, '[agent2api-sync] 启动失败：' . $e->getMessage() . "\n");
    exit(1);
}

$client = Agent2ApiClient::fromCredential([
    'baseUrl'       => $base,
    'apiKey'        => $apiKey,
    'panelUser'     => $panelUser,
    'panelPassword' => $panelPass,
]);

/**
 * 供应商 key 规范化
 *
 * aigw_provider.provider_key 是 VARCHAR(32)，超长会被 MySQL 静默截断，
 * 截断后两个 provider 撞车就会互相覆盖 —— 所以这里主动收敛长度。
 */
function a2a_key(string $prefix, string $provider): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($provider)) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'unknown';
    }

    $room = 32 - strlen($prefix) - 1;

    return $prefix . '_' . substr($slug, 0, max(1, $room));
}

/** @param list<array<string,mixed>> $items */
function a2a_catalog(array $items, string $providerKey): array
{
    $out = [];
    foreach ($items as $item) {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $lower = strtolower($id);
        $out[] = [
            'model_id'       => $id,
            'display_name'   => (string) ($item['name'] ?? $item['display_name'] ?? $id),
            'multimodal'     => (bool) preg_match('/vision|vl\b|4o|omni/i', $lower),
            'reasoning'      => (bool) preg_match('/reason|think|r1\b|glm-5/i', $lower),
            'capabilities'   => ['chat', 'stream', 'tools'],
            'context_window' => (int) ($item['context_window'] ?? 0),
            'max_tokens'     => (int) ($item['max_tokens'] ?? 0),
            'meta'           => ['provider_key' => $providerKey],
        ];
    }

    return $out;
}

// ---------------------------------------------------------
// 拉网关数据
// ---------------------------------------------------------

echo "→ 连接网关 {$base}\n";

try {
    $health = $client->health();
    if (!$health['ok']) {
        fwrite(STDERR, "[agent2api-sync] 网关健康检查失败，HTTP {$health['status']}\n");
        exit(1);
    }

    $allModels = $client->models();
    echo '  模型目录：' . count($allModels) . " 条\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[agent2api-sync] 读取模型目录失败：' . $e->getMessage() . "\n");
    exit(1);
}

// provider 目录：插件装了才有；没有就让 owned_by 兜底
$providerNames = [];
try {
    if ($client->hasPanelCredential()) {
        $plugins = $client->plugins();
        foreach ((array) ($plugins['providerDocs'] ?? []) as $doc) {
            if (is_array($doc) && isset($doc['key'])) {
                $providerNames[(string) $doc['key']] = (string) ($doc['name'] ?? $doc['title'] ?? $doc['key']);
            }
        }
        foreach ((array) ($plugins['packs'] ?? []) as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            foreach ((array) ($pack['host']['providers'] ?? []) as $key) {
                $key = (string) $key;
                $providerNames[$key] ??= $key;
            }
        }
        echo '  插件 provider 目录：' . count($providerNames) . " 条\n";
    } else {
        echo "  （未提供面板账号，跳过插件目录，按模型 owned_by 分组）\n";
    }
} catch (\Throwable $e) {
    echo '  ⚠ 读取插件目录失败（继续按 owned_by 分组）：' . $e->getMessage() . "\n";
}

// ---------------------------------------------------------
// 分组
// ---------------------------------------------------------

/** @var array<string, list<array<string,mixed>>> $groups provider => models */
$groups = [];
foreach ($allModels as $item) {
    $ownedBy = (string) ($item['owned_by'] ?? '');
    $groups[$ownedBy !== '' ? $ownedBy : 'gateway'][] = $item;
}

$options = static function (string $upstream) use ($base, $apiKey, $panelUser, $panelPass): array {
    $o = [
        'base_url'  => rtrim($base, '/'),
        'api_keys'  => $apiKey !== '' ? [$apiKey] : [],
        'models'    => [],
    ];
    if ($panelUser !== '') {
        $o['panel_user']     = $panelUser;
        $o['panel_password'] = $panelPass;
    }
    if ($upstream !== '') {
        $o['upstream_provider'] = $upstream;
    }

    return $o;
};

/** @var list<array{key:string,name:string,upstream:string,catalog:list<array<string,mixed>>}> $plan */
$plan = [];

if ($mode === 'aggregate') {
    $plan[] = [
        'key'      => $prefix,
        'name'     => 'Agent2API 网关',
        'upstream' => '',
        'catalog'  => a2a_catalog($allModels, $prefix),
    ];
} else {
    foreach ($groups as $upstream => $items) {
        $key = a2a_key($prefix, (string) $upstream);
        $plan[] = [
            'key'      => $key,
            'name'     => 'Agent2API · ' . ($providerNames[(string) $upstream] ?? (string) $upstream),
            'upstream' => (string) $upstream,
            'catalog'  => a2a_catalog($items, $key),
        ];
    }
}

// ---------------------------------------------------------
// 落库
// ---------------------------------------------------------

$totalCreated = 0;
$totalUpdated = 0;

foreach ($plan as $row) {
    $exists = $providers->find($row['key']) !== null;
    printf(
        "  %-28s %-2s 模型 %2d 个  %s\n",
        $row['key'],
        $exists ? '更新' : '新建',
        count($row['catalog']),
        $row['name']
    );

    if ($dryRun) {
        continue;
    }

    try {
        $opts = $options($row['upstream']);
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: '');

        if ($exists) {
            $providers->update($row['key'], [
                'display_name' => $row['name'],
                'options'      => $opts,
                'hosts'        => $host !== '' ? [$host] : [],
            ]);
        } else {
            $providers->createCustom($row['key'], $row['name'], $opts);
            $providers->update($row['key'], ['hosts' => $host !== '' ? [$host] : []]);
        }

        $stat = $models->syncCatalog($row['key'], $row['catalog']);
        $totalCreated += (int) ($stat['created'] ?? 0);
        $totalUpdated += (int) ($stat['updated'] ?? 0);
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("  ✗ %s 写入失败：%s\n", $row['key'], $e->getMessage()));
    }
}

echo "\n";

if ($dryRun) {
    echo "（--dry-run：未写入数据库）\n";
    exit(0);
}

printf(
    "完成：供应商 %d 个，模型新增 %d / 更新 %d\n",
    count($plan),
    $totalCreated,
    $totalUpdated
);
echo "下一步：控制台「供应商」页给每个 Agent2API 行粘贴账号（Base URL + 网关 Key），或直接编辑 aigw_account。\n";
