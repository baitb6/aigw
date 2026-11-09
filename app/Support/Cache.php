<?php
/**
 * 极简文件缓存（用于「聚合统计」这类贵且不需要实时的结果）
 *
 * 为什么需要它：
 *   数据大屏一次要跑 20+ 个聚合查询、调用日志页每次刷新也要跑 8 个窗口聚合，
 *   这些都是**随数据量线性变慢**的全表/范围扫描。而大屏、趋势图、排行榜
 *   本来就只关心「分钟级」的新鲜度 —— 每次打开都重算一遍纯属浪费。
 *   缓存 60 秒后，无论表里有 1 万行还是 1000 万行，页面都是毫秒级。
 *
 * 设计取舍：
 *   - 存 `storage/tmp/cache/`，与其它运行期产物同处一地，可整目录删除（删了只是回源）；
 *   - 只缓存**数组**（JSON 往返），不缓存对象/闭包，避免反序列化安全问题；
 *   - 写入用临时文件 + rename，避免并发读到一个写了一半的文件；
 *   - 读失败/写失败一律静默降级为「未命中」——缓存坏掉不该让页面报错。
 */

declare(strict_types=1);

namespace App\Support;

final class Cache
{
    /** 缓存根目录（相对 storage/tmp） */
    private const DIR = 'tmp/cache';

    /**
     * 命中则返回缓存值，否则执行 $producer 并写入。
     *
     * @param callable():array $producer
     * @return array{value:array, cached:bool, age:int}
     */
    public static function remember(string $namespace, string $key, int $ttlSeconds, callable $producer): array
    {
        $hit = self::read($namespace, $key, $ttlSeconds);

        if ($hit !== null) {
            return ['value' => $hit['value'], 'cached' => true, 'age' => $hit['age']];
        }

        $value = $producer();
        self::write($namespace, $key, $value);

        return ['value' => $value, 'cached' => false, 'age' => 0];
    }

    /**
     * @return array{value:array, age:int}|null 未命中/过期/损坏均为 null
     */
    public static function read(string $namespace, string $key, int $ttlSeconds): ?array
    {
        $file = self::path($namespace, $key);

        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);

        if ($mtime === false) {
            return null;
        }

        $age = time() - $mtime;

        if ($age > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($file);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !array_key_exists('value', $decoded) || !is_array($decoded['value'])) {
            return null;
        }

        return ['value' => $decoded['value'], 'age' => max(0, $age)];
    }

    public static function write(string $namespace, string $key, array $value): void
    {
        $file = self::path($namespace, $key);
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $payload = json_encode(['value' => $value], JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            return;
        }

        // 临时文件 + rename：并发的读者要么看到旧文件、要么看到完整的新文件，
        // 不会读到写了一半的内容（rename 在同分区上是原子的）。
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return;
        }

        self::prune($dir);
    }

    /**
     * 顺手清理过期文件。
     *
     * 键里含筛选条件（如按用户/关键词收窄统计），组合可能很多；不清理会随
     * 「筛选组合数」缓慢堆积。只在目录文件数超过阈值时扫一次，平时几乎零成本。
     */
    private static function prune(string $dir): void
    {
        $files = @scandir($dir);

        if (!is_array($files) || count($files) <= 128) {
            return;
        }

        // 保留窗口给足：最长的 TTL 是 300 秒，这里留 1 小时余量再删
        $cutoff = time() - 3600;

        foreach ($files as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);

            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    /** 让某个命名空间整体失效（数据被写入后想让大屏立刻反映时可调用） */
    public static function flush(string $namespace): void
    {
        $dir = storage_path(self::DIR . '/' . self::safe($namespace));

        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) @scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function path(string $namespace, string $key): string
    {
        // 键取哈希：筛选条件里可能含任意字符（模型名、关键词），哈希后既安全又定长
        return storage_path(self::DIR . '/' . self::safe($namespace) . '/' . sha1($key) . '.json');
    }

    private static function safe(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $name);

        return $clean === '' ? 'default' : $clean;
    }
}
