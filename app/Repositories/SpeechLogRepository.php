<?php
/**
 * 语音调用日志仓储（对应 aigw_speech_log）
 *
 * 参考 Operit 的 SpeechService / VoiceService —— 语音能力放服务端代理，
 * 客户端不接触上游 API Key。本仓储只记审计/计费元数据，不落音频正文。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class SpeechLogRepository
{
    private const TABLE = 'aigw_speech_log';

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    public function record(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert([
            'user_id'     => (int) ($data['user_id'] ?? 0),
            'kind'        => ($data['kind'] ?? 'stt') === 'tts' ? 'tts' : 'stt',
            'provider'    => mb_substr((string) ($data['provider'] ?? ''), 0, 32),
            'model'       => mb_substr((string) ($data['model'] ?? ''), 0, 64),
            'bytes_in'    => (int) ($data['bytes_in'] ?? 0),
            'bytes_out'   => (int) ($data['bytes_out'] ?? 0),
            'text_len'    => (int) ($data['text_len'] ?? 0),
            'duration_ms' => (int) ($data['duration_ms'] ?? 0),
            'ok'          => !empty($data['ok']) ? 1 : 0,
            'message'     => mb_substr((string) ($data['message'] ?? ''), 0, 500),
        ]);
    }

    /** 近期调用（属主隔离） */
    public function recent(?int $userId, int $limit = 50): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->orderBy('id', 'DESC')->limit($limit)->get();
    }
}
