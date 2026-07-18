-- ============================================================
-- Migration 014: Growth Agent — Fase 2 instrumentation (17 Jul 2026)
--
-- Logging/feedback/style-guide schema behind the new Growth Agent
-- sidebar item (AI Management). These tables already auto-create the
-- moment cms-admin/pages/growth-agent.php or cms-admin/api/seo-generate.php
-- is opened/called (idempotent cms_ensure_table() calls in
-- cms-admin/includes/growth-agent-service.php). This file is the formal,
-- version-controlled record of that same schema — safe to run manually on
-- a fresh database via phpMyAdmin or:
--   mysql -u <user> -p <database> < 014_growth_agent.sql
--
-- No FK constraints, matching this repo's existing convention (plain
-- indexed columns, app-level integrity — see article_tag_map in 003).
--
-- growth_agent_performance is schema-only for now: nothing ingests into
-- it yet, since there's no GA/Search Console integration in this repo.
-- The column shape is settled ahead of that follow-up work.
-- ============================================================

-- --------------------------------------------------------
-- Table: growth_agent_jobs — one row per generation attempt
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `growth_agent_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(50) NOT NULL COMMENT 'e.g. seo_meta, article_draft',
  `agent_key` varchar(50) NOT NULL COMMENT 'matches ai_agent_settings.agent_key',
  `page_id` int(10) unsigned DEFAULT NULL COMMENT 'pages.page_id — null if not saved yet',
  `status` enum('ready','running','succeeded','failed','manual_action') NOT NULL DEFAULT 'running',
  `input_brief` text DEFAULT NULL COMMENT 'JSON snapshot of what was sent to the agent',
  `output_json` text DEFAULT NULL COMMENT 'JSON snapshot of the parsed result',
  `model_used` varchar(100) DEFAULT NULL,
  `tokens_in` int(10) unsigned DEFAULT NULL,
  `tokens_out` int(10) unsigned DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'admins.admin_id, null = system',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gaj_status` (`status`),
  KEY `idx_gaj_page` (`page_id`),
  KEY `idx_gaj_agent_key` (`agent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: growth_agent_feedback — human approve/edit/reject signal
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `growth_agent_feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int(10) unsigned NOT NULL,
  `action` enum('approved_as_is','approved_with_edits','rejected') NOT NULL,
  `notes` text DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL COMMENT 'admins.admin_id',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gaf_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: growth_agent_style_rules — living style guide
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `growth_agent_style_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rule_text` text NOT NULL,
  `source` enum('manual','auto_extracted') NOT NULL DEFAULT 'manual',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gasr_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: growth_agent_performance — traffic/ranking signal (schema only)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `growth_agent_performance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int(10) unsigned NOT NULL,
  `metric_date` date NOT NULL,
  `pageviews` int(10) unsigned NOT NULL DEFAULT 0,
  `avg_ranking_position` decimal(6,2) DEFAULT NULL,
  `clicks` int(10) unsigned NOT NULL DEFAULT 0,
  `ctr` decimal(6,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gap_page_date` (`page_id`,`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify result
SHOW COLUMNS FROM `growth_agent_jobs`;
SHOW COLUMNS FROM `growth_agent_feedback`;
SHOW COLUMNS FROM `growth_agent_style_rules`;
SHOW COLUMNS FROM `growth_agent_performance`;
