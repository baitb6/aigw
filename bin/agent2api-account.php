<?php
/**
 * 给 Agent2API 供应商写入一个可用账号（Base URL + 网关 Key + 可选面板凭据）
 *
 * 用法：
 *   php bin/agent2api-account.php --key=sk-a2a-xxx [--provider=agent2api]
 *       [--base=http://127.0.0.1:3065] [--panel-user=admin] [--panel-pass=******]
 */
declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Core\Application;
use App\Repositories\AccountRepository;

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $arg = substr($arg, 2);
    [$k, $v] = array_pad(explode('=', $arg, 2), 2, '1');
    $args[$k] = $v;
}

if (isset($args['help']) || empty($args['key'])) {
    echo "用法: php bin/agent2api-account.php --key=sk-a2a-xxx [--provider=agent2api] [--base=http://127.0.0.1:3065] [--panel-user=admin] [--panel-pass=***]\n";
    exit(empty($args['key']) ? 1 : 0);
}

$provider = (string) ($args['provider'] ?? 'agent2api');
$base     = rtrim((string) ($args['base'] ?? 'http://127.0.0.1:3065'), '/');
$key      = (string) $args['key'];

$cred = [
    'baseUrl'   => $base,
    'apiKey'    => $key,
    'panelUser' => (string) ($args['panel-user'] ?? ''),
    'panelPassword' => (string) ($args['panel-pass'] ?? ''),
];

$app = new Application(AIGW_ROOT);
$repo = $app->container()->make(AccountRepository::class);

$uid = 'gateway:' . preg_replace('#^https?://#', '', $base);
$id  = $repo->upsertCredential($provider, $uid, $cred, ['via' => 'cli'], 'Agent2API 网关');

echo "\n[OK] 账号 #{$id} 已写入\n";
echo "     供应商: {$provider}\n";
echo "     地址:   {$base}\n";
echo "     Key:    " . substr($key, 0, 12) . "…\n";
echo "     面板:   " . ($cred['panelUser'] !== '' ? $cred['panelUser'] : '（未配置，签到/额度按钮不可用）') . "\n";
echo "\n下一步：控制台「供应商」页应能看到可用账号，直接开对话即可。\n\n";
