<?php
/**
 * 向量记忆仓储（对应 aigw_memory）
 *
 * 参考 Operit 的 MemoryRepository：以「实体 + 标签」构成知识图谱的轻量版，
 * 检索支持四路混合召回（keyword / tag / semantic / 重要度），并用 RRF 融合排序。
 *
 * 与 Operit 的差异：Operit 用 ObjectBox + HNSW 本地向量索引；
 * 本实现不引入外部向量库 —— 向量以 JSON 存 MEDIUMTEXT，相似度在 PHP 侧算余弦。
 * 记忆条目量级（每用户上限受控）下，全量扫描 + 余弦足够快；
 * 若未来上量，可平滑替换为 pgvector / Qdrant，接口不变。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class MemoryRepository
{
    private const TABLE = 'aigw_memory';

    /** 单用户记忆上限（防止无限膨胀；超限按 重要度+新旧 淘汰） */
    public const MAX_PER_USER = 1000;

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /** 列表（可选关键词过滤，服务端做混合排序） */
    public function list(?int $userId, string $query = '', int $limit = 100): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $rows = array_map([$this, 'hydrate'], $q->orderBy('importance', 'DESC')->orderBy('id', 'DESC')->limit($limit)->get());

        if ($query === '') {
            return $rows;
        }

        return $this->rank($rows, $query, min($limit, 20));
    }

    public function findForUser(int $id, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 按标题取（同用户唯一），供 upsert */
    public function findByTitle(string $title, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('title', $title);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 该属主的所有条目（检索用，只取必要列） */
    public function allForUser(?int $userId, int $limit = self::MAX_PER_USER): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->select('id', 'title', 'content', 'tags', 'importance', 'embedding', 'embed_model');
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->limit($limit)->get();
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert($this->pack($data));
    }

    /**
     * @param ?int $userId 归属过滤（审计 TOCTOU 修复）：传入后 WHERE 并入 user_id，
     *                     即使控制器前置 findForUser 与写操作之间记录被转移，也不会写错属主。
     */
    public function update(int $id, array $data, ?int $userId = null): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->update(array_intersect_key($this->pack($data), $data));
    }

    public function delete(int $id, ?int $userId = null): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    public function deleteAll(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    public function countForUser(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->count();
    }

    /** 只写向量（异步补齐时用，避免整行覆盖） */
    public function setEmbedding(int $id, array $vector, string $model): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'embedding'   => json_encode($vector),
            'embed_model' => mb_substr($model, 0, 64),
        ]);
    }

    /**
     * 四路混合召回 + RRF 融合（参考 Operit 的 4 路召回思路）。
     *
     *  - keyword 路：标题/正文命中查询词
     *  - tag 路：标签命中
     *  - semantic 路：查询向量与记忆向量的余弦相似度（若有向量）
     *  - 重要度：作为加权因子
     *
     * @param array    $rows  候选项（hydrate 后的行）
     * @param string   $query 查询文本
     * @param int      $topK
     * @param float[]  $queryVector 查询向量（可选；为空则跳过 semantic 路）
     * @return array 排序后的行（含 score / 各路命中标记）
     */
    public function rank(array $rows, string $query, int $topK = 5, array $queryVector = []): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return array_slice($rows, 0, $topK);
        }

        $keywords = $this->tokenize($q);
        $scored = [];

        foreach ($rows as $row) {
            $title = mb_strtolower((string) ($row['title'] ?? ''));
            $content = mb_strtolower((string) ($row['content'] ?? ''));
            $tags = array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? ''))));
            $importance = (float) ($row['importance'] ?? 1.0);

            $kwHit = 0.0;
            foreach ($keywords as $kw) {
                if (mb_strlen($kw) < 2) {
                    continue;
                }
                if (mb_strpos($title, $kw) !== false) {
                    $kwHit += 3.0;
                }
                if (mb_strpos($content, $kw) !== false) {
                    $kwHit += 1.0;
                }
            }

            $tagHit = 0.0;
            foreach ($tags as $tag) {
                if ($tag !== '' && mb_strpos($q, mb_strtolower($tag)) !== false) {
                    $tagHit += 2.5;
                }
            }

            $semantic = 0.0;
            if ($queryVector !== [] && !empty($row['embedding'])) {
                $vector = is_array($row['embedding']) ? $row['embedding'] : json_decode((string) $row['embedding'], true);
                if (is_array($vector) && $vector !== []) {
                    $semantic = $this->cosine($queryVector, $vector);
                }
            }

            // RRF 式融合：各路得分归一到同一量纲再加权
            $base = $kwHit + $tagHit + ($semantic > 0 ? $semantic * 5.0 : 0.0);
            if ($base <= 0) {
                continue;
            }

            $row['score'] = round($base * (0.5 + $importance / 2), 4);
            $row['hit'] = [
                'keyword'  => $kwHit > 0,
                'tag'      => $tagHit > 0,
                'semantic' => $semantic > 0,
            ];
            $scored[] = $row;
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /** 余弦相似度（向量等长时有效） */
    public function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0 || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if (!is_numeric($a[$i]) || !is_numeric($b[$i]) || !is_finite((float) $a[$i]) || !is_finite((float) $b[$i])) return 0.0;
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /** 分词：英文按空白/标点切，中文补 2-gram（无分词扩展时的兜底） */
    private function tokenize(string $text): array
    {
        $tokens = [];
        $parts = preg_split('/[\s,，。.!！?？;；:：、\-_\|\/]+/u', $text) ?: [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $tokens[$part] = true;
            if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $part) && mb_strlen($part) > 2) {
                $len = mb_strlen($part);
                for ($i = 0; $i + 2 <= $len; $i++) {
                    $tokens[mb_substr($part, $i, 2)] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    private function pack(array $data): array
    {
        $packed = [
            'title'   => mb_substr((string) ($data['title'] ?? ''), 0, 255),
            'content' => (string) ($data['content'] ?? ''),
            'tags'    => mb_substr((string) ($data['tags'] ?? ''), 0, 512),
            'source'  => in_array($data['source'] ?? '', ['manual', 'auto', 'import'], true)
                ? $data['source'] : 'manual',
        ];

        if (array_key_exists('importance', $data)) {
            $packed['importance'] = (float) $data['importance'];
        }
        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }
        if (array_key_exists('embedding', $data)) {
            $vector = $data['embedding'];
            $packed['embedding'] = (is_array($vector) && $vector !== [])
                ? json_encode($vector) : null;
        }
        if (array_key_exists('embed_model', $data)) {
            $packed['embed_model'] = mb_substr((string) $data['embed_model'], 0, 64);
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $row['importance'] = (float) ($row['importance'] ?? 1.0);
        $row['tags_list'] = array_values(array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? '')))));
        $row['has_embedding'] = !empty($row['embedding']);
        unset($row['embedding']); // 不外泄大向量

        return $row;
    }
}
