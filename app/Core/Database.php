<?php
/**
 * 自研数据库层：PDO 连接 + 链式查询构造器 + 事务
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private ?PDO $pdo = null;
    private static ?Database $instance = null;
    private float $lastActivity = 0.0;
    private bool $transactionActive = false;

    protected function connectionTime(): float
    {
        return hrtime(true) / 1e9;
    }

    protected function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) config('database.host', '127.0.0.1'),
            (int) config('database.port', 3306),
            (string) config('database.database', ''),
            (string) config('database.charset', 'utf8mb4')
        );
        try {
            return new PDO($dsn,
                (string) config('database.username', 'root'),
                (string) config('database.password', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, sql_mode='STRICT_TRANS_TABLES'",
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败：' . $e->getMessage(), 0, $e);
        }
    }

    private static function isDisconnected(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? $e->getCode()), [2006, 2013, 2055], true)
            || str_starts_with((string) ($e->errorInfo[0] ?? $e->getCode()), '08');
    }

    public function __construct()
    {
        self::$instance = $this;
    }

    public static function instance(): Database
    {
        return self::$instance ??= new Database();
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null && !$this->transactionActive
            && $this->connectionTime() - $this->lastActivity >= 5.0
            && !$this->pdo->inTransaction()) {
            try {
                $probe = $this->pdo->query('SELECT 1');
                $probe->closeCursor();
            } catch (PDOException $e) {
                if (!self::isDisconnected($e)) {
                    throw $e;
                }
                $this->pdo = null;
            }
        }
        if ($this->pdo === null) {
            $this->pdo = $this->createConnection();
        }
        $this->lastActivity = $this->connectionTime();

        return $this->pdo;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $bindings = [])
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function statement(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->statement($sql, $bindings);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback)
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $this->transactionActive = true;
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable $rollbackError) {
                error_log('Database rollback failed: ' . $rollbackError->getMessage());
            }
            throw $e;
        } finally {
            $this->transactionActive = false;
            $this->lastActivity = $this->connectionTime();
        }
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }
}

/**
 * 轻量查询构造器，覆盖常用 CRUD
 */
class QueryBuilder
{
    private Database $db;
    private string $table;
    /** @var string[] */
    private array $columns = ['*'];
    /** @var array<int, array{0:string,1:string,2:mixed}> */
    private array $wheres = [];
    private array $bindings = [];
    /** @var string[] 多级排序片段，按声明顺序拼接 */
    private array $orderBys = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function select(string ...$columns): self
    {
        $this->columns = $columns ?: ['*'];
        return $this;
    }

    /**
     * 支持两种调用形式：
     *   where('id', 1)                 → `id` = 1
     *   where('name', 'LIKE', '%foo%') → `name` LIKE '%foo%'
     */
    public function where(string $column, $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = (string) $operator;

        if ($value === null && !in_array(strtoupper($operator), ['IS NULL', 'IS NOT NULL'], true)) {
            $value = $operator;
            $operator = '=';
        }

        if (strtoupper($operator) === 'IN') {
            $values = (array) $value;
            if (empty($values)) {
                $this->wheres[] = ['1 = 0', 'RAW', []];
                return $this;
            }
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $this->wheres[] = ["`{$column}` IN ({$placeholders})", 'RAW', null];
            foreach ($values as $v) {
                $this->bindings[] = $v;
            }
            return $this;
        }

        $this->wheres[] = ["`{$column}` {$operator} ?", 'PARAM', $value];
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ["`{$column}` IS NULL", 'RAW', null];
        return $this;
    }

    /**
     * 原生条件片段，占位符用 ? 并配合 $bindings 传入
     *   whereRaw('(`model` LIKE ? OR `error` LIKE ?)', ['%a%', '%a%'])
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['(' . $sql . ')', 'RAW', null];
        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }
        return $this;
    }

    public function orderBy(string $column, string $direction = 'DESC'): self
    {
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        // 累积多级排序：多次调用按优先级依次追加（例如 is_default DESC, sort_order ASC, id ASC）
        $this->orderBys[] = "`{$column}` {$dir}";
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        [$whereSql, $bindings] = $this->buildWhere();

        $sql = 'SELECT ' . implode(', ', $this->columns) . " FROM `{$this->table}`" . $whereSql;
        if (!empty($this->orderBys)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . ($this->offset ?? 0);
        }

        return $this->db->select($sql, $bindings);
    }

    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        return $this->where('id', $id)->first();
    }

    public function first(): ?array
    {
        $this->limit = 1;
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `{$this->table}`" . $whereSql,
            $bindings
        );
    }

    /**
     * 构建 WHERE 与绑定参数（IN 子句在构建时展开）
     */
    private function buildWhere(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $parts = [];
        $bindings = [];

        $rawBindings = $this->bindings;

        foreach ($this->wheres as [$sql, $type, $value]) {
            if ($type === 'PARAM') {
                $parts[] = $sql;
                $bindings[] = $value;
            } elseif ($type === 'RAW' && str_contains($sql, '?')) {
                $parts[] = $sql;
                $count = substr_count($sql, '?');
                for ($i = 0; $i < $count; $i++) {
                    $bindings[] = array_shift($rawBindings);
                }
            } else {
                $parts[] = $sql;
            }
        }

        return [' WHERE ' . implode(' AND ', $parts), $bindings];
    }

    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        return $this->db->insert(
            "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function update(array $data): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        if ($whereSql === '') {
            throw new RuntimeException('更新操作必须带 WHERE 条件');
        }

        $sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));

        return $this->db->statement(
            "UPDATE `{$this->table}` SET {$sets}" . $whereSql,
            array_merge(array_values($data), $bindings)
        );
    }

    public function delete(): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        if ($whereSql === '') {
            throw new RuntimeException('删除操作必须带 WHERE 条件');
        }
        return $this->db->statement("DELETE FROM `{$this->table}`" . $whereSql, $bindings);
    }

    public function increment(string $column, int $amount = 1): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        return $this->db->statement(
            "UPDATE `{$this->table}` SET `{$column}` = `{$column}` + ?" . $whereSql,
            array_merge([$amount], $bindings)
        );
    }

    /**
     * 原生 upsert：唯一键冲突则更新指定列
     */
    public function upsert(array $data, array $updateColumns = []): void
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        $updateColumns = $updateColumns ?: array_diff($columns, ['id']);
        $updates = implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $updateColumns));

        $this->db->statement(
            "INSERT INTO `{$this->table}` ({$cols}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}",
            array_values($data)
        );
    }
}