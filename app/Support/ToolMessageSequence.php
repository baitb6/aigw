<?php

declare(strict_types=1);

namespace App\Support;

final class ToolMessageSequence
{
    /** @return array{0:array<int,mixed>,1:array<int,string>} */
    public static function repair(array $messages): array
    {
        $messages = array_values($messages);
        $out = [];
        $changes = [];
        for ($i = 0, $count = count($messages); $i < $count; $i++) {
            $message = $messages[$i];
            if (!is_array($message)) {
                $changes[] = "消息[{$i}] 非法消息，已删除";
                continue;
            }
            $role = $message['role'] ?? '';
            if ($role === 'tool') {
                $changes[] = "消息[{$i}] 孤立或重复工具响应，已删除";
                continue;
            }
            if ($role !== 'assistant' || !array_key_exists('tool_calls', $message)) {
                $out[] = $message;
                continue;
            }

            // Only the immediately following tool block may answer this assistant.
            $answers = [];
            $end = $i + 1;
            while ($end < $count && is_array($messages[$end]) && ($messages[$end]['role'] ?? '') === 'tool') {
                $answer = $messages[$end];
                $id = $answer['tool_call_id'] ?? null;
                if (is_string($id) && trim($id) !== '' && !isset($answers[$id])) {
                    $answers[$id] = $answer;
                }
                $end++;
            }
            $calls = [];
            $responses = [];
            $seen = [];
            foreach (is_array($message['tool_calls']) ? $message['tool_calls'] : [] as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $id = $call['id'] ?? null;
                $fn = $call['function'] ?? $call['function_call'] ?? null;
                if (!is_string($id) || trim($id) === '' || isset($seen[$id]) || !isset($answers[$id])
                    || !is_array($fn) || !is_string($fn['name'] ?? null) || trim($fn['name']) === '') {
                    continue;
                }
                $seen[$id] = true;
                $calls[] = $call;
                $responses[] = $answers[$id];
            }
            $original = array_slice($messages, $i, $end - $i);
            $group = [];
            if ($calls !== []) {
                $message['tool_calls'] = $calls;
                $group = [$message, ...$responses];
            } else {
                unset($message['tool_calls']);
                $content = $message['content'] ?? '';
                if (is_array($content) ? $content !== [] : trim((string) $content) !== '') {
                    $group[] = $message;
                }
            }
            if ($group !== $original) {
                $changes[] = "消息[{$i}] 工具组已按相邻且唯一的调用/响应修复";
            }
            array_push($out, ...$group);
            $i = $end - 1;
        }
        return [$out, $changes];
    }
}
