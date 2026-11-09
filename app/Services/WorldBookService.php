<?php
/**
 * 世界书服务（参考 Operit 的 WorldBookService / worldbook_service.ts）
 *
 * 职责：
 *   1. 按关键词 / 正则，扫描「当前输入 + 最近 scan_depth 条用户消息」激活条目
 *   2. always_active 条目无条件激活
 *   3. 按 inject_target / inject_position 把激活条目拼成上下文块
 *
 * 与 Operit 的差异：Operit 在 Android 端钩住 prompt finalize 阶段注入；
 * 这里由网关在转发前统一处理，客户端无需感知注入细节。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\WorldBookRepository;

class WorldBookService
{
    public function __construct(private WorldBookRepository $repository) {}

    /**
     * 计算「本轮对话应当注入的世界书内容」。
     *
     * @param string $input  当前用户输入（本轮）
     * @param array<int,string> $recentUserMessages 最近的历史用户消息（越靠后越新）
     * @param list<int> $onlyIds 白名单：非空时只激活这些条目 id（助手绑定的世界书）。
     * @return array{system:string,user:string,assistant:string,activated:array<int,array{id:int,name:string}>}
     *         按目标位置分组的注入文本；activated 用于回执展示
     */
    public function buildInjection(?int $userId, string $input, array $recentUserMessages = [], array $onlyIds = []): array
    {
        $entries = $this->repository->enabledForUser($userId);

        // 助手绑定了世界书：只激活白名单内的条目（其余即使命中也不注入）。
        if ($onlyIds !== []) {
            $allow = array_flip($onlyIds);
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => isset($allow[(int) $entry['id']])
            ));
        }

        $byTarget = ['system' => [], 'user' => [], 'assistant' => []];
        $activated = [];
        $structured = [];

        foreach ($entries as $entry) {
            if (!$this->isActive($entry, $input, $recentUserMessages)) {
                continue;
            }

            $target = $entry['inject_target'];
            if (!isset($byTarget[$target])) {
                $target = 'system';
            }

            $structured[] = $entry + ['priority' => 50];
            $position = $entry['inject_position'];
            if ($position === 'at_depth') {
                // at_depth：包裹深度标记，交由客户端/调用方在指定深度插入
                $depth = (int) $entry['insertion_depth'];
                $byTarget[$target][] = "[depth={$depth}]{$entry['content']}[/depth]";
            } elseif ($position === 'prepend') {
                array_unshift($byTarget[$target], $entry['content']);
            } else {
                $byTarget[$target][] = $entry['content'];
            }

            $activated[] = ['id' => (int) $entry['id'], 'name' => (string) $entry['name']];
            $this->repository->markHit((int) $entry['id']);
        }

        return [
            'system'    => $this->wrap($byTarget['system']),
            'user'      => $this->wrap($byTarget['user']),
            'assistant' => $this->wrap($byTarget['assistant']),
            'activated' => $activated,
            'entries' => $structured,
        ];
    }

    /** 是否激活：always_active 直接真；否则按关键词/正则匹配扫描范围 */
    private function isActive(array $entry, string $input, array $recentUserMessages): bool
    {
        if (!empty($entry['always_active'])) {
            return true;
        }

        $keywords = $this->splitKeywords((string) ($entry['keywords'] ?? ''));
        if ($keywords === []) {
            return false; // 无关键词且非常驻 = 永不激活
        }

        // 扫描范围：当前输入 + 最近 scan_depth 条用户消息
        $depth = max(1, (int) $entry['scan_depth']);
        $recent = array_slice(array_reverse($recentUserMessages), 0, $depth);
        $haystacks = array_merge([$input], $recent);

        foreach ($keywords as $keyword) {
            foreach ($haystacks as $text) {
                if ($text === '') {
                    continue;
                }
                if ($this->matches($keyword, $text, $entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matches(string $keyword, string $text, array $entry): bool
    {
        if (!empty($entry['is_regex'])) {
            // 正则：Operit 用 "gi"/"g" 标志；PHP 需显式补分隔符
            $pattern = '#' . str_replace('#', '\\#', $keyword) . '#u';
            if (empty($entry['case_sensitive'])) {
                $pattern .= 'i';
            }
            $result = @preg_match($pattern, $text);
            return $result === 1;
        }

        if (empty($entry['case_sensitive'])) {
            return mb_stripos($text, $keyword) !== false;
        }

        return mb_strpos($text, $keyword) !== false;
    }

    /** @return array<int,string> */
    private function splitKeywords(string $raw): array
    {
        $parts = preg_split('/[,，\n]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /** @param array<int,string> $blocks */
    private function wrap(array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }
        return "<worldbook>\n" . implode("\n", $blocks) . "\n</worldbook>";
    }
}
