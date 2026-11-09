<?php
/**
 * 后台进程启动器 —— 把长耗时任务从 HTTP 请求里摘出去。
 *
 * 背景（2026-10-02 线上 524 血案）：
 *   线上站点前面挂 CDN（腾讯云 EdgeOne），「回源超时」**默认仅 15 秒**。
 *   ZCode「领取额度」链路要跑「拉配置 + 无痕验证求解 9~75s + claim」，
 *   必然超过回源超时 → CDN 掐断回源连接 → 客户端看到 **524**，
 *   而源站 PHP 其实还在跑、甚至最终成功（用户的体感是「报错但额度到了」）。
 *
 * 本类负责：用 proc_open **脱离父进程**启动 `bin/action-worker.php <task_id>`，
 * 让任务在独立 PHP-CLI 进程里跑完 —— 不受 CDN / FPM / 请求生命周期约束。
 *
 * ── 跨平台要点 ───────────────────────────────────────────────────────────
 *   · Windows：字符串命令 + `start /B`，并用 NUL 重定向避免句柄继承阻塞父进程
 *   · Linux：字符串命令（走 /bin/sh -c），**一律用 shell 的 `&` 后台化**，
 *            再按可用性加 `setsid` / `nohup` 前缀做脱离保险。
 *   · 父进程**不等待**子进程：拿到句柄后立刻关闭管道并返回，避免 Web 请求被拖住
 *
 * ⚠️ 若 `proc_open` 被禁用（宝塔默认禁），会自动降级为「提示前端用同步路径」，
 *    绝不静默失败 —— 静默失败是本项目反复咬人的一类坑（见 docs/运维速查表.md 约定 C）。
 *
 * ── ★ 2026-10-02 二修：`setsid -f` 在 CentOS 7 上会直接失败 ────────────────
 *   线上诊断出现「proc_open OK / PHP-CLI OK / setsid OK，但 worker 就是起不来」。
 *   真因：**`setsid -f`（= `--fork`）是 util-linux 2.24（2013-10-21）才新增的选项**，
 *   而 CentOS 7 自带的 util-linux 是 **2.23.2**。2.23.2 的 setsid.c 里
 *   getopt 串为 `"+Vhc"`、longopts 只有 ctty/version/help —— **不认 `-f`**。
 *   于是 `setsid -f php ...` 会打印 usage 到 stderr 后 **exit(1)**，
 *   `php` 从未 `execvp` → worker 根本没跑；而 sh 只是「成功 exec 了 setsid」，
 *   故 `proc_start` 恒返回 true → 任务永远 PENDING。
 *
 *   修法（去版本依赖，不赌任何发行版）：
 *     · **不再使用 `setsid -f`**；改用 **shell `&` 后台化** 保证「父进程立即返回」，
 *       这一步与 setsid 版本无关，是 POSIX 语义，绝对可靠。
 *     · 在此之上按可用性叠加脱离保险：`setsid`（**不带 -f**，2.23 也认）→ `nohup` → 裸 `&`。
 *     · 启动命令行与所选 launcher **写进 worker 日志**，让「起不来」当场可见。
 */

declare(strict_types=1);

namespace App\Support;

class BackgroundProcess
{
    /**
     * 启动后台任务。成功返回 true。
     *
     * @param string $taskId 32 位 hex 任务号
     */
    public static function startActionWorker(string $taskId): bool
    {
        return self::startActionWorkerDetailed($taskId)['ok'];
    }

    /**
     * 与 startActionWorker() 同逻辑，但返回**详细失败原因**（供 web 接口/诊断直接透出给用户）。
     *
     * ── ★ 2026-10-02 三修：CLI 全绿但 web（FPM）仍报「后台任务未能启动」 ────────────
     *   现象：`php tools/action-diagnose.php --run`（root/CLI）10 项全绿、worker 已接管，
     *        但用户在浏览器点「领取额度」仍报「后台任务未能启动」，且
     *        **action-worker.log 里连一行启动痕迹都没有**。
     *
     *   根因（两件事叠加）：
     *     ① 本函数原三条**提前 `return false`** 只写 `Logger`（storage/logs/app-*.log），
     *        **不写 action-worker.log** → web 失败时日志空白，无从定位（可见性问题）。
     *     ② **CLI 与 PHP-FPM 是两套环境**：
     *        · 宝塔「禁用函数(disable_functions)」**只对 FPM 生效**；
     *        · `.user.ini` 的 `open_basedir`（宝塔「防跨站攻击」）**只对 FPM 生效**
     *          → FPM 下 `@is_executable('/www/server/php/82/bin/php')`（站点树外）返回 false
     *          → `phpBinary()` 返回 `''` → 提前失败。
     *        于是「CLI 一切正常、web 就是起不来」，且毫无线索。
     *
     *   修法：(a) 失败原因**一律写进 action-worker.log**（带 `sapi=` 标记）；
     *         (b) 返回结构化原因，由 web 接口原样透出，让用户当场看到是哪个限制。
     *
     * @return array{ok:bool,code:string,reason:string,php:string,launcher:string,log:string,sapi:string}
     */
    public static function startActionWorkerDetailed(string $taskId): array
    {
        return self::spawnWorker($taskId, 'action-worker.php', self::workerLogFile());
    }

    /**
     * 启动深度研究 worker（bin/research-worker.php <task_id>）。
     *
     * 与 action-worker 完全同构，只是脚本与日志不同；沿用同一套
     * 「PHP-CLI 探针 + 跨平台脱离 + 失败留痕」逻辑，避免再抄一遍踩过的坑。
     */
    public static function startResearchWorker(string $taskId): bool
    {
        return self::spawnWorker($taskId, 'research-worker.php', self::workerLogFile('research-worker.log'))['ok'];
    }

    /**
     * 启动 Responses background worker（bin/responses-worker.php <response_id>）。
     *
     * ⚠️ 参数是 `resp_` 前缀的响应 id（`^resp_[a-f0-9]{24}$`），**不是** 32 位 hex 任务号；
     *    二者都经 `escapeshellarg()` 传递，且脚本名由本类硬编码 —— 调用方不得传入
     *    自定义脚本路径，否则等于开了命令注入面。
     */
    public static function startResponsesWorker(string $responseId): array
    {
        return self::spawnWorker($responseId, 'responses-worker.php', self::workerLogFile('responses-worker.log'));
    }

    /**
     * 通用 worker 启动器（action / research / 未来更多）。
     *
     * @param string $scriptName bin/ 下的脚本名，如 action-worker.php
     * @param string $logFile    该 worker 的日志文件（失败也要写进去）
     * @return array{ok:bool,code:string,reason:string,php:string,launcher:string,log:string,sapi:string}
     */
    private static function spawnWorker(string $taskId, string $scriptName, string $logFile): array
    {
        $root = defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2);
        $sapi = PHP_SAPI;

        // ⚠️ 失败也要留痕：写 worker 日志（含 sapi，便于区分 CLI 与 FPM 两条通道）
        $fail = static function (string $code, string $reason, array $extra = []) use ($logFile, $taskId, $sapi): array {
            self::appendLog($logFile, sprintf(
                '[%s] ✗ 启动 worker 失败  task=%s  sapi=%s  code=%s  %s%s',
                date('Y-m-d H:i:s'),
                $taskId,
                $sapi,
                $code,
                $reason,
                $extra !== [] ? '  ' . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
            ));
            Logger::warn('启动后台领取任务失败[' . $code . ']：' . $reason, [
                'task_id' => $taskId,
                'sapi'    => $sapi,
            ] + $extra);

            return [
                'ok'       => false,
                'code'     => $code,
                'reason'   => $reason,
                'php'      => '',
                'launcher' => '',
                'log'      => $logFile,
                'sapi'     => $sapi,
            ] + $extra;
        };

        if (!function_exists('proc_open')) {
            return $fail(
                'NO_PROC_OPEN',
                'proc_open 不可用（被 disable_functions 禁用；当前 sapi=' . $sapi . '）'
                . ' → 宝塔「PHP 8.2 → 禁用函数」里删掉 proc_open 后重启 PHP',
                ['disable_functions' => (string) ini_get('disable_functions')]
            );
        }

        $script = $root . '/bin/' . $scriptName;
        if (!is_file($script)) {
            $ob = (string) ini_get('open_basedir');
            return $fail(
                'NO_SCRIPT',
                '后台任务脚本不存在或不可达：' . $script
                . ($ob !== '' ? "（open_basedir=[{$ob}] 可能挡住了项目根）" : ''),
                ['open_basedir' => $ob]
            );
        }

        $probe = self::phpBinaryProbe();
        $php   = $probe['php'];
        if ($php === '') {
            $ob       = (string) ini_get('open_basedir');
            $rejected = [];
            foreach ($probe['candidates'] as $c) {
                $state = $c['is_exec'] ? '可执行' : ($c['exec_ok'] ? 'is_executable=false 但 exec 探针通过' : '不可执行');
                $rejected[] = $c['path'] . '=>' . $state;
            }
            return $fail(
                'NO_PHP_CLI',
                '未找到可用的 PHP-CLI（is_executable 与 exec 探针都失败）'
                . ($ob !== ''
                    ? "；当前 sapi={$sapi} 受 open_basedir=[{$ob}] 限制，站点树外的 PHP 二进制会被 is_executable() 判为不可用"
                    : '')
                . ' → 在 .env 配 AIGW_PHP_BIN=/www/server/php/82/bin/php，并关闭宝塔「防跨站攻击(open_basedir)」后重启 PHP',
                [
                    'open_basedir' => $ob,
                    'candidates'   => implode('  ', $rejected),
                ]
            );
        }

        // ★ 子进程输出**必须落到文件**，绝不能丢 /dev/null。
        //   2026-10-02 线上「卡在正在执行中、看不到成功也没报错」的根因之一就是
        //   这里：worker 若因「PHP 二进制选错 / 语法不兼容 / 启动即致命错误」秒死，
        //   报错全部进了 /dev/null → 任务永远停在 PENDING，且**无任何痕迹可查**。
        //   现在统一写入 storage/logs/action-worker.log（含每次启动的命令行）。
        //   （$logFile 已在方法开头取好，此处不再重复 workerLogFile()，避免多一次 stat 与轮转）

        // ⚠️ descriptors 一律用**空设备**，重定向只由命令行（shell）负责。
        //   曾因「descriptors 指向日志文件 + 命令行再 `>>` 同一文件」双重定向，
        //   在 Windows 上触发文件占用锁：
        //     The process cannot access the file because it is being used by another process.
        //   → shell 启动即失败 → worker 根本没跑 → 任务永远 PENDING（正是用户报的现象）。
        //   同一个文件**只能被一方打开**：交给 shell 最可靠（跨平台一致）。
        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', self::nullDevice(), 'a'],
            2 => ['file', self::nullDevice(), 'a'],
        ];

        // 决定 launcher（Linux：setsid / nohup / 裸 &）——**绝不带 -f**（CentOS 7 不认）
        $launcher = PHP_OS_FAMILY === 'Windows' ? 'start /B' : self::unixLauncher();

        // ★ 启动行带 sapi=（cli / fpm-fcgi / …），便于在日志里区分「CLI 诊断」与「web 点击」两条通道
        self::appendLog($logFile, sprintf(
            "[%s] 启动 worker  task=%s  sapi=%s  php=%s  launcher=%s",
            date('Y-m-d H:i:s'),
            $taskId,
            $sapi,
            $php,
            $launcher === '' ? '(none)' : $launcher
        ));

        try {
            $ok = PHP_OS_FAMILY === 'Windows'
                ? self::startWindows($php, $script, $taskId, $descriptors, $logFile)
                : self::startUnix($php, $script, $taskId, $descriptors, $logFile, $launcher);

            if (!$ok) {
                return $fail('SPAWN_FAILED', 'shell 启动 worker 进程失败（proc_open 返回非资源或退出码异常，详见 '
                    . $logFile . '）');
            }

            return [
                'ok'       => true,
                'code'     => 'OK',
                'reason'   => '',
                'php'      => $php,
                'launcher' => $launcher,
                'log'      => $logFile,
                'sapi'     => $sapi,
            ];
        } catch (\Throwable $e) {
            return $fail('EXCEPTION', '启动异常：' . $e->getMessage());
        }
    }

    /**
     * worker 日志文件路径（storage/logs/action-worker.log）
     *
     * 超过 5MB 时轮转一次（保留 .1），避免长期运行把磁盘写满。
     */
    public static function workerLogFile(string $name = 'action-worker.log'): string
    {
        $root = defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2);
        $dir  = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $name;

        if (@is_file($file) && @filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }

        return $file;
    }

    /** 追加一行到 worker 日志（失败静默，绝不影响主流程） */
    private static function appendLog(string $file, string $line): void
    {
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Windows：`start /B` 让子进程不占父控制台，父进程立即返回。
     */
    private static function startWindows(string $php, string $script, string $taskId, array $descriptors, string $logFile): bool
    {
        $null = self::nullDevice();
        $logArg = escapeshellarg($logFile);
        // start /B：不新建窗口、后台运行；外层再套 cmd /C。
        // 注：Windows 下 proc_open 的 descriptors 句柄被 cmd start 接管后未必可靠，
        //     故这里再显式用 `>>` 重定向一次，双保险。
        $cmd = sprintf(
            'cmd /C start /B "" "%s" "%s" %s >> %s 2>&1',
            $php,
            $script,
            escapeshellarg($taskId),
            $logArg
        );

        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($script));
        if (!is_resource($proc)) {
            return false;
        }
        // 不等待：立刻关闭并放行（子进程已被 cmd start 接管）
        @proc_close($proc);
        return true;
    }

    /**
     * Unix：**一律用 shell `&` 后台化**（与 setsid 版本无关）+ 可选的脱离前缀。
     *
     * 为什么不直接 exec php 而要交给 shell：
     *   `proc_close()` 会 `waitpid()` 阻塞到**被 exec 的程序**结束。
     *   worker 要跑 9~75s，若直接 exec php，提交接口就被拖成同步 → 524 复发。
     *   交给 shell 并加 `&` 后，shell **立即退出**，proc_close 也随之立即返回，
     *   这才是「提交即返回」的唯一可靠保证。
     *
     * 为什么不再用 `setsid -f`：
     *   见类注释 —— `-f/--fork` 是 util-linux 2.24 才有的选项，
     *   CentOS 7（util-linux 2.23.2）上会 **usage + exit(1)**，导致 worker 从未启动。
     *   这里改为**不带任何选项的 `setsid`**（2.23 即支持，语义=在新会话中运行），
     *   后台化本身交给 shell 的 `&`，不依赖 setsid 的 fork 行为。
     *
     * launcher 优先级：setsid（最彻底，脱离控制终端与会话）→ nohup（忽略 SIGHUP）→ 裸 `&`。
     * 三者都只是**脱离保险**；即便全都没有，`&` 也能让父进程立即返回（任务仍会执行）。
     */
    private static function startUnix(
        string $php,
        string $script,
        string $taskId,
        array $descriptors,
        string $logFile,
        string $launcher = ''
    ): bool {
        // 组装重定向：stdin 空设备，stdout/stderr 追加到 worker 日志（唯一真源）
        $cmd = sprintf(
            '%s %s %s %s >> %s 2>&1 &',
            $launcher,                       // '' / 'setsid' / 'nohup'（绝不含 -f）
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($taskId),
            escapeshellarg($logFile)
        );
        // 去掉 launcher 为空时的前导空格
        $cmd = ltrim($cmd);

        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($script));
        if (!is_resource($proc)) {
            self::appendLog($logFile, '[' . date('Y-m-d H:i:s') . '] proc_open 返回非资源，启动失败');
            return false;
        }

        // sh 因 `&` 立即返回；此处只是回收 sh 的退出码，**不会**等 worker。
        // 保留退出码并落日志，便于区分「sh 起不来」与「worker 起不来」。
        $exit = @proc_close($proc);
        if ($exit !== 0) {
            self::appendLog($logFile, sprintf(
                '[%s] 启动 shell 退出码=%s（命令：%s）',
                date('Y-m-d H:i:s'),
                var_export($exit, true),
                $cmd
            ));
        }

        return true;
    }

    /**
     * 选择 Unix 后台执行的脱离前缀。
     *
     * ⚠️ 一律返回**不带选项**的命令名：
     *   · `setsid`（不带 -f！—— CentOS 7 的 util-linux 2.23.2 不认 -f，加了必失败）
     *   · `nohup`
     *   · ''（裸 `&`，仍能保证父进程立即返回）
     */
    private static function unixLauncher(): string
    {
        if (self::commandExists('setsid')) {
            return 'setsid';
        }
        if (self::commandExists('nohup')) {
            return 'nohup';
        }
        return '';
    }

    /**
     * 命令是否可用。
     *
     * ★ 只判断「可执行文件存在」，**不判断选项支持度**。
     *   历史上正是「按二进制存在就假定 -f 可用」酿成了 CentOS 7 事故；
     *   因此调用方必须使用**不带任何选项**的调用形式（见 unixLauncher）。
     */
    private static function commandExists(string $name): bool
    {
        foreach (["/usr/bin/{$name}", "/bin/{$name}", "/usr/local/bin/{$name}"] as $p) {
            if (@is_executable($p)) {
                return true;
            }
        }
        $out = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    /**
     * 定位 PHP-CLI 可执行文件。
     *
     * 优先级：
     *   1. `AIGW_PHP_BIN`（显式配置，最高 —— 生产环境推荐写进 .env）
     *   2. `PHP_BINARY`（当前进程的 PHP；FPM 下常是 php-fpm，未必能当 CLI 用，需排除）
     *   3. 常见绝对路径（宝塔 /www/server/php/82/bin/php 优先）
     *   4. 系统 /usr/bin/php
     *
     * ⚠️ FPM 场景下 `PHP_BINARY` 指向 **php-fpm**（或 php-cgi），直接拿来跑脚本会失败，
     *    必须排除。这是本项目「部署约定 E~I」里同类坑的又一实例。
     */
    private static function phpBinary(): string
    {
        return self::phpBinaryProbe()['php'];
    }

    /**
     * 与 phpBinary() 同逻辑，但**连候选明细一起返回**，供失败原因透出与诊断使用。
     *
     * ── ★ 2026-10-02 三修（决定性）：open_basedir 会让 is_executable() 说谎 ────────
     *   `is_executable()` **受 `open_basedir` 约束**（PHP 的文件类函数都受约束）。
     *   宝塔「防跨站攻击」把 open_basedir 限定到**站点树**后，
     *   站点树**外**的 `/www/server/php/82/bin/php` 会被判为「不可执行」
     *   → `phpBinary()` 返回 `''` → worker 起不来。
     *   而这一限制**只对 PHP-FPM 生效**（`.user.ini` / FPM 池配置），
     *   CLI 不读它 → 于是出现「CLI 诊断 10 项全绿、web 点击就是起不来」的诡异现象。
     *
     *   **关键事实：`proc_open()` 不受 open_basedir 约束**
     *   （它走 `/bin/sh -c` 由 shell 解析路径，不是 PHP 的文件函数）。
     *   所以只要二进制**真的能跑**，即便 `is_executable()` 报 false 也照样能用。
     *   → 修法：`is_executable()` 全部落空时，改用**真跑一次 `<php> -v`** 的 exec 探针。
     *     探针本身就是可用性证明（能跑通 = 一定能 spawn），比任何预检都可靠。
     *
     * @return array{php:string,source:string,candidates:list<array{path:string,is_exec:bool,exec_ok:bool,note:string}>}
     */
    public static function phpBinaryProbe(): array
    {
        $candidates = [];
        $add = static function (string $path, string $note) use (&$candidates): void {
            if ($path === '') {
                return;
            }
            foreach ($candidates as $c) {   // 去重
                if ($c['path'] === $path) {
                    return;
                }
            }
            $candidates[] = [
                'path'    => $path,
                'is_exec' => (bool) @is_executable($path),
                'exec_ok' => false,
                'note'    => $note,
            ];
        };

        // AIGW_PHP_BIN 显式配置的路径（最高优先）
        $env = env_raw('AIGW_PHP_BIN');
        if (is_string($env) && $env !== '') {
            $add($env, '.env AIGW_PHP_BIN');
        }

        // PHP_BINARY（当前进程）；FPM 下是 php-fpm，不能直接当 CLI 用
        $self = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
        if ($self !== '' && !preg_match('/(php-fpm|php-cgi|php-fpm\d)/i', basename($self))) {
            $add($self, 'PHP_BINARY（当前进程）');
        } elseif ($self !== '') {
            $add($self, 'PHP_BINARY（是 fpm/cgi，不可用）');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $add('D:\\phpstudy_pro\\Extensions\\php\\php8.2.9nts\\php.exe', 'phpstudy 默认路径');
            $add('C:\\phpstudy_pro\\Extensions\\php\\php8.2.9nts\\php.exe', 'phpstudy 默认路径');
        } else {
            foreach (['/www/server/php/82/bin/php', '/www/server/php/81/bin/php',
                      '/www/server/php/80/bin/php', '/usr/local/bin/php', '/usr/bin/php'] as $p) {
                $add($p, '常见绝对路径');
            }
        }

        $usableNote = static fn (array $c): bool => !str_ends_with($c['note'], '不可用）');

        // ── 第一轮（快路径）：纯 is_executable 判定 ────────────────────────────
        foreach ($candidates as $c) {
            if ($c['is_exec'] && $usableNote($c)) {
                return ['php' => $c['path'], 'source' => 'is_executable', 'candidates' => $candidates];
            }
        }

        // ── 第二轮（慢路径，仅当第一轮全落空）：exec 探针 ──────────────────────
        //   触发场景 = open_basedir（FPM）挡住了站点树外的 PHP 二进制，
        //   但 proc_open 仍能把它跑起来。**只在这一轮才付出起进程的代价。**
        //   不限定 open_basedir 非空：权限/挂载等其他原因也可能让 is_executable 误报，
        //   而「真跑一次能通过」在**任何**情况下都是最强的可用性证明。
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ($candidates as $i => $c) {
                if (!$usableNote($c)) {
                    continue;
                }
                if (self::execProbeWorks($c['path'])) {
                    $candidates[$i]['exec_ok'] = true;
                    return ['php' => $c['path'], 'source' => 'exec_probe', 'candidates' => $candidates];
                }
            }
        }

        // ── 第三轮：PATH 里的 php（`command -v` 由 shell 解析，不受 open_basedir 影响）──
        $probe = PHP_OS_FAMILY === 'Windows' ? 'where php 2>NUL' : 'command -v php 2>/dev/null';
        $out = @shell_exec($probe);
        if (is_string($out)) {
            foreach (preg_split('/[\r\n]+/', $out) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // PATH 命中：同样先 is_executable，失败再 exec 探针
                if (@is_executable($line) || (PHP_OS_FAMILY !== 'Windows' && self::execProbeWorks($line))) {
                    $add($line, 'PATH 探测' . (@is_executable($line) ? '' : '（exec 探针通过）'));
                    $candidates[count($candidates) - 1]['exec_ok'] = !@is_executable($line);
                    return ['php' => $line, 'source' => 'path', 'candidates' => $candidates];
                }
            }
        }

        return ['php' => '', 'source' => '', 'candidates' => $candidates];
    }

    /**
     * exec 探针：真的把候选二进制跑一次 `-v`，看有没有版本输出。
     *
     * 为什么不能只靠 `is_executable()`：它**受 open_basedir 约束**，
     * 会把站点树外的合法 PHP-CLI 判成不可用（FPM 下必踩）。
     * 而 `proc_open()` / `exec()` **不受**该约束 → 能跑通就是真能用。
     *
     * 安全性：候选路径全部来自硬编码白名单 + `.env`（运维自己配的），不含用户输入，
     * 故此处直接拼接无注入面。加 `-n`（no ini）避免加载站点 ini 反而变慢/报错。
     */
    private static function execProbeWorks(string $php): bool
    {
        if ($php === '' || PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        $cmd = escapeshellarg($php) . ' -n -r ' . escapeshellarg('echo "AIGW_PROBE_OK";') . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        return is_string($out) && str_contains($out, 'AIGW_PROBE_OK');
    }

    /**
     * ★ 后台任务运行环境自检（2026-10-02 三修）。
     *
     * 目的：把「CLI 能用、FPM 不能用」这件事**当场暴露**。
     * 同一份代码在 CLI 与 PHP-FPM 下会因下面三项**得出完全不同的结果**：
     *   · `disable_functions`（宝塔「禁用函数」）——**只对 FPM 生效**
     *   · `open_basedir`（宝塔「防跨站攻击」）——**只对 FPM 生效**，会让站点树外的
     *     `/www/server/php/82/bin/php` 被 `is_executable()` 判为不可用
     *   · `AIGW_PHP_BIN` 是否配置且可达
     *
     * @return array<string,mixed> 可直接 json 输出，供 web 管理台展示
     */
    public static function selfCheck(): array
    {
        $root   = defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2);
        $script = $root . '/bin/action-worker.php';
        $probe  = self::phpBinaryProbe();

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $disabled = array_values(array_filter($disabled, static fn ($v) => $v !== ''));

        // open_basedir 是否真的挡住了项目根（这在 FPM 下极常见）
        $ob = (string) ini_get('open_basedir');
        $obBlocksRoot = false;
        if ($ob !== '') {
            $obBlocksRoot = !self::pathWithinOpenBasedir($root, $ob);
        }

        $logFile   = self::workerLogFile();
        $logsWrit  = false;
        $dir       = dirname($logFile);
        if (is_dir($dir)) {
            $probeFile = $dir . '/.selfcheck-' . bin2hex(random_bytes(4));
            $logsWrit  = @file_put_contents($probeFile, "ok\n") !== false;
            @unlink($probeFile);
        }

        $blockers = [];
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            $blockers[] = 'proc_open 不可用（disable_functions）→ 宝塔「PHP 8.2 → 禁用函数」里删除它';
        }
        if (!is_file($script)) {
            $blockers[] = 'worker 脚本不可达：' . $script . ($ob !== '' ? "（open_basedir=[{$ob}]）" : '');
        }
        if ($probe['php'] === '') {
            $blockers[] = '未找到可用的 PHP-CLI'
                . ($obBlocksRoot ? "（open_basedir=[{$ob}] 挡住了站点树外的 PHP 二进制）" : '')
                . ' → .env 配 AIGW_PHP_BIN=/www/server/php/82/bin/php';
        }
        if (!$logsWrit) {
            $blockers[] = 'storage/logs 不可写 → chown -R www:www storage && chmod -R 775 storage';
        }

        return [
            'sapi'               => PHP_SAPI,
            'php_version'        => PHP_VERSION,
            'php_binary'         => defined('PHP_BINARY') ? PHP_BINARY : '',
            'proc_open'          => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
            'disable_functions'  => $disabled,
            'open_basedir'       => $ob,
            'open_basedir_blocks_root' => $obBlocksRoot,
            'env_php_bin'        => (string) (env_raw('AIGW_PHP_BIN') ?? ''),
            'php_cli'            => $probe['php'],
            'php_cli_source'     => $probe['source'],
            'php_cli_candidates' => $probe['candidates'],
            'worker_script'      => $script,
            'worker_script_ok'   => is_file($script),
            'launcher'           => PHP_OS_FAMILY === 'Windows' ? 'start /B' : self::unixLauncher(),
            'log_file'           => $logFile,
            'log_writable'       => $logsWrit,
            'blockers'           => $blockers,
            'ready'              => $blockers === [],
        ];
    }

    /**
     * 判断给定绝对路径是否落在 open_basedir 允许范围内（用于定位「FPM 下站点树外不可达」）。
     *
     * ⚠️ 分隔符**必须按平台选**：PHP 在 Windows 用 `;`、Unix 用 `:`。
     *   若一律用 `/[;:]/` 切，Windows 的盘符（`D:`）会被当成分隔符切碎
     *   → 明明可达却被判为 blocked（2026-10-02 本地自检时踩到）。
     */
    private static function pathWithinOpenBasedir(string $path, string $openBasedir): bool
    {
        $norm = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');

        $path  = $norm($path);
        $sep   = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        $roots = [];

        foreach (explode($sep, $openBasedir) as $root) {
            $root = $norm(trim($root));
            if ($root !== '') {
                $roots[] = $root;
            }
        }

        // 配置存在但解析不出任何有效根（如只剩空串）→ 视为不限制
        if ($roots === []) {
            return true;
        }

        // 只要存在一个允许根是该路径的前缀（按目录边界），即视为可达
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path . '/', $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /** 空设备路径（跨平台） */
    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
