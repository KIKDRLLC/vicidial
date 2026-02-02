

ALTER TABLE lead_rules
  ADD COLUMN schedule_tz varchar(64) DEFAULT NULL AFTER next_exec_at;


-- Lead Rules Table

CREATE TABLE IF NOT EXISTS `lead_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,

  /* Scheduling / automation fields */
  `interval_minutes` int(11) DEFAULT NULL,
  `next_exec_at` datetime DEFAULT NULL,
  `apply_batch_size` int(11) DEFAULT NULL,
  `apply_max_to_update` int(11) DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(64) DEFAULT NULL,

  /* Core rule engine payloads */
  `conditions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`conditions_json`)),
  `actions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actions_json`)),

  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_lead_rules_active` (`is_active`),
  KEY `idx_lead_rules_locked` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;




-- Lead Rule Runs Table

CREATE TABLE IF NOT EXISTS `lead_rule_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,

  `run_type` enum('DRY_RUN','APPLY') NOT NULL,
  `matched_count` int(11) DEFAULT NULL,
  `updated_count` int(11) DEFAULT NULL,

  `status` enum('STARTED','SUCCESS','FAILED') NOT NULL DEFAULT 'STARTED',
  `error_text` text DEFAULT NULL,

  `sample_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sample_json`)),

  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_runs_rule_id` (`rule_id`),
  KEY `idx_runs_created_at` (`created_at`),
  KEY `idx_runs_rule_type` (`rule_id`,`run_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;


