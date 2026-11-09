<?php
/**
 * 环境服务（Operit SoftwareSettings 生态）
 *
 * 参考 Operit 的软件设置：
 *  - EnvironmentVariableRead/Write：环境变量读写（见 EnvVarRepository）
 *  - SandboxPackages / SandboxPackageUpdate：沙箱内可用的运行时包（node/python/ffmpeg 等）
 *
 * 网关侧无真实沙箱文件系统，故「沙箱包」落地为**可安装的运行时能力清单**：
 * 记录每个包是否「已启用」，供 App 侧 QuickJS/Root 环境据此决定是否暴露对应能力。
 * 这是「声明式环境」——与实际安装解耦，App 侧按清单自行准备。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EnvVarRepository;

class EnvironmentService
{
    /**
     * 内置可声明的运行时包清单（对齐 Operit 沙箱常见包）。
     *
     * key = 包标识；label = 展示名；category = 分组；provides = 提供的能力（工具/命令）。
     */
    private const PACKAGE_CATALOG = [
        'coreutils' => [
            'label'    => 'Coreutils',
            'category' => 'system',
            'provides' => ['ls', 'cat', 'grep', 'sed', 'awk'],
            'desc'     => '基础 Unix 命令行工具集',
        ],
        'nodejs' => [
            'label'    => 'Node.js',
            'category' => 'runtime',
            'provides' => ['node', 'npm'],
            'desc'     => 'JavaScript 运行时（供插件 bundle 执行）',
        ],
        'python' => [
            'label'    => 'Python',
            'category' => 'runtime',
            'provides' => ['python3', 'pip'],
            'desc'     => 'Python 运行时',
        ],
        'ffmpeg' => [
            'label'    => 'FFmpeg',
            'category' => 'media',
            'provides' => ['ffmpeg', 'ffprobe'],
            'desc'     => '音视频转码与处理',
        ],
        'git' => [
            'label'    => 'Git',
            'category' => 'vcs',
            'provides' => ['git'],
            'desc'     => '版本控制',
        ],
        'curl' => [
            'label'    => 'cURL',
            'category' => 'network',
            'provides' => ['curl', 'wget'],
            'desc'     => '网络下载工具',
        ],
        'jq' => [
            'label'    => 'jq',
            'category' => 'system',
            'provides' => ['jq'],
            'desc'     => 'JSON 处理工具',
        ],
        'imagemagick' => [
            'label'    => 'ImageMagick',
            'category' => 'media',
            'provides' => ['convert', 'identify'],
            'desc'     => '图像处理',
        ],
        'sqlite' => [
            'label'    => 'SQLite',
            'category' => 'database',
            'provides' => ['sqlite3'],
            'desc'     => '嵌入式数据库',
        ],
        'openssl' => [
            'label'    => 'OpenSSL',
            'category' => 'crypto',
            'provides' => ['openssl'],
            'desc'     => '加解密工具',
        ],
    ];

    public function __construct(private EnvVarRepository $envVars) {}

    /** 包清单（catalog） */
    public function catalog(): array
    {
        $out = [];
        foreach (self::PACKAGE_CATALOG as $key => $meta) {
            $out[] = array_merge(['key' => $key], $meta);
        }

        return $out;
    }

    public function hasPackage(string $key): bool
    {
        return isset(self::PACKAGE_CATALOG[$key]);
    }

    /**
     * 网关平台信息（供 App 展示「环境」概况）。
     */
    public function platformInfo(): array
    {
        return [
            'php_version'      => PHP_VERSION,
            'os'               => PHP_OS_FAMILY,
            'arch'             => php_uname('m'),
            'sapi'             => PHP_SAPI,
            'extensions'       => [
                'curl'      => extension_loaded('curl'),
                'json'      => extension_loaded('json'),
                'mbstring'  => extension_loaded('mbstring'),
                'openssl'   => extension_loaded('openssl'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'sqlite3'   => extension_loaded('sqlite3'),
                'gd'        => extension_loaded('gd'),
                'zip'       => extension_loaded('zip'),
            ],
            'limits'           => [
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'memory_limit'       => (string) ini_get('memory_limit'),
                'upload_max_filesize'=> (string) ini_get('upload_max_filesize'),
                'post_max_size'      => (string) ini_get('post_max_size'),
            ],
        ];
    }

    /**
     * 解析出「环境变量 + 已声明包」的执行上下文（供工具/MCP 子进程注入）。
     *
     * @return array{env:array<string,string>, packages:array<int,string>}
     */
    public function execContext(?int $userId, array $declaredPackages = []): array
    {
        $env = $this->envVars->resolved($userId);
        $packages = array_values(array_filter(
            $declaredPackages,
            fn ($k) => is_string($k) && $this->hasPackage($k)
        ));

        return ['env' => $env, 'packages' => $packages];
    }
}
