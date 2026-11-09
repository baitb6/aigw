<?php
/**
 * 运行时结构自愈：为老库幂等补齐新增表与新增列
 *
 * 项目采用「就地升级」策略，不重建表。这里在每个进程首次访问时：
 *   1. 检查并创建缺失的新表（签到日志 / 账号+模型冷却 / 会话粘性 / 旅行日志）
 *   2. 检查并按需 ALTER 追加新增列（积分到期 / 退避 / 租约 / 缓存 Token / 来源 等）
 * 检查结果按进程缓存，避免重复查询 information_schema。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

final class Schema
{
    /**
     * 结构版本号 —— **改表结构时必须 +1**（第四条同改，见 docs/开发约定与踩坑.md）。
     *
     * 原「三处同改」（Schema.php + database/schema.sql + Repository 的 ADDED_COLUMNS）
     * 由此升级为四处：再加这里 +1，并删除 storage/schema-ok.json。
     * 不 +1 的后果：老库升级时哨兵会跳过自愈，新列永远补不上。
     */
    /**
     * 结构版本号 —— **改表结构时必须 +1**（第四条同改，见 docs/开发约定与踩坑.md）。
     *
     * 原「三处同改」（Schema.php + database/schema.sql + Repository 的 ADDED_COLUMNS）
     * 由此升级为四处：再加这里 +1，并删除 storage/schema-ok.json。
     * 不 +1 的后果：老库升级时哨兵会跳过自愈，新列永远补不上。
     *
     * ⚠️ 2026-09-30 修正：**新增列已不再依赖手改版本号**（见 ensureColumns 的列指纹）。
     *    本版本号现在只在「改名/删列/改类型」这类指纹表达不了的变更时才需要 +1。
     *
     * ⚠️ 2026-10-01 v2→v3：模型目录/供应商表新增 `user_id` 作用域列。
     *    单纯加列本可由列指纹自动重探，**但本次还改了唯一键**
     *    （`uk_model` 由 (provider_key,model_id) 扩成 (provider_key,model_id,user_id)），
     *    唯一键变更是指纹表达不了的，故必须 +1 让老库的哨兵失效、重跑一次自愈。
     *
     * ⚠️ 2026-10-02 v3→v4：新增 `aigw_action_task`（异步动作任务表）。
     *    新增「表」而非「列」时，`loadSentinel()['tables']` 会因哨兵命中而**跳过建表**，
     *    故必须 +1 让 `tables` 条目失效、重跑一次建表（否则新表永不创建，静默漂移）。
     *
     * ⚠️ 2026-10-04 v4→v5：新增四张 Operit 生态表
     *    （`aigw_mcp_server` / `aigw_memory` / `aigw_workflow` / `aigw_workflow_task` /
     *    `aigw_speech_log`）。同上，新增「表」必须 +1 让 tables 条目失效重建。
     * ⚠️ 2026-10-04 v5→v6：新增 Operit 生态第二批表
     *    （`aigw_worldbook` 世界书条目 / `aigw_plan` 计划模式计划 /
     *    `aigw_research_task` 深度研究任务）。
     *    同上，新增「表」必须 +1 让 tables 条目失效重建。
     * ⚠️ 2026-10-04 v6→v7：新增 `aigw_chat_setting`（对话增强设置：
     *    楼层裁剪 / 思考引导 / 世界书开关）。同上，必须 +1 重建。
     * ⚠️ 2026-10-04 v7→v8：新增四张 Operit 生态表
     *    （`aigw_plugin` 插件 / `aigw_env_var` 环境变量 /
     *     `aigw_model_profile` 模型档案 / `aigw_character_card` 角色卡）。
     *    同上，新增「表」必须 +1 让 tables 条目失效重建。
     * ⚠️ 2026-10-04 v8→v9：新增 `aigw_subagent_run`（被动式子代理运行记录，
     *    参考 Operit 的 subagent 插件）。同上，必须 +1 重建。
     * ⚠️ 2026-10-07 v9→v10：新增 `aigw_response`（Responses API 持久化与后台任务）。
     *    同上，新增「表」必须 +1 让 tables 条目失效重建。
     */
    private const SCHEMA_VERSION = 10;

    /** 结构哨兵文件：内容匹配当前版本即跳过全部 information_schema 探测 */
    private const SENTINEL_FILE = 'schema-ok.json';

    private static bool $tablesEnsured = false;

    /** @var array<string, bool> 已检查过列的物理表 */
    private static array $columnChecked = [];

    /** 是否已检查过用户邮箱唯一索引 */
    private static bool $userEmailIndexChecked = false;

    /** 是否已检查过模型表作用域唯一键（uk_model 是否含 user_id） */
    private static bool $modelUniqueChecked = false;

    /**
     * 哨兵路径
     */
    private static function sentinelPath(): string
    {
        return AIGW_ROOT . '/storage/' . self::SENTINEL_FILE;
    }

    /**
     * 读取哨兵（版本不匹配一律当作"未就绪"）
     *
     * 结构：{ "v": <版本>, "tables": 1, "cols": { "<表名>": "<列指纹>", ... } }
     *
     * ⚠️ 血案（2026-09-30）：哨兵最初只有 `v` 一个字段，且 `ensure()`（核表）在
     *    **核完表就落哨兵**；而 `ensureColumns()`（核列）是靠各 Repository 构造时
     *    才调用的 —— 于是同一请求里「先 ensure 落哨兵 → 后 ensureColumns 读到哨兵
     *    命中 → 直接 return」，**列探测被整体跳过**，新增列永远补不上，
     *    且哨兵文件照常写入、界面无任何报错，属典型静默漂移。
     *    更坑的是 `ensure()` 与 `ensureColumns()` 谁先跑取决于当次请求走了哪些
     *    Repository，表现为「有时补上、有时补不上」。
     *    修法：把「表」与「每张表的列」拆成**独立可校验**的条目，各自单独落盘。
     */
    private static function loadSentinel(): array
    {
        $raw = @file_get_contents(self::sentinelPath());
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== self::SCHEMA_VERSION) {
            return [];
        }

        return $decoded;
    }

    /**
     * 合并写回哨兵（**不得整体覆盖** —— `ensure` 与 `ensureColumns` 分属不同调用时机，
     * 直接覆盖会把对方刚写好的条目抹掉，又变成静默不生效）
     */
    private static function saveSentinel(array $data): void
    {
        $data['v'] = self::SCHEMA_VERSION;
        $data['at'] = time();

        $dir = dirname(self::sentinelPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents(
            self::sentinelPath(),
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 幂等创建缺失的新表
     */
    public static function ensure(Database $db): void
    {
        if (self::$tablesEnsured) {
            return;
        }
        self::$tablesEnsured = true;

        // 哨兵命中「表已核对」：跳过 16 次 information_schema 探测。
        // 实测：一张表的结构探测 0.80 ms，16 张 = 12.8 ms，而普通查询仅 0.07 ms
        // （information_schema 贵 11 倍）；PHP 无跨进程常驻内存，这笔开销每个
        // worker 冷启动都要重付一次，故必须持久化跳过。
        if (!empty(self::loadSentinel()['tables'])) {
            return;
        }

        try {
            foreach (self::TABLES as $table => $ddl) {
                if (!self::tableExists($db, $table)) {
                    $db->statement($ddl);
                }
            }
            // 全部表核对完毕且无异常 → 记「表已核对」，后续进程冷启动直接跳过
            $s = self::loadSentinel();
            $s['tables'] = 1;
            self::saveSentinel($s);
        } catch (\Throwable $e) {
            // 自愈失败**不落哨兵**，让下一个进程再试（否则会把结构缺陷永久固化）
            Logger::warn('数据表补齐失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 幂等追加缺失的列（同一张表每进程只查一次）
     *
     * 判据用「列指纹」而非「版本号」：把传入的列名集合算 md5，与哨兵里该表上次
     * 核对成功的指纹比对。**只要列集合变了（= 代码里新增了列），指纹就不同，
     * 自动重新探测并补齐** —— 不再依赖开发者记得手改 SCHEMA_VERSION。
     * 这消除了「新增列忘记 +1 → 新列永远补不上」这个项目最高频的静默漂移源。
     *
     * @param array<string, string> $columns 列名 => 列定义
     */
    public static function ensureColumns(Database $db, string $table, array $columns): void
    {
        if (isset(self::$columnChecked[$table])) {
            return;
        }
        self::$columnChecked[$table] = true;

        // 列指纹只取「列名」不取定义：改类型/注释属于另一类变更（需升版本号），
        // 而新增列是本类的高频场景 —— 只认列名可避免改注释就触发全量重探。
        $fingerprint = md5(implode('|', array_keys($columns)));

        $sentinel = self::loadSentinel();
        if (($sentinel['cols'][$table] ?? '') === $fingerprint) {
            return; // 这张表的这组列已核对过 → 跳过 information_schema.COLUMNS 探测
        }

        try {
            $existing = $db->select(
                'SELECT `COLUMN_NAME` AS `name` FROM information_schema.COLUMNS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
                [$table]
            );
            $names = array_column($existing, 'name');

            foreach ($columns as $column => $definition) {
                if (!in_array($column, $names, true)) {
                    $db->statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                }
            }

            // 核对成功才记指纹（失败走 catch，不落盘 → 下一进程重试）
            $s = self::loadSentinel();
            $s['cols'][$table] = $fingerprint;
            self::saveSentinel($s);
        } catch (\Throwable $e) {
            Logger::warn('数据列补齐失败', ['table' => $table, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 幂等补齐 aigw_user.email 唯一索引（老库由普通索引升级而来）
     *
     * 邮箱是注册主身份，唯一索引可从数据库层面杜绝并发注册撞库。
     * 先尝试新增唯一索引，成功后再移除遗留的非唯一同名/旧索引；
     * 若存量数据本身有重复（历史脏数据），则保留旧索引并记录告警，不影响启动。
     */
    public static function ensureUserEmailUnique(Database $db): void
    {
        if (self::$userEmailIndexChecked) {
            return;
        }
        self::$userEmailIndexChecked = true;

        try {
            $hasUnique = (bool) $db->scalar(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'aigw_user'
                   AND `COLUMN_NAME` = 'email' AND `NON_UNIQUE` = 0 LIMIT 1"
            );
            if ($hasUnique) {
                return;
            }

            $db->statement('ALTER TABLE `aigw_user` ADD UNIQUE KEY `uk_email` (`email`)');

            $legacy = $db->select(
                "SELECT DISTINCT `INDEX_NAME` AS `name` FROM information_schema.STATISTICS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'aigw_user'
                   AND `COLUMN_NAME` = 'email' AND `NON_UNIQUE` = 1"
            );
            foreach ($legacy as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name !== '') {
                    $db->statement("ALTER TABLE `aigw_user` DROP INDEX `{$name}`");
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('aigw_user.email 唯一索引补齐失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 幂等重建 aigw_model 的唯一键，把 user_id 纳入作用域（老库由 uk_model 升级而来）。
     *
     * 旧键：`uk_model`(provider_key, model_id)
     * 新键：`uk_model`(provider_key, model_id, user_id)
     *
     * 为什么必须重建：多个用户会在**同一个 provider_key**（如 `custom`）下同步各自的私有模型。
     * 键不含 user_id 时，用户 B 同步一个与 A 同名的模型会直接撞唯一键 —— 要么报错，
     * 要么（更糟）被 upsert 逻辑当成 A 的行覆盖掉。纳入 user_id 后，同名模型可以
     * 按用户各存一行、互不干扰。
     *
     * ⚠️ 唯一键变更**列指纹表达不了**，所以这一步必须显式检测 + 重建，
     *    且 SCHEMA_VERSION 必须 +1（否则老库哨兵命中、直接跳过本方法）。
     *
     * 重建前先做重复数据体检：老库若已有同 (provider_key,model_id,user_id) 的重复行
     * （理论上不该有，因为旧键更严），重建唯一键会失败 —— 此时保留旧键并告警，不阻塞启动。
     */
    public static function ensureModelScopedUnique(Database $db): void
    {
        if (self::$modelUniqueChecked) {
            return;
        }
        self::$modelUniqueChecked = true;

        try {
            // 新键已就位？（含 user_id 的唯一索引）
            $hasScoped = (bool) $db->scalar(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'aigw_model'
                   AND `INDEX_NAME` = 'uk_model' AND `COLUMN_NAME` = 'user_id' AND `NON_UNIQUE` = 0
                 LIMIT 1"
            );
            if ($hasScoped) {
                return;
            }

            // 老键存在才需要重建（全新安装由 schema.sql 直接建新键，走不到这里）
            $hasLegacy = (bool) $db->scalar(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'aigw_model'
                   AND `INDEX_NAME` = 'uk_model' AND `NON_UNIQUE` = 0
                 LIMIT 1"
            );
            if ($hasLegacy) {
                $db->statement('ALTER TABLE `aigw_model` DROP INDEX `uk_model`');
            }

            // 重复体检：同 (provider_key, model_id, user_id) 不得有重复行
            $dup = $db->first(
                'SELECT `provider_key`, `model_id`, `user_id`, COUNT(*) AS `c`
                   FROM `aigw_model`
                  GROUP BY `provider_key`, `model_id`, `user_id`
                 HAVING `c` > 1 LIMIT 1'
            );
            if ($dup !== null) {
                Logger::warn('aigw_model 存在重复行，唯一键未重建', [
                    'provider_key' => (string) ($dup['provider_key'] ?? ''),
                    'model_id'     => (string) ($dup['model_id'] ?? ''),
                    'user_id'      => (int) ($dup['user_id'] ?? 0),
                    'count'        => (int) ($dup['c'] ?? 0),
                ]);
                return;
            }

            $db->statement(
                'ALTER TABLE `aigw_model` ADD UNIQUE KEY `uk_model` (`provider_key`, `model_id`, `user_id`)'
            );
        } catch (\Throwable $e) {
            Logger::warn('aigw_model 唯一键重建失败', ['error' => $e->getMessage()]);
        }
    }

    private static function tableExists(Database $db, string $table): bool
    {
        return (bool) $db->scalar(
            'SELECT 1 FROM information_schema.TABLES
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? LIMIT 1',
            [$table]
        );
    }

    /**
     * 新表定义（与 database/schema.sql 保持一致，两处同步维护）
     */
    private const TABLES = [
        'aigw_checkin_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_checkin_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`   VARCHAR(32)  NOT NULL DEFAULT '',
  `account_uid`    VARCHAR(128) NOT NULL DEFAULT '',
  `nickname`       VARCHAR(128) NOT NULL DEFAULT '',
  `status`         VARCHAR(16)  NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS|ALREADY|FAILED|SKIPPED',
  `message`        VARCHAR(255) NOT NULL DEFAULT '',
  `credits_before` DECIMAL(16,2) NULL,
  `credits_after`  DECIMAL(16,2) NULL,
  `trigger`        VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual|scheduled|startup',
  `checked_at`     BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checked` (`checked_at`),
  KEY `idx_account` (`provider_key`, `account_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='签到日志（保留 30 天）'
SQL,

        'aigw_account_model_cooldown' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_account_model_cooldown` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`  VARCHAR(32)  NOT NULL DEFAULT '',
  `account_uid`   VARCHAR(128) NOT NULL DEFAULT '',
  `model`         VARCHAR(160) NOT NULL DEFAULT '',
  `reason`        VARCHAR(255) NOT NULL DEFAULT '',
  `cooling_until` BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_acct_model` (`provider_key`, `account_uid`, `model`),
  KEY `idx_cooling` (`cooling_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账号+模型级限流冷却隔离'
SQL,

        'aigw_session_affinity' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_session_affinity` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key`  VARCHAR(80)  NOT NULL COMMENT '客户端会话标识（chat_id/user/自定义）',
  `provider_key` VARCHAR(32)  NOT NULL DEFAULT '',
  `model`        VARCHAR(160) NOT NULL DEFAULT '',
  `account_uid`  VARCHAR(128) NOT NULL DEFAULT '',
  `expires_at`   BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session` (`session_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话粘性绑定'
SQL,

        'aigw_travel_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_travel_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key` VARCHAR(32)  NOT NULL DEFAULT '',
  `account_uid`  VARCHAR(128) NOT NULL DEFAULT '',
  `travel_date`  DATE         NOT NULL,
  `state`        VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'idle|traveling|arrived|adopted',
  `location`     VARCHAR(64)  NOT NULL DEFAULT '',
  `reward`       VARCHAR(255) NOT NULL DEFAULT '',
  `message`      VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_travel_day` (`provider_key`, `account_uid`, `travel_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='猫猫旅行每日幂等日志'
SQL,

        'aigw_activity_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_activity_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`  VARCHAR(32)  NOT NULL DEFAULT '',
  `account_uid`   VARCHAR(128) NOT NULL DEFAULT '',
  `activity_date` DATE         NOT NULL,
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS|FAILED',
  `model`         VARCHAR(160) NOT NULL DEFAULT '',
  `message`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_day` (`provider_key`, `account_uid`, `activity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日活跃注入幂等日志'
SQL,

        'aigw_login_lockout' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_login_lockout` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`         VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'user|admin',
  `identity`      VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'ip:<sha1> | pair:<sha1>',
  `fails`         INT          NOT NULL DEFAULT 0,
  `first_fail_at` BIGINT       NOT NULL DEFAULT 0 COMMENT '本轮首次失败时间(秒)',
  `locked_until`  BIGINT       NOT NULL DEFAULT 0 COMMENT '锁定到期(秒)',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_identity` (`scope`, `identity`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录失败锁定（IP+账号维度，持久化防清 cookie 绕过）'
SQL,

        'aigw_user' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_user` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL,
  `email`         VARCHAR(128) NOT NULL DEFAULT '',
  `password`      VARCHAR(255) NOT NULL COMMENT 'password_hash 结果',
  `nickname`      VARCHAR(64)  NOT NULL DEFAULT '',
  `api_key`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT '用户独立对外调用密钥',
  `status`        VARCHAR(16)  NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|DISABLED',
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(64)  NOT NULL DEFAULT '',
  `session_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话纪元：改密码时 +1，使旧会话立即失效',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_user_api_key` (`api_key`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='普通用户账号'
SQL,

        'aigw_prompt_template' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_prompt_template` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID，0=管理员/全局',
  `name`        VARCHAR(64)  NOT NULL COMMENT '模板名称',
  `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '用途说明',
  `content`     TEXT         NULL COMMENT '提示词正文（system）',
  `mode`        VARCHAR(16)  NOT NULL DEFAULT 'prepend' COMMENT '注入位置 prepend|append|replace',
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '模板启用开关',
  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否为自动注入的默认模板',
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_default` (`user_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提示词模板'
SQL,

        'aigw_rate_limit' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_rate_limit` (
  `bucket`     VARCHAR(160)    NOT NULL COMMENT '限流桶标识：api_key 哈希 或 客户端 IP',
  `tokens`     BIGINT          NOT NULL DEFAULT 0 COMMENT '剩余令牌（千分位整数，1000=1个令牌）',
  `updated_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次令牌刷新时间(ms)',
  PRIMARY KEY (`bucket`),
  KEY `idx_updated` (`updated_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网关对外限流令牌桶'
SQL,

        // ── 思维注入（Persona Injection）────────────────────────────
        // 语义与提示词模板一致：user_id=0 为全局思维，>0 为用户自建思维。
        // 网关在转发前把生效思维整体写入 system 提示词，并记录到 aigw_persona_target。
        'aigw_persona' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_persona` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID，0=管理员/全局',
  `name`           VARCHAR(64)  NOT NULL COMMENT '思维名称',
  `description`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '用途说明',
  `content`        MEDIUMTEXT   NULL COMMENT '思维正文（注入到 system 的规则块）',
  `mode`           VARCHAR(16)  NOT NULL DEFAULT 'prepend' COMMENT '注入位置 prepend|append|replace',
  `enabled`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '思维启用开关',
  `is_default`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否为该作用域自动注入的默认思维',
  `version`        INT          NOT NULL DEFAULT 1 COMMENT '思维版本号，每次保存自增',
  `seal`           CHAR(64)     NOT NULL DEFAULT '' COMMENT '正文 SHA256 封条，用于检测注入后被改写',
  `seal_checked_at` DATETIME    NULL COMMENT '最近一次封条校验时间',
  `targets`        TEXT         NULL COMMENT '生效靶点 JSON 数组（供应商 key）；空=全部已启用供应商',
  `models`         TEXT         NULL COMMENT '生效模型 JSON 数组（模型 ID，支持尾部 * 前缀通配）；空=全部模型',
  `selfcheck_on`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否启用自证口令拦截',
  `selfcheck_pass` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '自证口令（整条消息恰为此串时命中）',
  `selfcheck_reply` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '自证命中时返回的固定回执',
  `hit_count`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计注入次数',
  `last_hit_at`    DATETIME     NULL COMMENT '最近一次注入时间',
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_default` (`user_id`, `is_default`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维库（网关注入用）'
SQL,

        'aigw_persona_revision' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_persona_revision` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `persona_id` BIGINT UNSIGNED NOT NULL COMMENT '所属思维ID',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID（随思维，便于越权过滤）',
  `version`    INT          NOT NULL DEFAULT 1 COMMENT '该快照对应的版本号',
  `content`    MEDIUMTEXT   NULL COMMENT '该版本的正文快照',
  `seal`       CHAR(64)     NOT NULL DEFAULT '' COMMENT '该版本封条',
  `note`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '变更说明 / 还原点备注',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_persona` (`persona_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维修订快照（备份与还原）'
SQL,

        'aigw_persona_target' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_persona_target` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `persona_id`     BIGINT UNSIGNED NOT NULL COMMENT '所属思维ID',
  `target`         VARCHAR(64)  NOT NULL COMMENT '靶点：供应商 key',
  `enabled`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '该靶点是否注入',
  `inject_count`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '该靶点累计注入次数',
  `last_inject_at` DATETIME     NULL COMMENT '该靶点最近一次注入时间',
  `last_error`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次注入失败原因',
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_persona_target` (`persona_id`, `target`),
  KEY `idx_target` (`target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维注入靶点状态（按供应商）'
SQL,

        // 用户级平台开关：普通用户自选「在哪些平台上接受思维注入」。
        // 与 aigw_persona_target（挂在某一份思维上）不同，这张表挂在用户上，
        // 是「这个平台我到底要不要任何思维」的总闸，对所有层生效。
        // 注意：**无记录 = 关闭**（开箱默认全关，用户要显式打开才注入）。
        'aigw_persona_user_target' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_persona_user_target` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '普通用户ID',
  `target`     VARCHAR(64)  NOT NULL COMMENT '平台：供应商 key',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '该用户在该平台上是否接受注入（默认关）',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_target` (`user_id`, `target`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级平台开关（普通用户自选启用注入的平台，默认全关）'
SQL,

        // 用户级思维开关：普通用户自选「哪几份思维对我生效」。
        // 只对**全局（系统）思维**留记录 —— 用户自建思维的开关就是它自己的 enabled 字段。
        // **无记录 = 关闭**：普通用户开箱不注入任何思维，必须自己显式打开。
        'aigw_persona_user_enable' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_persona_user_enable` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '普通用户ID',
  `persona_id` BIGINT UNSIGNED NOT NULL COMMENT '被开关的思维ID（通常指向全局思维）',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '该用户是否让这份思维对自己生效（默认关）',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_persona` (`user_id`, `persona_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级思维开关（普通用户自选启用的思维，默认全关）'
SQL,

        // 用户行为审计流水：把「谁在什么时候对什么做了什么」逐条落库。
        // 与 aigw_call_log（网关转发明细）互补 —— 这张表记的是"操作事件"，
        // 覆盖登录/注册/改密/改资料/Key、思维与模板的增删改、账号增删改、管理员管控动作。
        // detail 存 JSON 明细（变更前后值等），便于管理台展开回溯。
        'aigw_audit_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '行为归属用户ID（被操作用户/操作用户本人），0=系统或全局',
  `actor_type`  VARCHAR(16)  NOT NULL DEFAULT 'user' COMMENT '操作者身份：user|admin|system',
  `actor_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作者ID（管理员为管理员ID）',
  `actor_name`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '操作者名称快照',
  `action`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '事件键，如 login.success / persona.create',
  `category`    VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '分类：auth|persona|template|account|admin|gateway|model|provider',
  `target_type` VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '对象类型，如 persona/template/account/user',
  `target_id`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '对象ID或键',
  `summary`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '一句话摘要（管理台列表直接展示）',
  `detail`      TEXT         NULL COMMENT 'JSON 明细（变更前后值、参数片段等）',
  `ip`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '客户端真实 IP',
  `ua`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'User-Agent（截断）',
  `method`      VARCHAR(8)   NOT NULL DEFAULT '' COMMENT 'HTTP 方法',
  `path`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '请求路径',
  `status`      SMALLINT     NOT NULL DEFAULT 200 COMMENT '结果状态码：200/400/401/403/404/409/429/500',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `id`),
  KEY `idx_actor` (`actor_type`, `actor_id`),
  KEY `idx_action` (`action`),
  KEY `idx_category_time` (`category`, `id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户行为审计流水（谁在何时对什么做了什么）'
SQL,

        // 邮箱验证码：注册 / 找回密码的一次性校验码。
        // 只存 SHA-256（email|purpose|code 加盐哈希），明文仅存在于发信那一刻。
        'aigw_email_code' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_email_code` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(190) NOT NULL COMMENT '目标邮箱（小写归一）',
  `purpose`     VARCHAR(32)  NOT NULL DEFAULT 'register' COMMENT '用途：register|reset|bind',
  `code_hash`   CHAR(64)     NOT NULL DEFAULT '' COMMENT '验证码 SHA-256（不存明文）',
  `expires_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '到期时间戳（秒）',
  `attempts`    INT          NOT NULL DEFAULT 0 COMMENT '已校验次数',
  `used_at`     BIGINT       NOT NULL DEFAULT 0 COMMENT '使用时间戳，0=未使用',
  `ip`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '请求来源 IP',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_purpose` (`email`, `purpose`, `id`),
  KEY `idx_ip_time` (`ip`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码（注册/找回密码）'
SQL,

        // 异步动作任务：把「耗时长、会被 CDN 回源超时（524）掐断」的动作
        // （典型：ZCode 领额度，要跑「拉配置 + 无痕验证求解 9~75s + claim」）
        // 改成「提交即返回 task_id → 后台 worker 执行 → 前端轮询结果」。
        // 关键：HTTP 请求在提交后立刻结束，不再占用 CDN 的回源连接。
        'aigw_action_task' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_action_task` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`      CHAR(32)     NOT NULL COMMENT '对外任务号（随机 hex）',
  `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `provider_key` VARCHAR(32)  NOT NULL DEFAULT '',
  `account_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `account_uid`  VARCHAR(128) NOT NULL DEFAULT '',
  `action`       VARCHAR(32)  NOT NULL DEFAULT '',
  `params`       JSON         NULL COMMENT '动作入参（含 plan_id 等）',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|RUNNING|DONE|FAILED',
  `ok`           TINYINT(1)   NOT NULL DEFAULT 0,
  `message`      VARCHAR(512) NOT NULL DEFAULT '',
  `result`       JSON         NULL COMMENT '动作返回 data',
  `error_code`   VARCHAR(32)  NOT NULL DEFAULT '',
  `worker_pid`   INT          NOT NULL DEFAULT 0,
  `started_at`   BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `finished_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`task_id`),
  KEY `idx_user_created` (`user_id`, `id`),
  KEY `idx_status_created` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异步动作任务（绕开 CDN 回源超时）'
SQL,

        // MCP 服务器配置（参考 Operit 的 MCPServerConfig / MCPRepository）。
        // 支持两种传输：remote（httpStream/sse，直连）与 local（stdio，PHP proc_open 托管）。
        'aigw_mcp_server' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_mcp_server` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`            VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '服务名（唯一，工具前缀）',
  `transport`       VARCHAR(16)  NOT NULL DEFAULT 'remote' COMMENT 'remote|local',
  `connection_type` VARCHAR(16)  NOT NULL DEFAULT 'httpStream' COMMENT 'httpStream|sse',
  `endpoint`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'remote: 服务地址',
  `command`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'local: 启动命令',
  `args`            TEXT         NULL COMMENT 'local: 命令参数（JSON 数组）',
  `env`             TEXT         NULL COMMENT 'local: 环境变量（JSON 对象）',
  `headers`         TEXT         NULL COMMENT 'remote: 附加请求头（JSON 对象）',
  `bearer_token`    VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'remote: Bearer Token（加密存储）',
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `tools`           JSON         NULL COMMENT '工具清单缓存（CachedToolInfo[]）',
  `tools_cached_at` BIGINT       NOT NULL DEFAULT 0 COMMENT '工具缓存时间(ms)',
  `last_status`     VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '最近连通状态 OK|FAIL',
  `last_message`    VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MCP 服务器配置（Operit 生态）'
SQL,

        // 向量记忆（参考 Operit 的 Memory / MemoryRepository）。
        // 无外部向量库依赖：向量以 JSON 存 TEXT，检索在 PHP 侧算余弦相似度。
        'aigw_memory' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_memory` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `title`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '记忆标题（同用户下唯一）',
  `content`     MEDIUMTEXT   NOT NULL COMMENT '记忆正文',
  `tags`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT '标签（逗号分隔）',
  `importance`  DECIMAL(6,3) NOT NULL DEFAULT 1.000 COMMENT '重要度权重',
  `embedding`   MEDIUMTEXT   NULL COMMENT '向量（JSON 数组，空=未生成）',
  `embed_model` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '生成向量的模型',
  `source`      VARCHAR(32)  NOT NULL DEFAULT 'manual' COMMENT 'manual|auto|import',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_updated` (`user_id`, `id`),
  UNIQUE KEY `uk_user_title` (`user_id`, `title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='向量记忆（Operit 生态）'
SQL,

        // 工作流定义（参考 Operit 的 Workflow / WorkflowExecutor）。
        // 节点图以 JSON 存储；调度支持 interval / specific_time / cron。
        'aigw_workflow' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_workflow` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`         VARCHAR(128) NOT NULL DEFAULT '',
  `description`  VARCHAR(512) NOT NULL DEFAULT '',
  `graph`        JSON         NULL COMMENT '节点图 {nodes,edges}',
  `schedule`     VARCHAR(32)  NOT NULL DEFAULT 'manual' COMMENT 'manual|interval|cron|specific_time',
  `interval`     INT          NOT NULL DEFAULT 0 COMMENT '周期秒（最小 900）',
  `cron`         VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'cron 表达式',
  `specific_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '定时执行时间(ms)',
  `next_run_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '下次执行时间(ms)',
  `enabled`      TINYINT(1)   NOT NULL DEFAULT 1,
  `last_run_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '最近执行时间(ms)',
  `last_status`  VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'OK|FAIL',
  `last_message` VARCHAR(512) NOT NULL DEFAULT '',
  `run_count`    INT          NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `id`),
  KEY `idx_due` (`enabled`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流定义（Operit 生态）'
SQL,

        // 工作流执行记录（对照 aigw_action_task 的异步模式）。
        'aigw_workflow_task' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_workflow_task` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     CHAR(32)     NOT NULL COMMENT '对外任务号（随机 hex）',
  `workflow_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `trigger`     VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual|schedule',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|RUNNING|DONE|FAILED',
  `ok`          TINYINT(1)   NOT NULL DEFAULT 0,
  `message`     VARCHAR(512) NOT NULL DEFAULT '',
  `steps`       JSON         NULL COMMENT '逐节点执行结果',
  `error_code`  VARCHAR(32)  NOT NULL DEFAULT '',
  `started_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `finished_at` BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`task_id`),
  KEY `idx_user_created` (`user_id`, `id`),
  KEY `idx_workflow` (`workflow_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流执行记录（Operit 生态）'
SQL,

        // 语音调用日志（STT/TTS 代理；用于计费/审计/排查，不含音频正文）。
        'aigw_speech_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_speech_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `kind`       VARCHAR(8)   NOT NULL DEFAULT 'stt' COMMENT 'stt|tts',
  `provider`   VARCHAR(32)  NOT NULL DEFAULT '',
  `model`      VARCHAR(64)  NOT NULL DEFAULT '',
  `bytes_in`   INT          NOT NULL DEFAULT 0 COMMENT '输入字节数',
  `bytes_out`  INT          NOT NULL DEFAULT 0 COMMENT '输出字节数',
  `text_len`   INT          NOT NULL DEFAULT 0 COMMENT '文本字符数',
  `duration_ms` INT         NOT NULL DEFAULT 0,
  `ok`         TINYINT(1)   NOT NULL DEFAULT 0,
  `message`    VARCHAR(512) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语音调用日志（Operit 生态）'
SQL,

        // 世界书条目（参考 Operit 的 WorldBookEntry / worldbook_service）。
        // 关键词/正则激活 → 注入 system 或历史指定深度，支持 always_active 常驻。
        'aigw_worldbook' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_worldbook` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`            VARCHAR(128) NOT NULL DEFAULT '' COMMENT '条目名称',
  `content`         MEDIUMTEXT   NOT NULL COMMENT '注入正文',
  `keywords`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT '触发关键词（逗号分隔）',
  `is_regex`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '关键词是否按正则匹配',
  `case_sensitive`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '匹配是否区分大小写',
  `always_active`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '常驻注入（无需关键词命中）',
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '启用开关',
  `priority`        INT          NOT NULL DEFAULT 50 COMMENT '优先级（大者先注入）',
  `scan_depth`      INT          NOT NULL DEFAULT 4 COMMENT '关键词扫描最近 N 条用户消息',
  `inject_target`   VARCHAR(16)  NOT NULL DEFAULT 'system' COMMENT 'system|user|assistant',
  `inject_position` VARCHAR(16)  NOT NULL DEFAULT 'append' COMMENT 'prepend|append|at_depth',
  `insertion_depth` INT          NOT NULL DEFAULT 0 COMMENT 'at_depth 时的插入深度',
  `group_name`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '分组（用于批量开关）',
  `hit_count`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计命中次数',
  `last_hit_at`     DATETIME     NULL COMMENT '最近命中时间',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `id`),
  KEY `idx_user_enabled` (`user_id`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='世界书条目（Operit 生态）'
SQL,

        // 计划模式（参考 Operit 的 Plan Mode）。
        // 计划先生成、待确认后执行；执行期间禁止直接改文件的工具。
        'aigw_plan' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_plan` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `session_key` VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '会话标识（按会话隔离计划）',
  `title`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '计划标题',
  `content`     MEDIUMTEXT   NOT NULL COMMENT '计划正文（Markdown）',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'DRAFT' COMMENT 'DRAFT|CONFIRMED|DONE',
  `source`      VARCHAR(32)  NOT NULL DEFAULT 'manual' COMMENT 'manual|ai',
  `confirmed_at` BIGINT      NOT NULL DEFAULT 0 COMMENT '确认执行时间(ms)',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_session` (`user_id`, `session_key`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划模式的计划（Operit 生态）'
SQL,

        // 深度研究任务（参考 Operit 的 DeepSearch 多轮研究）。
        // 异步执行：提交 → 后台 worker 跑「规划 → 分层执行子任务 → 汇总」 → 轮询结果。
        'aigw_research_task' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_research_task` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     CHAR(32)     NOT NULL COMMENT '对外任务号（随机 hex）',
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `topic`       VARCHAR(512) NOT NULL DEFAULT '' COMMENT '研究主题',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|RUNNING|DONE|FAILED',
  `ok`          TINYINT(1)   NOT NULL DEFAULT 0,
  `progress`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '当前阶段说明',
  `graph`       JSON         NULL COMMENT '执行图 {tasks:[...],final_summary_instruction}',
  `steps`       JSON         NULL COMMENT '逐子任务结果',
  `result`      MEDIUMTEXT   NULL COMMENT '最终汇总报告',
  `message`     VARCHAR(512) NOT NULL DEFAULT '',
  `error_code`  VARCHAR(32)  NOT NULL DEFAULT '',
  `started_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `finished_at` BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`task_id`),
  KEY `idx_user_created` (`user_id`, `id`),
  KEY `idx_status_created` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='深度研究任务（Operit 生态）'
SQL,

        // 对话增强设置（按用户 + 会话）。
        // 汇总 Operit 的三项「请求前改写」能力：
        //   · Context Limiter（楼层裁剪，保留系统消息 + 最近 N 轮）
        //   · Thinking Guidance（给非推理模型注入 <think> 思考引导）
        //   · WorldBook 开关（是否启用世界书注入）
        'aigw_chat_setting' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_chat_setting` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `session_key`          VARCHAR(80)  NOT NULL DEFAULT 'default' COMMENT '会话标识',
  `limiter_enabled`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否启用上下文楼层裁剪',
  `floor_limit`          INT          NOT NULL DEFAULT 5 COMMENT '保留最近 N 轮用户对话',
  `thinking_enabled`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否为非推理模型注入思考引导',
  `thinking_lang`        VARCHAR(8)   NOT NULL DEFAULT 'zh' COMMENT '引导语语言 zh|en',
  `worldbook_enabled`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否启用世界书注入',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_session` (`user_id`, `session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对话增强设置（Operit 生态）'
SQL,

        // 插件（参考 Operit 的 ToolPkg / manifest.json 热插拔机制）。
        // 元数据来自 manifest.json；启停状态与脚本正文由本表托管。
        'aigw_plugin' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_plugin` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `plugin_id`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'manifest.toolpkg_id（属主内唯一）',
  `name`            VARCHAR(128) NOT NULL DEFAULT '' COMMENT '展示名',
  `description`     VARCHAR(512) NOT NULL DEFAULT '',
  `version`         VARCHAR(32)  NOT NULL DEFAULT '',
  `api_version`     VARCHAR(32)  NOT NULL DEFAULT '',
  `entry`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'manifest.main 入口文件',
  `subpackages`     JSON         NULL COMMENT '子包清单',
  `manifest`        JSON         NULL COMMENT '完整 manifest 原文',
  `source`          VARCHAR(16)  NOT NULL DEFAULT 'upload' COMMENT 'upload|builtin',
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `status`          VARCHAR(16)  NOT NULL DEFAULT 'OK' COMMENT 'OK|FAIL',
  `status_message`  VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_plugin` (`user_id`, `plugin_id`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件（Operit ToolPkg 生态）'
SQL,

        // 环境变量（参考 Operit 的 EnvironmentVariableRead/Write）。
        // 属主隔离；敏感值经 Crypto 加密存储。
        'aigw_env_var' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_env_var` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '变量名',
  `value`       TEXT         NULL COMMENT '变量值（敏感时加密存储）',
  `is_secret`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否敏感（加密 + 不回明文）',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_name` (`user_id`, `name`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='环境变量（Operit 生态）'
SQL,

        // 模型档案（参考 Operit 的 ModelConfig CRUD + 功能模型绑定）。
        // 与网关账号池解耦：这是「用户自定义的 OpenAI 兼容端点档案」。
        'aigw_model_profile' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_model_profile` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`            VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '档案名（属主内唯一）',
  `provider_type`   VARCHAR(32)  NOT NULL DEFAULT 'openai' COMMENT 'openai|anthropic|gemini|custom',
  `api_endpoint`    VARCHAR(512) NOT NULL DEFAULT '',
  `api_key`         VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'API Key（加密存储）',
  `model_name`      VARCHAR(160) NOT NULL DEFAULT '',
  `custom_headers`  JSON         NULL COMMENT '自定义请求头',
  `custom_params`   JSON         NULL COMMENT '自定义参数',
  `enable_tool_call` TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '是否允许工具调用',
  `enable_vision`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否支持图片输入',
  `is_default`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否默认档案',
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
  `last_status`     VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '最近连通状态 OK|FAIL',
  `last_message`    VARCHAR(512) NOT NULL DEFAULT '',
  `last_checked_at` BIGINT       NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_name` (`user_id`, `name`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模型档案（Operit ModelConfig 生态）'
SQL,

        // 角色卡（参考 Operit 的 CharacterCard）。
        // 字段对齐 Operit CharacterCardResultItem；导入/导出兼容 SillyTavern PNG/JSON 的字段名。
        'aigw_character_card' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_character_card` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `name`                VARCHAR(128) NOT NULL DEFAULT '' COMMENT '角色名',
  `description`         TEXT         NULL COMMENT '角色简介',
  `character_setting`   TEXT         NULL COMMENT '角色设定（persona / system prompt）',
  `opening_statement`   TEXT         NULL COMMENT '开场白',
  `other_content_chat`  TEXT         NULL COMMENT '额外对话内容',
  `other_content_voice` TEXT         NULL COMMENT '额外语音内容',
  `advanced_custom_prompt` TEXT      NULL COMMENT '高级自定义提示词',
  `tags`                JSON         NULL COMMENT '标签数组',
  `marks`               VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注标记',
  `avatar`              TEXT         NULL COMMENT '头像（URL 或 base64）',
  `model_profile_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定的模型档案（0=跟随全局）',
  `is_default`          TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否为当前激活角色（属主内唯一）',
  `source`              VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual|import',
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_name` (`user_id`, `name`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色卡（Operit CharacterCard 生态）'
SQL,

        // 子代理运行记录（参考 Operit 的被动式 Subagent / subagent_run 工具）。
        // 主 agent 委托一个隔离子任务给子代理，服务端跑一次独立会话并回传总结。
        'aigw_subagent_run' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_subagent_run` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `run_id`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '运行 ID（s+base36）',
  `model`        VARCHAR(160) NOT NULL DEFAULT '' COMMENT '子任务所用模型',
  `task`         TEXT         NULL COMMENT '委托任务正文',
  `context_text` TEXT         NULL COMMENT '补充上下文',
  `target_paths` JSON         NULL COMMENT '优先处理路径列表',
  `max_tool_calls` INT        NOT NULL DEFAULT 8 COMMENT '建议最大工具调用数',
  `tool_count`   INT          NOT NULL DEFAULT 0 COMMENT '实际上报工具调用数',
  `status`       VARCHAR(16)  NOT NULL DEFAULT 'running' COMMENT 'running|success|fail',
  `summary`      TEXT         NULL COMMENT '最终总结（裁到 320 字）',
  `error`        VARCHAR(512) NOT NULL DEFAULT '',
  `duration_ms`  BIGINT       NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_run` (`run_id`),
  KEY `idx_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='子代理运行记录（Operit Subagent 生态）'
SQL,

        // OpenAI Responses API 响应对象与后台执行状态。
        'aigw_response' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `aigw_response` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '属主（0=管理员/全局）',
  `response_id`          VARCHAR(64)  NOT NULL COMMENT '对外 response id',
  `previous_response_id` VARCHAR(64)  NOT NULL DEFAULT '',
  `status`               VARCHAR(16)  NOT NULL DEFAULT 'queued' COMMENT 'queued|in_progress|completed|incomplete|failed',
  `model`                VARCHAR(160) NOT NULL DEFAULT '',
  `response_object`      MEDIUMTEXT   NULL COMMENT '完整 Responses API 对象 JSON',
  `context_messages`     MEDIUMTEXT   NULL COMMENT '供 previous_response_id 恢复的累计消息 JSON',
  `request_payload`      MEDIUMTEXT   NULL COMMENT '后台 worker 所需请求 JSON',
  `background`           TINYINT(1)   NOT NULL DEFAULT 0,
  `cancel_requested`     TINYINT(1)   NOT NULL DEFAULT 0,
  `worker_pid`           INT          NOT NULL DEFAULT 0,
  `started_at`           BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `finished_at`          BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_response` (`user_id`, `response_id`),
  KEY `idx_user_created` (`user_id`, `id`),
  KEY `idx_status_created` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OpenAI Responses API 响应对象与后台任务'
SQL,
    ];

    /** 账号表新增列 */
    public const ACCOUNT_COLUMNS = [
        'credits_expire_at' => 'BIGINT NOT NULL DEFAULT 0 COMMENT \'积分到期时间(ms)\'',
        'next_retry_at'     => 'BIGINT NOT NULL DEFAULT 0 COMMENT \'指数退避下次可试时间(ms)\'',
        'lease_until'       => 'BIGINT NOT NULL DEFAULT 0 COMMENT \'在途租约到期(ms)\'',
        'checkin_at'        => 'BIGINT NOT NULL DEFAULT 0 COMMENT \'最近签到时间(ms)\'',
        'last_error_at'     => 'BIGINT NOT NULL DEFAULT 0 COMMENT \'最近一次计错时间(ms)，用于错误计数时间窗衰减\'',
        'user_id'           => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'归属用户ID，0=管理员/全局\'',
    ];

    /**
     * 模型目录新增列
     *
     * `user_id` 作用域语义（0=全局/内置，>0=某用户私有）：
     *  - 管理员可见 **全部** 行（不含过滤）；
     *  - 普通用户只可见 `user_id = 0`（管理员下发的公共模型）**或** `user_id = 本人` 的行。
     *
     * 为什么必须隔离：`custom` 供应商是**所有用户共用的同一个 provider_key**，
     * 每个账号各自带 `base_url`（用户自填的私有中转站）。若不隔离，用户 A 同步自己
     * 私有端点后，模型名会写进公共目录 —— 用户 B 既能看到 A 的私有模型清单（泄露上游
     * 构成），又能对同一行执行 `enabled=0`，把 A 的模型一并停掉。
     */
    public const MODEL_COLUMNS = [
        'user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'归属用户ID，0=管理员/全局\'',
    ];

    /**
     * 普通用户表新增列
     *
     * `session_epoch`：会话纪元。改密码 / 高敏操作时 +1，会话里存的是登录那一刻的
     * 水位 —— 两者不等即视为「凭据已变更」，旧会话立即失效。没有它的话，「改密码后
     * 踢掉其他端」只能靠清空自己的 session 文件，而同账号在别处的会话仍然通行。
     */
    public const USER_COLUMNS = [
        'session_epoch' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'会话纪元：改密码时 +1，使旧会话立即失效\'',
    ];

    /**
     * 管理台账号表新增列（审计 M-2 修复）
     *
     * `session_epoch`：与用户端同义。此前管理端改密后旧会话仍可用满 7 天 TTL，
     * 凭据泄露场景下改密无法止损；补齐纪元机制后改密即踢掉所有其他端会话。
     */
    public const ADMIN_COLUMNS = [
        'session_epoch' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'会话纪元：改密码时 +1，使旧会话立即失效\'',
    ];

    /**
     * 供应商表新增列
     *
     * 用户自建的自定义供应商实例归属本人（user_id>0）；内置与管理员创建的仍为 0。
     * ⚠️ `aigw_provider.provider_key` 上有**全局唯一键**，所以用户自建实例的 key
     * 必须是 `custom_<用户ID>_<slug>` 形式，否则两个用户用同一个名字会互相顶掉。
     */
    public const PROVIDER_COLUMNS = [
        'user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'归属用户ID，0=管理员/全局\'',
    ];

    /** 调用日志新增列 */
    public const CALL_LOG_COLUMNS = [        'cache_read_tokens'  => 'INT NOT NULL DEFAULT 0 COMMENT \'缓存命中读取 Token\'',
        'cache_write_tokens' => 'INT NOT NULL DEFAULT 0 COMMENT \'缓存写入 Token\'',
        'source'             => 'VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'来源：workbuddy|workbuddy_intl|codebuddy_cli|codebuddy_cn_ide\'',
        'session_key'        => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
        'user_id'            => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'归属用户ID，0=管理员/全局\'',
        // 思维注入留痕：哪一次调用用了哪份思维、是否命中自证口令
        'persona_id'         => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'本次注入的思维ID，0=未注入\'',
        'persona_hit'        => 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'是否命中思维自证口令（未打上游直回）\'',
        // 内容留痕：仅在网关设置「记录请求体」(log_request_body) 打开时写入，且按 log_content_max_chars 截断。
        //
        // 存储形态：请求/回复正文可能带思维注入块（8~10KB/条），原样落库会让日志表成为库体积唯一大头。
        // 超过 BODY_COMPRESS_MIN 的内容用 gzip 压缩后 base64 存入，读取侧按 log_body_encoding 还原 ——
        // 实测真实中文 JSON 正文可压到 55%，重复度高的长会话可压到 1% 以下。
        'request_body'       => 'MEDIUMTEXT NULL COMMENT \'请求内容快照（压缩后为 gz:b64，见 log_body_encoding）\'',
        'response_body'      => 'MEDIUMTEXT NULL COMMENT \'回复内容快照（压缩后为 gz:b64，见 log_body_encoding）\'',
        'log_body_encoding'  => 'VARCHAR(16) NOT NULL DEFAULT \'\' COMMENT \'正文编码：空=明文 / gz:b64=gzip+base64\'',
        // 明文字符数：写入时算好，列表直接读，避免为了显示"这条多大"去解压正文。
        // 压缩态无法 O(1) 得到明文长度，靠压缩比估算在长会话场景误差可达数十倍（实测
        // 估算 796 而实际 23974），所以必须真实记录。
        'req_body_length'    => 'INT NOT NULL DEFAULT 0 COMMENT \'请求正文明文字符数（0=未记录）\'',
        'res_body_length'    => 'INT NOT NULL DEFAULT 0 COMMENT \'回复正文明文字符数（0=未记录）\'',
        // 收尾诊断：区分「输出被截断」的两种真因（上游 length 截断 / 推理占满 token / 上游提前 done）
        'finish_reason'      => 'VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'上游收尾原因：stop|length|tool_calls|content_filter 等\'',
        'reasoning_tokens'   => 'INT NOT NULL DEFAULT 0 COMMENT \'推理 token 数（reasoning 模型；不计入可见内容）\'',
        // 客户端**原始**请求的 model 字段（resolveModel() 之前的值）。
        //
        // 为什么必须单独存：`model` 列记的是**路由后**的模型名 —— GatewayService::complete()
        // 里 `[$providerKey,$modelId] = resolveModel($request->model); $request->model = $modelId;`
        // 把入参就地覆盖了，于是「客户端发的是什么」再也查不出来。
        // 排查「用 A 模型却得到 B 模型回答 / 跨供应商误路由」时，这一列是唯一能区分
        // 「客户端本来就发错」与「网关解析错了」的证据（2026-09-30 TraeWork 路由错配排查就因为
        // 缺这一列，只能靠客户端侧配置 + nginx 日志反推）。
        'requested_model'    => 'VARCHAR(160) NOT NULL DEFAULT \'\' COMMENT \'客户端原始请求的 model（未经 resolveModel 解析）\'',
        // 同一账号内的**空响应/拒绝重采样**实际轮次（0 = 一次就成）。
        //
        // 为什么必须单独存：`retry_count` 记的是「换了第几个账号」（`$attempts - 1`），
        // **完全不含**重采样轮次 —— 于是「连续 5 次返回空内容」这种失败在日志里
        // 与「一次就失败」长得一模一样（retry_count 都是 0）。
        // 2026-09-30 排查 RESAMPLE_BUDGET_MS 失效时就是被这个盲区挡住：无法从日志区分
        // 「慢是因为重采样跑满」还是「慢是因为单次上游调用本身长」，导致一度误判成预算护栏失效。
        'resample_count'     => 'INT NOT NULL DEFAULT 0 COMMENT \'同一账号内空响应/拒绝重采样轮次（0=未重采样）\'',
    ];
}
