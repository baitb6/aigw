<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 阿里云验证码 2.0 V3 —— 无痕验证求解器客户端（服务端）。
 *
 * 背景（实测自 ZCode 3.14.3 桌面端 app.asar，2026-09-30）：
 *   ZCode 的「领取额度包」走 `POST {zcode.z.ai}/api/v1/zcode-plan/billing/claim`，
 *   上游强制要求 `X-Aliyun-Captcha-Verify-Param` —— 这是阿里云验证码 2.0 V3 的
 *   业务验签串，**由浏览器现场求解**，服务端无法凭算法直接构造。
 *
 *   但 SDK 提供 `startTracelessVerification()`（**无痕验证**）：先做风控评分，
 *   正常浏览器环境**直接放行、零人工交互**，只有评分不足才降级为滑块。
 *   经实测（有头/无头 Edge 均可）稳定通过，且 param 具备：
 *     · 一次性（同一 param 二次提交 → 429 / F018）
 *     · 时效 90 秒（F019）
 *
 * ⚠️ 正因为 param **一次性**，本类**不跨请求缓存** —— 每次 solve() 都重新求解。
 *   （曾经加过 45 秒缓存，导致「国内账号用完、国际账号复用同一 param → 3007」，
 *    是一次真实踩坑。领取是低频主动操作，多花几秒换取正确性是值得的。）
 *
 * 实现：调 `tools/captcha-solver.js`（原生 CDP 驱动无头浏览器，自动起停）。
 *
 * ── 跨平台要点（Windows 开发机 / Linux 生产机）────────────────────────────────
 *   1. 命令组装：Windows 需要 `set NODE_PATH=... && node ...`（cmd 语法），
 *      Linux 直接用数组形式 proc_open（**不经 shell**）—— 后者顺带规避宝塔默认
 *      `disable_functions=proc_open` 之外还把 `shell_exec` 也禁掉的问题。
 *   2. Node 探测：Windows 用 `where`，Linux 依次探测常见绝对路径与 `command -v`。
 *   3. 浏览器路径不在此处写死：交给 captcha-solver.js 按 process.platform 自行探测，
 *      生产环境可用环境变量 `AIGW_BROWSER_BIN` 指定（推荐）。
 *
 * 用法：
 *   $param = CaptchaSolver::solve($prefix, $sceneId, $region);
 *   if ($param === '') { ...失败，提示人工... }
 */
class CaptchaSolver
{
    /** 求解总超时（秒）—— 含浏览器冷启动 + SDK 加载 + 无痕评分 */
    private const TIMEOUT = 75;

    /** 求解次数上限（无痕偶发超时，重试一次显著提升成功率） */
    private const ATTEMPTS = 2;

    /** @var int 上次求解耗时（秒，供诊断/日志用） */
    private static int $lastCost = 0;

    /**
     * 求解一个验证码 param。失败返回 ''（绝不抛异常，由调用方决定降级）。
     *
     * @param array $opts 透传给 Node 求解器，可含 headless/edgePath/port/profileDir
     */
    public static function solve(string $prefix, string $sceneId, string $region = 'cn', array $opts = []): string
    {
        if ($prefix === '' || $sceneId === '') {
            return '';
        }

        $t0 = microtime(true);

        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $param = self::invoke($prefix, $sceneId, $region, $opts);
            if ($param !== '') {
                self::$lastCost = (int) round(microtime(true) - $t0);
                return $param;
            }
            if ($i + 1 < self::ATTEMPTS) {
                // 无痕偶发超时（风控抖动/浏览器冷启动慢），换端口重试一次
                Logger::warn('验证码求解第 ' . ($i + 1) . ' 次失败，重试…');
                usleep(600000);
            }
        }

        self::$lastCost = (int) round(microtime(true) - $t0);
        return '';
    }

    /** 上次求解耗时（秒） */
    public static function lastCost(): int
    {
        return self::$lastCost;
    }

    /** 兼容旧接口（缓存已移除，此方法仅保证调用方不炸） */
    public static function reset(): void
    {
        self::$lastCost = 0;
    }

    /**
     * 调用 Node 求解器。
     * @return string param，失败 ''
     */
    private static function invoke(string $prefix, string $sceneId, string $region, array $opts): string
    {
        $root  = defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2);
        $js    = $root . '/tools/captcha-solver.js';
        if (!is_file($js)) {
            Logger::warn('captcha-solver.js 不存在：' . $js);
            return '';
        }

        $node = self::nodeBinary();
        if ($node === '') {
            Logger::warn('未找到 Node 运行时，无法求解验证码');
            return '';
        }

        $tmpDir = $root . '/storage/tmp/captcha';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $uniq = bin2hex(random_bytes(6));
        $in   = $tmpDir . '/solve-in-' . $uniq . '.json';
        $out  = $tmpDir . '/solve-out-' . $uniq . '.json';

        $payload = array_merge([
            'prefix'  => $prefix,
            'sceneId' => $sceneId,
            'region'  => $region,
            'port'    => self::pickPort(),
        ], $opts);

        if (file_put_contents($in, json_encode($payload, JSON_UNESCAPED_UNICODE)) === false) {
            return '';
        }

        try {
            $cmd = self::buildCommand($node, $js, $in, $out);
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $extraEnv = self::procEnv();
            $proc = proc_open($cmd, $descriptors, $pipes, $root, $extraEnv);
            if (!is_resource($proc)) {
                Logger::warn('验证码求解进程启动失败（proc_open 可能被 disable_functions 禁用）');
                return '';
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = time() + self::TIMEOUT;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                if (time() > $deadline) {
                    proc_terminate($proc, 9);
                    Logger::warn('验证码求解超时（' . self::TIMEOUT . 's）');
                    break;
                }
                usleep(150000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        } finally {
            @unlink($in);
        }

        $raw = @file_get_contents($out);
        @unlink($out);
        if (!is_string($raw) || $raw === '') {
            Logger::warn('验证码求解无输出');
            return '';
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['ok']) || empty($json['param'])) {
            Logger::warn('验证码求解失败：' . mb_substr($raw, 0, 300));
            return '';
        }

        return trim((string) $json['param']);
    }

    /**
     * 组装 proc_open 的命令。
     *
     * - Linux/macOS：返回**数组**，proc_open 会直接 execve，不经 shell。
     *   这既避免了 shell 转义问题，也不会触碰 `shell_exec` / `exec` 等被禁用函数。
     * - Windows：返回字符串，且必须先 `set "NODE_PATH=..."` 再跑 node。
     *
     * @return array<int,string>|string
     */
    private static function buildCommand(string $node, string $js, string $in, string $out)
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            // 数组形式：元素原样作为 argv，无需引号包裹
            return [$node, $js, $in, $out];
        }

        $nodeModules = self::nodeModules();
        $prefix = $nodeModules !== '' ? 'set "NODE_PATH=' . $nodeModules . '" && ' : '';

        // Windows 不经 procEnv()，显示模式变量得在这里用 cmd 的 set 语法带上
        // （Windows 本就有桌面，通常用不到；但支持显式覆盖以便复现/调试）
        foreach (['AIGW_CAPTCHA_HEADLESS', 'AIGW_XVFB_DISPLAY'] as $k) {
            $v = self::envString($k);
            if ($v !== '') {
                $prefix .= 'set "' . $k . '=' . $v . '" && ';
            }
        }

        return $prefix . sprintf(
            '"%s" "%s" "%s" "%s"',
            $node,
            $js,
            $in,
            $out
        );
    }

    /**
     * 传给子进程的环境变量。
     * 非 Windows 下显式把 NODE_PATH 塞进 env（代替 cmd 的 set 语法）。
     */
    private static function procEnv(): ?array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null; // Windows 走 buildCommand 里的 `set`
        }

        $env = [];
        // 透传继承当前进程环境（PHP 不会自动带上，需显式读取）
        foreach (['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'DISPLAY'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $env[$k] = (string) $v;
            }
        }

        // ── 显示模式相关（2026-10-02 F001 整改）────────────────────────────
        // 阿里云风控会识别**无头**指纹并直接判 F001，故求解器默认改跑「有头」。
        // Linux 服务器无物理显示，需借助 Xvfb；下面两个变量必须显式透传，
        // 否则 .env 里配了也进不了子进程（`.env` 只进 `$_ENV`，getenv 读不到，
        // 见约定 J —— 故一律走 envString()）。
        foreach (['AIGW_XVFB_DISPLAY', 'AIGW_CAPTCHA_HEADLESS'] as $k) {
            $v = self::envString($k);
            if ($v !== '') {
                $env[$k] = $v;
            }
        }

        $nodeModules = self::nodeModules();
        if ($nodeModules !== '') {
            $env['NODE_PATH'] = $nodeModules;
        }

        // 浏览器可执行文件：生产环境推荐通过 AIGW_BROWSER_BIN 指定
        // （.env 只进 $_ENV，必须走 envString 而不是裸 getenv）
        $browser = self::envString('AIGW_BROWSER_BIN');
        if ($browser !== '') {
            $env['AIGW_BROWSER_BIN'] = $browser;
        }

        return $env;
    }

    /** 选一个空闲端口给无头浏览器，避免多请求并发时抢占 */
    private static function pickPort(): int
    {
        for ($i = 0; $i < 20; $i++) {
            $port = random_int(9400, 9900);
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
            if ($conn === false) {
                return $port;
            }
            fclose($conn);
        }

        return 9333;
    }

    /**
     * 读取环境变量（字符串形态）。
     *
     * ⚠️ 必须同时查 `$_ENV` / `$_SERVER` —— `Config::loadEnvFile()` **只把 .env 灌进 `$_ENV`，
     * 从不调用 putenv()**，所以 `getenv('AIGW_BROWSER_BIN')` 在写了 .env 的机器上**永远返回 false**。
     * （PHP-FPM 池里的 `env[XXX]` 才会进 getenv()；两条配置路径都得认。）
     * 曾经的表现：.env 明明写了浏览器路径，却仍报「未找到 Chromium 系浏览器」。
     */
    private static function envString(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($v) ? trim($v) : '';
    }

    /**
     * 从一个候选路径读出 Node 版本（major*1000+minor），失败返回 -1。
     */
    private static function nodeVersionScore(string $path): int
    {
        // 注意：重定向语法 Windows 与 *nix 不同，不能用同一串
        $null = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
        $out = @shell_exec(escapeshellarg($path) . ' --version ' . $null);
        if (!is_string($out) || !preg_match('/v(\d+)\.(\d+)/', $out, $m)) {
            return -1;
        }
        return (int) $m[1] * 1000 + (int) $m[2];
    }

    /**
     * 定位 Node 可执行文件。
     *
     * 优先级：
     *   1. `AIGW_NODE_BIN`（显式配置，最高）
     *   2. **要求满足 ≥ 20.10 的版本下限**（求解器依赖全局 WebSocket，它自 v21.0.0/v20.10.0 才加入）
     *   3. 常见绝对路径 → PATH 兜底
     *   4. ★ 按**版本从高到低**取最优（而非取第一个存在的）
     *
     * ⚠️ 第 4 条是 CentOS 7 实战踩出来的：这类机器上 `PATH` 里排第一的常是
     *    宝塔的 `/www/server/nodejs/v16.20.2/bin/node`（v16 **没有 WebSocket**）。
     *    若「取第一个存在的」就会把 v16 当可用，装了 node22 也照样失败。
     *    所以候选拿到后必须逐个读版本，挑最高的那个。
     */
    private static function nodeBinary(): string
    {
        // 1) 显式配置：信任用户，但仍要能跑
        $env = self::envString('AIGW_NODE_BIN');
        if ($env !== '' && @is_executable($env)) {
            return $env;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Users\\Administrator\\.workbuddy\\binaries\\node\\versions\\22.22.2-3\\node.exe',
                'C:\\Program Files\\nodejs\\node.exe',
            ]
            : [
                // 本项目推荐位置（glibc-217 构建装在这里，见 docs/部署-centos7-宝塔.md §8.1）
                '/usr/local/nodejs/bin/node',
                // 手装 tarball 的常见落点
                '/usr/local/bin/node',
                '/usr/local/node/bin/node',
                '/opt/nodejs/bin/node',
                '/opt/node/bin/node',
                // 系统包管理器
                '/usr/bin/node',
                '/usr/bin/nodejs',
                // ⚠️ 宝塔自带的常是 v16（无 WebSocket），放最后 —— 版本低于 20.10 会被拒绝
                '/www/server/nodejs/bin/node',
            ];

        // 收集所有真实存在的候选（含 PATH 里的，PATH 可能有多个 node）
        $found = [];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                $found[$p] = true;
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            // `which -a` 列出 PATH 中所有命中，避免只看第一个
            $which = @shell_exec('which -a node nodejs 2>/dev/null');
            if (is_string($which)) {
                foreach (preg_split('/[\r\n]+/', $which) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && is_file($line)) {
                        $found[$line] = true;
                    }
                }
            }
        } else {
            $which = @shell_exec('where node 2>NUL');
            if (is_string($which)) {
                foreach (preg_split('/[\r\n]+/', $which) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && is_file($line)) {
                        $found[$line] = true;
                    }
                }
            }
        }
        // 按版本择优：满足 ≥20.10 的取最高；否则退回「存在的最高版本」（上层会给出明确报错）
        $best = '';
        $bestScore = -1;
        $fallback = '';
        $fallbackScore = -1;
        foreach (array_keys($found) as $p) {
            $score = self::nodeVersionScore($p);
            if ($score < 0) {
                // 跑不起来的（缺 glibc 等）跳过，不选它
                continue;
            }
            $ok = $score >= 20010; // v20.10 及以上
            if ($ok && $score > $bestScore) {
                $best = $p;
                $bestScore = $score;
            }
            if ($score > $fallbackScore) {
                $fallback = $p;
                $fallbackScore = $score;
            }
        }

        if ($best !== '') {
            return $best;
        }

        // 没有合格的：把存在过的最高版本返回去，让上层报「缺少 WebSocket」而不是「找不到 node」
        return $fallback;
    }

    /**
     * Node 依赖目录（NODE_PATH）。
     * 本项目求解器**不依赖任何 npm 包**（纯内置模块），因此这里通常返回空；
     * 保留是为了兼容将来引入依赖的场景。
     */
    private static function nodeModules(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $p = 'C:\\Users\\Administrator\\.workbuddy\\binaries\\node\\workspace\\node_modules';
            return is_dir($p) ? $p : '';
        }

        $env = self::envString('AIGW_NODE_PATH');
        if ($env !== '' && is_dir($env)) {
            return $env;
        }

        return '';
    }
}
