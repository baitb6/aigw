-- ============================================================
-- AI 聚合网关 · 数据库结构
-- 对应原 App 的 settings/*.json、account/*、pool/state/*、logs/*
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 管理台用户
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_admin`;
CREATE TABLE `aigw_admin` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(64)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL COMMENT 'password_hash 结果',
  `nickname`     VARCHAR(64)  NOT NULL DEFAULT '',
  `last_login_at` DATETIME    NULL,
  `last_login_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `session_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话纪元：改密码时 +1，使旧会话立即失效',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理台账号';

-- ------------------------------------------------------------
-- 普通用户账号（自助注册，独立 API Key，账号/日志按属主隔离）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_user`;
CREATE TABLE `aigw_user` (
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
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='普通用户账号';

-- ------------------------------------------------------------
-- 登录失败锁定（IP + 账号维度，持久化，清 cookie 不可绕过）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_login_lockout`;
CREATE TABLE `aigw_login_lockout` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录失败锁定（IP+账号维度，持久化防清 cookie 绕过）';

-- ------------------------------------------------------------
-- 网关设置（单行配置，对应 settings/gateway.json）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_gateway_settings`;
CREATE TABLE `aigw_gateway_settings` (
  `id`                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `api_key`             VARCHAR(128) NOT NULL DEFAULT '' COMMENT '对外调用密钥',
  `allow_no_key`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '允许无 Key 调用',
  `allow_register`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '开放普通用户自助注册',
  `expose_lan`          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否对外暴露（0.0.0.0）',
  `only_usable_models`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '仅展示可用模型',
  `default_provider`    VARCHAR(32)  NOT NULL DEFAULT 'custom' COMMENT '默认供应商',
  `default_model`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT '默认模型',
  `quota_cooldown_ms`   BIGINT       NOT NULL DEFAULT 600000,
  `soft_cooldown_ms`    BIGINT       NOT NULL DEFAULT 60000,
  `max_error_count`     INT          NOT NULL DEFAULT 3,
  `log_keep_days`       INT          NOT NULL DEFAULT 30 COMMENT '日志保留天数',
  `stream_heartbeat`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'SSE 首帧心跳',
  `schedule_mode`       VARCHAR(16)  NOT NULL DEFAULT 'balance' COMMENT '账号调度模式 balance|rotation|pinned',
  `pinned_account_uid`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'pinned 模式下固定使用的账号 uid',
  `auto_checkin`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '定时自动签到',
  `auto_refresh`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '定时自动刷新额度',
  `scheduler_last_run`  BIGINT       NOT NULL DEFAULT 0 COMMENT '定时任务上次执行时间(ms)',
  `scheduler_last_log`  TEXT         NULL COMMENT '定时任务上次执行结果摘要',
  `checkin_hours`       VARCHAR(32)  NOT NULL DEFAULT '9,21' COMMENT '每日自动签到时间点(小时，逗号分隔)',
  `refresh_hour`        INT          NOT NULL DEFAULT 22 COMMENT '每日 Token 保活刷新时间点(小时)',
  `checkin_log_keep_days` INT        NOT NULL DEFAULT 30 COMMENT '签到日志保留天数',
  `credit_monitor_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '积分到期巡检开关',
  `credit_patrol_minutes` INT        NOT NULL DEFAULT 15 COMMENT '积分到期巡检间隔(分钟)',
  `credit_expiring_days` INT         NOT NULL DEFAULT 7 COMMENT '积分到期预警窗口(天)',
  `session_affinity_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '会话粘性开关',
  `session_affinity_ttl` INT         NOT NULL DEFAULT 86400 COMMENT '会话粘性 TTL(秒，滚动续期)',
  `travel_enabled`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '猫猫旅行自动派出开关',
  `travel_location`     VARCHAR(64)  NOT NULL DEFAULT '古镇客栈' COMMENT '猫猫旅行固定派出地点',
  `travel_rate_limit_ms` INT         NOT NULL DEFAULT 800 COMMENT '账号间旅行操作限速(ms)',
  `activity_enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '每日活跃注入开关(WorkBuddy 国际版)',
  `activity_rate_limit_ms` INT       NOT NULL DEFAULT 1500 COMMENT '账号间活跃注入限速(ms)',
  `tasks_enabled`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '成长中心自动领奖开关(WorkBuddy 国服)',
  `tasks_interval_minutes` INT       NOT NULL DEFAULT 60 COMMENT '成长任务轮询间隔(分钟)',
  `streak_enabled`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '连登管家自动办理开关(WorkBuddy 国服)',
  `streak_interval_minutes` INT      NOT NULL DEFAULT 60 COMMENT '连登管家轮询间隔(分钟)',
  `lease_ttl_ms`        BIGINT       NOT NULL DEFAULT 120000 COMMENT '账号在途租约时长(ms)',
  `backoff_base_ms`     BIGINT       NOT NULL DEFAULT 2000 COMMENT '指数退避基数(ms)',
  `lease_bypass_providers` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '免排队供应商白名单(逗号分隔 provider_key)；空=全部排队',
  `scheduler_last_checkin_at` BIGINT NOT NULL DEFAULT 0 COMMENT '上次定时签到时间(ms)',
  `scheduler_last_refresh_at` BIGINT NOT NULL DEFAULT 0 COMMENT '上次 Token 保活刷新时间(ms)',
  `scheduler_last_patrol_at`  BIGINT NOT NULL DEFAULT 0 COMMENT '上次积分巡检时间(ms)',
  `scheduler_last_prune_at`   BIGINT NOT NULL DEFAULT 0 COMMENT '上次日志清理时间(ms)',
  `scheduler_last_travel_at`  BIGINT NOT NULL DEFAULT 0 COMMENT '上次猫猫旅行派出时间(ms)',
  `scheduler_last_activity_at` BIGINT NOT NULL DEFAULT 0 COMMENT '上次每日活跃注入时间(ms)',
  `scheduler_last_tasks_at`   BIGINT NOT NULL DEFAULT 0 COMMENT '上次成长任务执行时间(ms)',
  `scheduler_last_streak_at`  BIGINT NOT NULL DEFAULT 0 COMMENT '上次连登管家执行时间(ms)',
  `gateway_enabled`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '网关总开关，关闭后对外接口整体不可用',
  `chat_enabled`            TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '对话补全开关',
  `models_enabled`          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '模型列表开关',
  `stream_enabled`          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '流式输出开关',
  `tools_enabled`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '工具/函数调用透传开关',
  `models_expose_providers` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '模型列表是否暴露供应商归属',
  `models_qualified_id`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '模型列表 id 是否下发「供应商key/模型ID」，关闭则回落裸模型名',
  `log_enabled`             TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '调用日志记录开关',
  `log_request_body`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否记录请求体摘要',
  `log_content_max_chars`   INT          NOT NULL DEFAULT 100000 COMMENT '内容留痕单条上限（字符），0=不记录，上限 200000',
  `log_body_max_rows`       INT          NOT NULL DEFAULT 20000 COMMENT '正文只保留最近 N 行（0=不限），超出清空正文但保留统计',
  `log_max_rows`            INT          NOT NULL DEFAULT 200000 COMMENT '日志最大保留行数，0=不限制',
  `resample_rewrite_user`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '重采样时改写 user 提示词以规避上游出口层特征匹配',
  `rate_limit_enabled`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '对外限流开关',
  `rate_limit_rpm`          INT          NOT NULL DEFAULT 60 COMMENT '每分钟请求上限',
  `rate_limit_burst`        INT          NOT NULL DEFAULT 10 COMMENT '突发允许的额外请求数',
  `rate_limit_scope`        VARCHAR(16)  NOT NULL DEFAULT 'key' COMMENT '限流维度 key|ip',
  `user_persona_enabled`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否允许普通用户自定义思维（关闭后只能使用系统自带思维）',
  `system_persona_enabled`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '系统内置（全局）思维总开关，默认不开启',
  `notice_enabled`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '弹窗公告总开关（注册页/总览面板）',
  `notice_title`            VARCHAR(128) NOT NULL DEFAULT '' COMMENT '弹窗公告标题',
  `notice_content`          TEXT         NULL COMMENT '弹窗公告正文（支持换行）',
  `notice_target`           VARCHAR(16)  NOT NULL DEFAULT 'all' COMMENT '显示位置 all|register|dashboard',
  `notice_type`             VARCHAR(16)  NOT NULL DEFAULT 'info' COMMENT '公告样式 info|success|warning|danger',
  `notice_version`          INT          NOT NULL DEFAULT 0 COMMENT '公告版本号，变更后自动+1（前端据此判断是否已读）',
  `notice_group_enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '公告弹窗是否显示「加入交流群」按钮',
  `notice_group_qq`         VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'QQ 群号（后端只留数字）',
  `notice_group_url`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '加群链接（可空；填了优先跳转，需 http(s)://）',
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网关全局设置';

-- ------------------------------------------------------------
-- 提示词模板（全局模板 + 用户自建模板）
--   user_id = 0  → 管理员维护的全局模板（对所有调用生效）
--   user_id > 0  → 某普通用户自建模板（仅对该用户自己的调用生效）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_prompt_template`;
CREATE TABLE `aigw_prompt_template` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提示词模板';

-- ------------------------------------------------------------
-- 网关对外限流令牌桶（跨 php-fpm 进程共享）
--   每行一个「桶」：rate_limit_scope=key 时按 API Key，=ip 时按客户端 IP
--   tokens 为千分位整数（1000 = 1 个令牌），避免浮点误差
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_rate_limit`;
CREATE TABLE `aigw_rate_limit` (
  `bucket`     VARCHAR(160)    NOT NULL COMMENT '限流桶标识：api_key 哈希 或 客户端 IP',
  `tokens`     BIGINT          NOT NULL DEFAULT 0 COMMENT '剩余令牌（千分位整数，1000=1个令牌）',
  `updated_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次令牌刷新时间(ms)',
  PRIMARY KEY (`bucket`),
  KEY `idx_updated` (`updated_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网关对外限流令牌桶';

-- ------------------------------------------------------------
-- 网络代理（对应 LV1/z）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_proxy_settings`;
CREATE TABLE `aigw_proxy_settings` (
  `id`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `enabled`        TINYINT(1)   NOT NULL DEFAULT 0,
  `host`           VARCHAR(255) NOT NULL DEFAULT '',
  `port`           INT          NOT NULL DEFAULT 0,
  `username`       VARCHAR(128) NOT NULL DEFAULT '',
  `password_enc`   VARBINARY(512) NOT NULL DEFAULT '' COMMENT 'AES-GCM 加密',
  `excluded_provider_ids` JSON    NULL COMMENT '排除的供应商 ID 列表',
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网络代理设置';

-- ------------------------------------------------------------
-- 供应商（对应 settings/provider/<id>.json）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_provider`;
CREATE TABLE `aigw_provider` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`  VARCHAR(32)  NOT NULL COMMENT 'trae/loomy/codebuddy/antigravity/custom',
  `display_name`  VARCHAR(64)  NOT NULL,
  `kind`          VARCHAR(16)  NOT NULL DEFAULT 'builtin' COMMENT 'builtin|synthetic|custom',
  `enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
  `login_type`    VARCHAR(32)  NOT NULL DEFAULT 'NONE' COMMENT 'WEBVIEW_CALLBACK|DEVICE_CODE|SMS_CODE|OAUTH_LOOPBACK|API_KEY|NONE',
  `default_model` VARCHAR(128) NOT NULL DEFAULT '',
  `rate`          DECIMAL(8,4) NOT NULL DEFAULT 1.0000 COMMENT '每平台默认倍率（参与用量/费用换算）',
  `hosts`         JSON         NULL COMMENT '主机白名单',
  `options`       JSON         NULL COMMENT '扩展参数',
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `user_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID，0=管理员/内置（用户自建实例不为0）',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_key` (`provider_key`),
  KEY `idx_provider_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商注册表';

-- ------------------------------------------------------------
-- 账号池（对应 account/<providerId>/<uid>.json）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_account`;
CREATE TABLE `aigw_account` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`     VARCHAR(32)  NOT NULL,
  `account_uid`      VARCHAR(128) NOT NULL COMMENT '上游账号唯一标识',
  `nickname`         VARCHAR(128) NOT NULL DEFAULT '',
  `credential_enc`   TEXT         NULL COMMENT 'AES-GCM 加密的凭据 JSON',
  `meta`             JSON         NULL COMMENT '非敏感附加信息(email/machineId/domain...)',
  `credits`          DECIMAL(16,2) NULL COMMENT '剩余额度',
  `credits_known`    TINYINT(1)   NOT NULL DEFAULT 0,
  `credits_detail`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '额度描述',
  `enabled_by_user`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '用户启停',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'READY' COMMENT 'READY|COOLING|DISABLED|ERROR',
  `reason`           VARCHAR(255) NOT NULL DEFAULT '',
  `cooling_until`    BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `error_count`      INT          NOT NULL DEFAULT 0,
  `last_error`       VARCHAR(512) NOT NULL DEFAULT '',
  `last_refresh_at`  BIGINT       NOT NULL DEFAULT 0,
  `used_count`       BIGINT       NOT NULL DEFAULT 0 COMMENT '累计调用次数',
  `credits_expire_at` BIGINT      NOT NULL DEFAULT 0 COMMENT '积分到期时间(ms)，0=未知/不过期',
  `next_retry_at`    BIGINT       NOT NULL DEFAULT 0 COMMENT '指数退避下次可试时间(ms)',
  `lease_until`      BIGINT       NOT NULL DEFAULT 0 COMMENT '在途租约到期(ms)',
  `checkin_at`       BIGINT       NOT NULL DEFAULT 0 COMMENT '最近签到时间(ms)',
  `last_error_at`    BIGINT       NOT NULL DEFAULT 0 COMMENT '最近一次计错时间(ms)，用于错误计数时间窗衰减',
  `user_id`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID，0=管理员/全局',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account` (`provider_key`, `account_uid`),
  KEY `idx_provider_status` (`provider_key`, `status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_cooling` (`cooling_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账号池';

-- ------------------------------------------------------------
-- 模型目录（对应 LY1/y 的模型开关）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_model`;
CREATE TABLE `aigw_model` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_key`  VARCHAR(32)  NOT NULL,
  `model_id`      VARCHAR(160) NOT NULL COMMENT '上游模型名',
  `display_name`  VARCHAR(160) NOT NULL DEFAULT '',
  `enabled`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '供应商级模型开关',
  `is_custom`     TINYINT(1)   NOT NULL DEFAULT 0,
  `capabilities`  JSON         NULL COMMENT 'stream/tools/vision',
  `rate`          DECIMAL(8,4) NULL COMMENT '上游模型倍率（同步采集），NULL=未采集，参与费用换算',
  `rate_source`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '倍率来源字段名（原始采集路径）',
  `fee_level`     INT          NULL COMMENT '上游 fee_model_level',
  `entitlement_cost` DECIMAL(8,4) NULL COMMENT '上游 model_entitlement_usage_cost',
  `context_window` INT         NULL COMMENT '上游 context_window_tokens',
  `max_tokens`    INT          NULL COMMENT '上游 model_detail.max_tokens',
  `multimodal`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '上游 multimodal',
  `reasoning`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '上游 reasoning_model',
  `synced_at`     DATETIME     NULL COMMENT '最近一次上游同步时间',
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `user_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属用户ID，0=管理员/全局（用户私有模型不为0）',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_model` (`provider_key`,`model_id`,`user_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_user` (`user_id`),
  KEY `idx_model_rate` (`provider_key`,`model_id`,`rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模型目录';

-- ------------------------------------------------------------
-- 调用日志（对应 logs/calls 与 logs/requests.json）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_call_log`;
CREATE TABLE `aigw_call_log` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`        VARCHAR(64)  NOT NULL DEFAULT '',
  `provider_key`      VARCHAR(32)  NOT NULL DEFAULT '',
  `model`             VARCHAR(160) NOT NULL DEFAULT '',
  `account_uid`       VARCHAR(128) NOT NULL DEFAULT '',
  `account_nickname`  VARCHAR(128) NOT NULL DEFAULT '',
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS|FAILED|ABORTED',
  `error_code`        VARCHAR(48)  NOT NULL DEFAULT '',
  `error`             VARCHAR(512) NOT NULL DEFAULT '',
  `streaming`         TINYINT(1)   NOT NULL DEFAULT 0,
  `retry_count`       INT          NOT NULL DEFAULT 0,
  `duration_ms`       INT          NOT NULL DEFAULT 0,
  `first_token_ms`    INT          NOT NULL DEFAULT 0,
  `prompt_tokens`     INT          NOT NULL DEFAULT 0,
  `completion_tokens` INT          NOT NULL DEFAULT 0,
  `total_tokens`      INT          NOT NULL DEFAULT 0,
  `cache_read_tokens` INT          NOT NULL DEFAULT 0 COMMENT '缓存命中读取 Token',
  `cache_write_tokens` INT         NOT NULL DEFAULT 0 COMMENT '缓存写入 Token',
  `source`            VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '来源：workbuddy|workbuddy_intl|codebuddy_cli|codebuddy_cn_ide',
  `session_key`       VARCHAR(80)  NOT NULL DEFAULT '',
  `user_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属调用用户ID，0=管理员/全局',
  `persona_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本次注入的思维ID，0=未注入',
  `persona_hit`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否命中思维自证口令（未打上游直回）',
  `request_body`      MEDIUMTEXT   NULL COMMENT '请求内容快照（超长时 gzip+base64，见 log_body_encoding）',
  `response_body`     MEDIUMTEXT   NULL COMMENT '回复内容快照（超长时 gzip+base64，见 log_body_encoding）',
  `log_body_encoding` VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '正文编码：空=明文 / gz:b64=gzip+base64',
  `req_body_length`   INT          NOT NULL DEFAULT 0 COMMENT '请求正文明文字符数（0=未记录）',
  `res_body_length`   INT          NOT NULL DEFAULT 0 COMMENT '回复正文明文字符数（0=未记录）',
  `finish_reason`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '上游收尾原因：stop|length|tool_calls|content_filter 等',
  `reasoning_tokens`  INT          NOT NULL DEFAULT 0 COMMENT '推理 token 数（reasoning 模型；不计入可见内容）',
  `requested_model`   VARCHAR(160) NOT NULL DEFAULT '' COMMENT '客户端原始请求的 model（未经 resolveModel 解析）',
  `resample_count`    INT          NOT NULL DEFAULT 0 COMMENT '同一账号内空响应/拒绝重采样轮次（0=未重采样）',
  `client_ip`         VARCHAR(64)  NOT NULL DEFAULT '',
  `started_at`        BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_provider` (`provider_key`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_request` (`request_id`),
  -- 性能优化（2026-11）：复合索引覆盖高频过滤/聚合路径
  KEY `idx_user_id` (`user_id`, `id`),                 -- 用户维度分页 ORDER BY id DESC
  KEY `idx_provider_created` (`provider_key`, `created_at`), -- 供应商+时间窗聚合
  KEY `idx_provider_account` (`provider_key`, `account_uid`) -- 账号用量 GROUP BY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='调用日志';

-- ------------------------------------------------------------
-- 签到日志（保留 30 天，自动清理）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_checkin_log`;
CREATE TABLE `aigw_checkin_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='签到日志';

-- ------------------------------------------------------------
-- 账号 + 模型级限流冷却隔离（429 code=6004 等）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_account_model_cooldown`;
CREATE TABLE `aigw_account_model_cooldown` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账号+模型级冷却';

-- ------------------------------------------------------------
-- 会话粘性绑定（同会话绑定同一账号，TTL 滚动续期）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_session_affinity`;
CREATE TABLE `aigw_session_affinity` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key`  VARCHAR(80)  NOT NULL,
  `provider_key` VARCHAR(32)  NOT NULL DEFAULT '',
  `model`        VARCHAR(160) NOT NULL DEFAULT '',
  `account_uid`  VARCHAR(128) NOT NULL DEFAULT '',
  `expires_at`   BIGINT       NOT NULL DEFAULT 0 COMMENT '毫秒时间戳',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session` (`session_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话粘性绑定';

-- ------------------------------------------------------------
-- 猫猫旅行每日幂等日志
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_travel_log`;
CREATE TABLE `aigw_travel_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='猫猫旅行每日幂等日志';

-- ------------------------------------------------------------
-- 每日活跃注入幂等日志（每账号每自然日最多一次）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_activity_log`;
CREATE TABLE `aigw_activity_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日活跃注入幂等日志';

-- ------------------------------------------------------------
-- 思维注入（Persona Injection）
-- 与 pojia-next 的「思维 / 护照 / 靶点状态」三层一一对应，
-- 但注入点在网关（改写 system 提示词），不碰客户端任何文件。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_persona`;
CREATE TABLE `aigw_persona` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维库（网关注入用）';

DROP TABLE IF EXISTS `aigw_persona_revision`;
CREATE TABLE `aigw_persona_revision` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维修订快照（备份与还原）';

DROP TABLE IF EXISTS `aigw_persona_target`;
CREATE TABLE `aigw_persona_target` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='思维注入靶点状态（按供应商）';

-- 用户级平台开关：普通用户自选「在哪些平台上接受思维注入」
DROP TABLE IF EXISTS `aigw_persona_user_target`;
CREATE TABLE `aigw_persona_user_target` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '普通用户ID',
  `target`     VARCHAR(64)  NOT NULL COMMENT '平台：供应商 key',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '该用户在该平台上是否接受注入（默认关）',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_target` (`user_id`, `target`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级平台开关（普通用户自选启用注入的平台，默认全关）';

-- 用户级思维开关（普通用户自选哪几份思维对自己生效；无记录 = 关闭）
DROP TABLE IF EXISTS `aigw_persona_user_enable`;
CREATE TABLE `aigw_persona_user_enable` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '普通用户ID',
  `persona_id` BIGINT UNSIGNED NOT NULL COMMENT '被开关的思维ID（通常指向全局思维）',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '该用户是否让这份思维对自己生效（默认关）',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_persona` (`user_id`, `persona_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级思维开关（普通用户自选启用的思维，默认全关）';

-- 用户行为审计流水：谁在何时对什么做了什么（登录/改密/增删改/管控动作）
DROP TABLE IF EXISTS `aigw_audit_log`;
CREATE TABLE `aigw_audit_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户行为审计流水（谁在何时对什么做了什么）';

-- ------------------------------------------------------------
-- 异步动作任务（绕开 CDN 回源超时 524）
-- 耗时长、会被 CDN 回源超时掐断的动作（典型：ZCode 领额度，要跑
-- 「拉配置 + 无痕验证求解 9~75s + claim」）改为「提交即返回 task_id →
-- 后台 worker 执行 → 前端轮询结果」，提交请求不再占用 CDN 回源连接。
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_action_task`;
CREATE TABLE `aigw_action_task` (
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
  KEY `idx_status_created` (`status`, `id`),
  -- 性能优化（2026-11）：按账号+动作查最近任务（ActionTaskRepository）
  KEY `idx_account_action` (`account_id`, `action`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异步动作任务（绕开 CDN 回源超时）';

-- ------------------------------------------------------------
-- Operit 生态扩展表（MCP / 向量记忆 / 工作流 / 语音）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `aigw_mcp_server`;
CREATE TABLE `aigw_mcp_server` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MCP 服务器配置（Operit 生态）';

DROP TABLE IF EXISTS `aigw_memory`;
CREATE TABLE `aigw_memory` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='向量记忆（Operit 生态）';

DROP TABLE IF EXISTS `aigw_workflow`;
CREATE TABLE `aigw_workflow` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流定义（Operit 生态）';

DROP TABLE IF EXISTS `aigw_workflow_task`;
CREATE TABLE `aigw_workflow_task` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流执行记录（Operit 生态）';

DROP TABLE IF EXISTS `aigw_speech_log`;
CREATE TABLE `aigw_speech_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语音调用日志（Operit 生态）';

DROP TABLE IF EXISTS `aigw_response`;
CREATE TABLE `aigw_response` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OpenAI Responses API 响应对象与后台任务';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 初始化数据
-- ============================================================

INSERT INTO `aigw_gateway_settings` (`id`) VALUES (1);
INSERT INTO `aigw_proxy_settings` (`id`) VALUES (1);

INSERT INTO `aigw_provider`
  (`provider_key`, `display_name`, `kind`, `enabled`, `login_type`, `default_model`, `hosts`, `sort_order`)
VALUES
  ('trae', 'Trae',
   'builtin', 1, 'WEBVIEW_CALLBACK', 'doubao-seed-2.0-pro',
   JSON_ARRAY('trae-api-cn.mchost.guru', 'api.trae.cn', 'api.trae.com.cn', 'www.trae.cn'), 10),

  ('trae_intl', 'Trae 国际版',
   'builtin', 1, 'WEBVIEW_CALLBACK', 'doubao-seed-2.0-pro',
   JSON_ARRAY('a0ai-api-sg.byteintlapi.com', 'api-sg-central.trae.ai', 'www.trae.ai'), 11),

  ('loomy', 'Loomy',
   'builtin', 1, 'SMS_CODE', 'DeepSeek-v3.2',
   JSON_ARRAY('xfinfr.com', 'xunfei.cn', 'account.xfinfr.com'), 20),

  ('codebuddy', 'WorkBuddy',
   'builtin', 1, 'DEVICE_CODE', '',
   JSON_ARRAY('codebuddy.cn', 'workbuddy.ai', 'copilot.tencent.com'), 30),

  ('codebuddy_intl', 'WorkBuddy 国际版',
   'builtin', 1, 'DEVICE_CODE', '',
   JSON_ARRAY('workbuddy.ai'), 31),

  ('antigravity', 'Antigravity',
   'builtin', 1, 'OAUTH_LOOPBACK', '',
   JSON_ARRAY('de-pa.googleapis.com', 'oauth2.googleapis.com'), 40),

  ('doubao', '豆包',
   'builtin', 1, 'WEBVIEW_CALLBACK', 'doubao',
   JSON_ARRAY('doubao.com', 'api.doubao.com'), 45),

  ('xiaohuanxiong', '商汤小浣熊',
   'builtin', 1, 'WEBVIEW_CALLBACK', 'sn-glm-5-3-flash',
   JSON_ARRAY('xiaohuanxiong.com'), 70),

  ('custom', '自定义供应商',
   'custom', 1, 'API_KEY', '',
   JSON_ARRAY(), 50),

  -- Agent2API 网关桥接（见 app/Provider/Providers/Agent2ApiProvider.php）。
  -- base_url / 网关 Key / 面板账号在「供应商」页填写，或跑 bin/agent2api-sync.php 自动生成。
  ('agent2api', 'Agent2API 网关',
   'custom', 1, 'API_KEY', '',
   JSON_ARRAY(), 60);

-- Loomy 内置静态模型
INSERT INTO `aigw_model` (`provider_key`, `model_id`, `display_name`, `enabled`, `is_custom`, `capabilities`, `sort_order`) VALUES
  ('loomy', 'MiniMax-M2.5',       'MiniMax M2.5',       1, 0, JSON_ARRAY('stream'), 1),
  ('loomy', 'doubao-seed-2.0-pro','豆包 Seed 2.0 Pro', 1, 0, JSON_ARRAY('stream'), 2),
  ('loomy', 'DeepSeek-v3.2',      'DeepSeek v3.2',      1, 0, JSON_ARRAY('stream'), 3);

-- 豆包内置静态模型（模型标识还原自 Apk Lc/m）
INSERT INTO `aigw_model` (`provider_key`, `model_id`, `display_name`, `enabled`, `is_custom`, `capabilities`, `sort_order`) VALUES
  ('doubao', 'doubao',        '豆包',           1, 0, JSON_ARRAY('stream', 'vision'),    1),
  ('doubao', 'doubao-pro',    '豆包 Pro',       1, 0, JSON_ARRAY('stream', 'vision'),    2),
  ('doubao', 'doubao-think',  '豆包 深度思考',  1, 0, JSON_ARRAY('stream', 'reasoning'), 3),
  ('doubao', 'doubao-expert', '豆包 专家',      1, 0, JSON_ARRAY('stream', 'reasoning'), 4),
  ('doubao', 'doubao-image',  '豆包 图像生成',  1, 0, JSON_ARRAY('stream'),             5);

-- 默认管理员由 database/install.php 创建（口令随机或由 AIGW_INSTALL_ADMIN_PASSWORD 指定），
-- 这里不预置任何账号口令，避免把固定默认凭据带进仓库。