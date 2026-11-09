<?php
/**
 * 安装脚本：建库、建表、初始化数据、生成管理员与 API Key
 * 用法：php database/install.php [--force]
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

if (PHP_SAPI !== 'cli') {
    exit('请通过命令行执行：php database/install.php');
}

require AIGW_ROOT . '/bootstrap/autoload.php';

\App\Core\Config::load(AIGW_ROOT . '/config');

$force = in_array('--force', $argv, true);

echo "\n\033[36m=== AI 聚合网关 · 安装 ===\033[0m\n\n";

/**
 * 把 SQL 脚本切分为可执行语句。
 *
 * 必须用状态机，不能 explode(';')，否则会踩三类坑：
 *  1. 注释里的分号会把一条语句拦腰切断
 *     （schema.sql 第 593 行 "-- 还原自 Apk Lc/m;" 就是这么炸的）；
 *  2. 字符串 / 反引号标识符里的分号同理；
 *  3. 注释与 INSERT 紧邻时，无法只用正则「整行剔除」——会把紧跟其后的
 *     真实语句一起丢掉（曾导致 aigw_gateway_settings 种子行静默消失，
 *     进而 install.php 后面 UPDATE ... WHERE id=1 影响 0 行，API Key 从未入库）。
 *
 * @return list<string> 已剥离注释、剔除空白后的有效语句
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = ($i + 1) < $len ? $sql[$i + 1] : '';

        // 行注释 `--`（后跟空白才构成注释，与 MySQL 语义一致）
        if ($ch === '-' && $next === '-') {
            $after = ($i + 2) < $len ? $sql[$i + 2] : "\n";
            if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
        }

        // 行注释 `#`
        if ($ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // 块注释 /* ... */
        if ($ch === '/' && $next === '*') {
            $i += 2;
            while ($i < $len && !($sql[$i] === '*' && ($i + 1) < $len && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i += 2; // 跳过收尾的 */
            continue;
        }

        // 引号字符串 / 反引号标识符：整体吞噬，内部的分号不作分隔
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buf .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                // 反斜杠转义（反引号内不适用）
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $c . (($i + 1) < $len ? $sql[$i + 1] : '');
                    $i += 2;
                    continue;
                }
                $buf .= $c;
                $i++;
                if ($c === $quote) {
                    // 双写引号（'' / ""）是转义，仍在字符串内
                    if ($i < $len && $sql[$i] === $quote) {
                        $buf .= $sql[$i];
                        $i++;
                        continue;
                    }
                    break;
                }
            }
            continue;
        }

        // 语句结束
        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buf = '';
            $i++;
            continue;
        }

        $buf .= $ch;
        $i++;
    }

    $tail = trim($buf);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

// ---------- 1. 建立连接（不指定库名，库可能还不存在）----------
$host = (string) config('database.host');
$port = (int) config('database.port');
$dbName = (string) config('database.database');
$user = (string) config('database.username');
$pass = (string) config('database.password');
$prefix = (string) config('database.prefix', 'aigw_');

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    exit("\033[31m[×] 数据库连接失败：{$e->getMessage()}\033[0m\n");
}

echo "\033[32m[✓]\033[0m 数据库连接成功 ({$host}:{$port})\n";

// ---------- 2. 建库 ----------
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");
echo "\033[32m[✓]\033[0m 数据库就绪：{$dbName}\n";

// ---------- 3. 建表 ----------
$existing = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$dbName}' AND table_name='{$prefix}provider'")->fetchColumn();

if ($existing && !$force) {
    echo "\033[33m[!]\033[0m 表结构已存在，跳过建表（如需重建请加 --force，会清空数据）\n";
} else {
    /**
     * 🔴 数据保护闸门（2026-10-01 血案：`--force` 静默 drop 全部业务表）
     * ------------------------------------------------------------------
     * schema.sql 每张表前都是 `DROP TABLE IF EXISTS`，所以 `--force` 的真实语义
     * 不是「升级表结构」而是「**清空整库**」。当天有人在有数据的库上跑了一次
     * `php database/install.php --force`，23 张业务表被 drop 重建为空，
     * 表内 7500+ 行业务数据（思维/账号/调用日志/审计）全部消失，且**没有任何提示**。
     *
     * 所以：库里有业务数据时，`--force` 单独一个参数不再足以触发重建，
     * 必须再显式带上环境变量 `AIGW_ALLOW_DESTRUCTIVE=1`（相当于二次确认）。
     * 纯新建库（表还不存在）不受影响，照旧直接建。
     */
    if ($existing && $force) {
        $rows = 0;
        foreach (['user', 'persona', 'call_log', 'account'] as $probe) {
            $t = $prefix . $probe;
            try {
                $rows += (int) $pdo->query("SELECT COUNT(*) FROM `{$dbName}`.`{$t}`")->fetchColumn();
            } catch (PDOException $e) {
                // 表不存在（首次安装）或权限不足：不计入
            }
        }

        $allowed = getenv('AIGW_ALLOW_DESTRUCTIVE') === '1';

        if ($rows > 0 && !$allowed) {
            fwrite(STDERR, "\n\033[31m[×] 拒绝执行：目标库 `{$dbName}` 内已有 {$rows} 行业务数据。\033[0m\n");
            fwrite(STDERR, "    本次 `--force` 会 DROP 全部表并重建为空 —— 这是**不可逆的数据清空**。\n\n");
            fwrite(STDERR, "    先备份：\n");
            fwrite(STDERR, "      mysqldump -u{$user} -p {$dbName} > backup-{$dbName}-" . date('Ymd-His') . ".sql\n\n");
            fwrite(STDERR, "    确认要清空、再执行：\n");
            fwrite(STDERR, "      AIGW_ALLOW_DESTRUCTIVE=1 php database/install.php --force\n\n");
            exit(1);
        }

        if ($rows > 0 && $allowed) {
            echo "\033[31m[!]\033[0m 已确认清空：库 `{$dbName}` 将被 DROP 重建（{$rows} 行业务数据将丢失）\n";
        }
    }

        $sql = file_get_contents(AIGW_ROOT . '/database/schema.sql');
    if ($sql === false) {
        exit("\033[31m[×] 无法读取 database/schema.sql\033[0m\n");
    }

    // 逐条执行（SQL 拆分交给状态机，正确处理注释与引号内的分号）
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $statements = splitSqlStatements($sql);

    $failed = [];
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $head = substr(preg_replace('/\s+/', ' ', $stmt) ?? '', 0, 120);
            $failed[] = $head . '  →  ' . $e->getMessage();
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    if ($failed !== []) {
        echo "\033[31m[×]\033[0m 有 " . count($failed) . " 条语句执行失败：\n";
        foreach ($failed as $f) {
            echo "    · {$f}\n";
        }
        exit("\033[31m[×] 安装中止：建表未全部成功，请修复后重试\033[0m\n");
    }

    echo "\033[32m[✓]\033[0m 表结构创建完成（共 " . count($statements) . " 条语句）\n";
}

// ---------- 4. 管理员初始化 ----------
// 口令不写死在源码里：优先读环境变量，未提供时随机生成一次并在本次输出中打印。
$adminUser = (string) (getenv('AIGW_INSTALL_ADMIN_USERNAME') ?: 'admin');
$adminPass = (string) (getenv('AIGW_INSTALL_ADMIN_PASSWORD') ?: '');
if ($adminPass === '') {
    $adminPass = bin2hex(random_bytes(10));
    echo "\033[33m[!]\033[0m 未提供 AIGW_INSTALL_ADMIN_PASSWORD，已随机生成口令\n";
}
$hash = password_hash($adminPass, PASSWORD_BCRYPT);
$pdo->prepare("UPDATE `{$prefix}admin` SET `password` = ? WHERE `username` = ?")->execute([$hash, $adminUser]);
$pdo->prepare("INSERT IGNORE INTO `{$prefix}admin` (`username`,`password`,`nickname`) VALUES (?, ?, '管理员')")->execute([$adminUser, $hash]);
echo "\033[32m[✓]\033[0m 管理员账号：\033[36m{$adminUser} / {$adminPass}\033[0m（请登录后立即修改）\n";

// ---------- 5. 生成网关 API Key ----------
// 先确保 id=1 这一行存在，否则下面的 UPDATE 会静默影响 0 行、Key 根本不入库
$pdo->exec("INSERT IGNORE INTO `{$prefix}gateway_settings` (`id`) VALUES (1)");

$current = $pdo->query("SELECT `api_key` FROM `{$prefix}gateway_settings` WHERE `id` = 1")->fetchColumn();
if (!$current) {
    $current = 'sk-aigw-' . bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE `{$prefix}gateway_settings` SET `api_key` = ? WHERE `id` = 1")->execute([$current]);
}

// 校验确实写进去了（防止未来再出现「显示了一个没入库的 Key」）
$verify = $pdo->query("SELECT `api_key` FROM `{$prefix}gateway_settings` WHERE `id` = 1")->fetchColumn();
if ($verify !== $current) {
    exit("\033[31m[×] API Key 写入失败：网关设置表缺少 id=1 记录，请检查建表是否完整\033[0m\n");
}
echo "\033[32m[✓]\033[0m 网关 API Key：\033[36m{$current}\033[0m\n";

// ---------- 6. 初始化 .env（如缺失密钥）----------
$envFile = AIGW_ROOT . '/.env';
if (!is_file($envFile)) {
    $key = \App\Support\Crypto::generateKey();
    $tpl = file_get_contents(AIGW_ROOT . '/.env.example') ?: '';
    // 整行替换，避免模板里的占位说明残留在值末尾
    $tpl = (string) preg_replace('/^AIGW_APP_KEY=.*$/m', 'AIGW_APP_KEY=' . $key, $tpl);
    file_put_contents($envFile, $tpl);
    echo "\033[32m[✓]\033[0m 已生成 .env 与加密主密钥\n";
}

echo "\n\033[32m安装完成。\033[0m 访问 \033[36m" . config('app.url') . "\033[0m 进入管理台\n\n";