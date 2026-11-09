-- ------------------------------------------------------------
-- 2026-11-09 性能优化：热点查询复合索引
--
-- 适用：老库升级（新装的 schema.sql 已自带这些索引，重复执行会报
-- "Duplicate key name"，可忽略或逐条执行）。
--
-- 背景：
--   aigw_call_log 是站内最重的表（每次调用一行），原只有单列索引，
--   用户分页 / 供应商聚合 / 账号用量统计都走不到复合索引，数据量大后
--   管理台日志页与仪表盘明显变慢。
-- ------------------------------------------------------------

-- 用户维度分页（WHERE user_id=? ORDER BY id DESC）
ALTER TABLE `aigw_call_log` ADD KEY `idx_user_id` (`user_id`, `id`);

-- 供应商 + 时间窗过滤/聚合（仪表盘 by_provider、趋势图）
ALTER TABLE `aigw_call_log` ADD KEY `idx_provider_created` (`provider_key`, `created_at`);

-- 账号用量统计（GROUP BY provider_key, account_uid）
ALTER TABLE `aigw_call_log` ADD KEY `idx_provider_account` (`provider_key`, `account_uid`);

-- 动作任务：按账号+动作查最近一条（ActionTaskRepository）
ALTER TABLE `aigw_action_task` ADD KEY `idx_account_action` (`account_id`, `action`, `id`);

-- 管理台账号：会话纪元列（改密踢下线，本次安全修复新增；新装库 schema.sql 已含）
ALTER TABLE `aigw_admin` ADD COLUMN `session_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话纪元：改密码时 +1，使旧会话立即失效';
