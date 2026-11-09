<?php
/**
 * 插件服务（Operit ToolPkg 生态）
 *
 * 职责：
 *  1. 解析 `manifest.json`（校验 schema_version / toolpkg_id / main 等必备字段）
 *  2. 把插件声明的**声明式能力**挂载到对话管线：
 *     - `tools`：追加到工具清单（执行交由宿主已注册的执行器 / HTTP 端点）
 *     - `system_prompt`：追加到系统提示词
 *  3. 导出给 App 侧 QuickJS 沙箱执行的 bundle 引用（按 plugin_id 定位）
 *
 * 设计取舍：网关侧无 JS 运行时，插件 JS 由 App 沙箱执行。故这里管理的是
 * 「元数据 + 声明」，与 Operit 的 manifest 结构保持字段级兼容。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PluginRepository;
use App\Support\Logger;

class PluginService
{
    /** 支持的 manifest schema 版本 */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** 支持的 ToolPkg API 大版本（Operit 当前为 1.x） */
    public const SUPPORTED_API_MAJOR = '1';

    /** 单个插件最多声明的工具数（防止清单爆炸） */
    public const MAX_TOOLS_PER_PLUGIN = 50;

    public function __construct(private PluginRepository $repository) {}

    /**
     * 校验并解析 manifest。
     *
     * @return array{ok:bool, manifest:array, error:string}
     */
    public function parseManifest(array $manifest): array
    {
        $schemaVersion = (int) ($manifest['schema_version'] ?? 0);
        if (!in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            return ['ok' => false, 'manifest' => [], 'error' => "不支持的 schema_version：{$schemaVersion}"];
        }

        $pluginId = trim((string) ($manifest['toolpkg_id'] ?? ''));
        if ($pluginId === '') {
            return ['ok' => false, 'manifest' => [], 'error' => 'manifest.toolpkg_id 不能为空'];
        }
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $pluginId)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'toolpkg_id 只能包含字母、数字、点、下划线与短横线'];
        }

        $entry = trim((string) ($manifest['main'] ?? ''));
        if ($entry === '') {
            return ['ok' => false, 'manifest' => [], 'error' => 'manifest.main 入口不能为空'];
        }

        $apiVersion = (string) ($manifest['api_version'] ?? '');
        if ($apiVersion !== '' && !str_starts_with($apiVersion, self::SUPPORTED_API_MAJOR . '.')) {
            return ['ok' => false, 'manifest' => [], 'error' => "不兼容的 api_version：{$apiVersion}（需要 " . self::SUPPORTED_API_MAJOR . '.x）'];
        }

        $tools = $manifest['tools'] ?? [];
        if (!is_array($tools)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'manifest.tools 必须是数组'];
        }
        $subpackages = $manifest['subpackages'] ?? [];
        if (!is_array($subpackages)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'manifest.subpackages 必须是数组'];
        }
        foreach ($subpackages as $sub) {
            if (!is_array($sub) || (isset($sub['tools']) && !is_array($sub['tools']))) {
                return ['ok' => false, 'manifest' => [], 'error' => 'subpackage.tools 必须是数组'];
            }
            $tools = array_merge($tools, $sub['tools'] ?? []);
        }
        if (count($tools) > self::MAX_TOOLS_PER_PLUGIN) {
            return ['ok' => false, 'manifest' => [], 'error' => '插件工具数超过上限'];
        }
        $names = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tool['name'])
                || isset($names[$tool['name']])) {
                return ['ok' => false, 'manifest' => [], 'error' => '插件工具名称无效或重复'];
            }
            $names[$tool['name']] = true;
            $script = is_string($tool['script'] ?? null) ? trim($tool['script']) : '';
            $handler = is_string($tool['handler'] ?? null) ? trim($tool['handler']) : '';
            if ($script === '' && ($handler === '' || $handler === $tool['name'])) {
                return ['ok' => false, 'manifest' => [], 'error' => '工具 ' . $tool['name'] . ' 缺少可执行 script/handler'];
            }
        }
        return ['ok' => true, 'manifest' => $manifest, 'error' => ''];
    }

    /**
     * 安装（新建）插件。
     *
     * @return array{ok:bool, plugin:?array, error:string}
     */
    public function install(array $manifest, ?int $userId, int $writeId, string $source = 'upload'): array
    {
        $parsed = $this->parseManifest($manifest);
        if (!$parsed['ok']) {
            return ['ok' => false, 'plugin' => null, 'error' => $parsed['error']];
        }

        $manifest = $parsed['manifest'];
        $pluginId = (string) $manifest['toolpkg_id'];

        if ($this->repository->pluginIdExists($pluginId, $userId)) {
            return ['ok' => false, 'plugin' => null, 'error' => "插件已存在：{$pluginId}"];
        }
        if ($this->repository->countForUser($userId) >= PluginRepository::MAX_PER_USER) {
            return ['ok' => false, 'plugin' => null, 'error' => '插件数量已达上限'];
        }

        $id = $this->repository->create([
            'user_id'        => $writeId,
            'plugin_id'      => $pluginId,
            'name'           => $this->displayName($manifest),
            'description'    => $this->localizedText($manifest['description'] ?? ''),
            'version'        => (string) ($manifest['version'] ?? ''),
            'api_version'    => (string) ($manifest['api_version'] ?? ''),
            'entry'          => (string) ($manifest['main'] ?? ''),
            'subpackages'    => (array) ($manifest['subpackages'] ?? []),
            'manifest'       => $manifest,
            'source'         => $source,
            'enabled'        => !isset($manifest['enabled_by_default']) || !empty($manifest['enabled_by_default']),
            'status'         => 'OK',
            'status_message' => '',
        ]);

        Logger::info('插件已安装', ['plugin_id' => $pluginId, 'id' => $id]);

        return ['ok' => true, 'plugin' => $this->repository->findForUser($id, $userId), 'error' => ''];
    }

    /**
     * 汇总启用插件声明的所有工具（OpenAI function schema）。
     *
     * 插件 manifest 可用两种方式声明工具：
     *  - `tools`: [{name, description, parameters, handler}]  —— handler 指向宿主执行器
     *  - `subpackages` 中的每一项若带 `tools`，同样并入
     *
     * @return array<int, array>
     */
    public function validatePlugin(array $plugin): array
    {
        $parsed = $this->parseManifest($plugin['manifest'] ?? []);
        $status = $parsed['ok'] ? 'OK' : 'FAIL';
        if (($plugin['status'] ?? '') !== $status || ($plugin['status_message'] ?? '') !== $parsed['error']) {
            $this->repository->setStatus((int) $plugin['id'], $status, $parsed['error']);
        }
        $plugin['status'] = $status;
        $plugin['status_message'] = $parsed['error'];
        return $plugin;
    }

    public function collectToolSchemas(?int $userId): array
    {
        $out = [];
        foreach ($this->repository->enabledForUser($userId) as $plugin) {
            $plugin = $this->validatePlugin($plugin);
            if ($plugin['status'] !== 'OK') continue;
            foreach ($this->toolsOfManifest($plugin['manifest'] ?? []) as $tool) {
                $out[] = $tool;
            }
        }

        return $out;
    }

    /**
     * 汇总启用插件声明的系统提示词片段。
     */
    public function collectSystemPrompt(?int $userId): string
    {
        $parts = [];
        foreach ($this->repository->enabledForUser($userId) as $plugin) {
            $plugin = $this->validatePlugin($plugin);
            if ($plugin['status'] !== 'OK') continue;
            $manifest = $plugin['manifest'] ?? [];
            $prompt = trim((string) ($manifest['system_prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $name = $plugin['name'] !== '' ? $plugin['name'] : $plugin['plugin_id'];
            $parts[] = "### 插件：{$name}\n{$prompt}";
        }

        if ($parts === []) {
            return '';
        }

        return "## 已启用插件\n" . implode("\n\n", $parts);
    }

    /**
     * 按名称查找「声明了该工具」的插件（执行分发时用）。
     */
    public function findPluginForTool(string $toolName, ?int $userId): ?array
    {
        foreach ($this->repository->enabledForUser($userId) as $plugin) {
            $plugin = $this->validatePlugin($plugin);
            if ($plugin['status'] !== 'OK') continue;
            foreach ($this->toolsOfManifest($plugin['manifest'] ?? []) as $tool) {
                if (($tool['function']['name'] ?? '') === $toolName) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /**
     * 把 manifest 中的工具声明规范化为 OpenAI function schema。
     *
     * @return array<int, array>
     */
    private function toolsOfManifest(array $manifest): array
    {
        $raw = [];
        if (is_array($manifest['tools'] ?? null)) {
            $raw = $manifest['tools'];
        }
        foreach ((array) ($manifest['subpackages'] ?? []) as $sub) {
            if (is_array($sub) && is_array($sub['tools'] ?? null)) {
                $raw = array_merge($raw, $sub['tools']);
            }
        }

        $out = [];
        foreach ($raw as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name'        => $name,
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters'  => is_array($tool['parameters'] ?? null)
                        ? $tool['parameters']
                        : ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
                // 非标准字段：交给宿主分发时决定怎么执行
                '_handler' => (string) ($tool['handler'] ?? ''),
                '_plugin'  => (string) ($manifest['toolpkg_id'] ?? ''),
                // Only actual inline code is executable; a main filename is not a bundle.
                '_script'  => is_string($tool['script'] ?? null) ? $tool['script'] : '',
            ];
        }

        return $out;
    }

    /** 取本地化展示名（manifest.display_name 支持 {zh,en} 或纯字符串） */
    private function displayName(array $manifest): string
    {
        $name = $this->localizedText($manifest['display_name'] ?? '');
        if ($name === '') {
            $name = (string) ($manifest['toolpkg_id'] ?? '');
        }
        return mb_substr($name, 0, 128);
    }

    /** Operit 的 LocalizedText：string | {lang: string} */
    private function localizedText($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach (['zh', 'zh-CN', 'en', 'en-US'] as $lang) {
                if (!empty($value[$lang]) && is_string($value[$lang])) {
                    return $value[$lang];
                }
            }
            $first = reset($value);
            return is_string($first) ? $first : '';
        }
        return '';
    }
}
