-- ============================================================
-- Migration 017: Growth Agent "Agent Memory" (winning patterns / content
-- gaps detected from historical GSC data, admin-reviewed before use)
--
-- One new table + two columns bolted onto the pre-existing gsc_settings
-- table (015_gsc_search_console.sql, not edited). See
-- docs/GROWTH_AGENT_MEMORY_PLAN.md for the full design.
--
-- This table already auto-creates the moment cms-admin/pages/growth-agent.php
-- is opened (idempotent cms_ensure_table()/cms_ensure_column() calls in
-- cms-admin/includes/gsc-api.php). This file is the formal,
-- version-controlled record of that same schema — safe to run manually via
-- phpMyAdmin or:
--   mysql -u <user> -p <database> < 017_growth_agent_memory.sql
--
-- No FK constraints, matching this repo's existing convention.
--
-- IMPORTANT — learned the hard way from 015/016: "ADD COLUMN IF NOT
-- EXISTS" requires MySQL 8.0.29+/MariaDB 10.3+ and threw #1064 on this
-- project's actual production server. Every ADD COLUMN below is guarded
-- by a throwaway stored procedure instead (check INFORMATION_SCHEMA.COLUMNS
-- first), which works on effectively any MySQL/MariaDB version. See
-- migrations/README.md "Update (18 Jul 2026)" note.
-- ============================================================

-- --------------------------------------------------------
-- Table: growth_agent_memory — winning_pattern / content_gap insights
-- detected from gsc_query_data aggregated across its full retention
-- window (not a single fetch snapshot — see cms_growth_agent_detect_
-- memory_if_stale() in growth-agent-service.php). Every new row starts
-- 'pending_review'; only 'active' rows are folded into
-- GrowthAgentPromptBuilder context, and only for job_type=gsc_article_idea.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `growth_agent_memory` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `insight_type` enum('winning_pattern','content_gap') NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL COMMENT 'parametrized narrative with real numbers, not AI-generated',
  `supporting_data_json` text DEFAULT NULL COMMENT 'query, distinct_weeks, avg_ctr/position, total_impressions, matched_page_id',
  `status` enum('pending_review','active','archived') NOT NULL DEFAULT 'pending_review',
  `archived_reason` enum('rejected','stale_pending','stale_active') DEFAULT NULL COMMENT 'distinguishes explicit admin reject (permanent) from auto-archive-by-age (re-detectable)',
  `reviewed_by` int(10) unsigned DEFAULT NULL COMMENT 'admins.admin_id',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `detected_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_confirmed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'bumped every re-detection that still finds this pattern — retention basis, not detected_at',
  `dedupe_key` char(32) NOT NULL COMMENT 'MD5(insight_type|query)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gam_dedupe` (`dedupe_key`),
  KEY `idx_gam_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- gsc_settings: pre-existing table (015_gsc_search_console.sql /
-- 016_gsc_opportunities.sql, not edited). Adds two columns: a dedicated
-- JSON blob for every Memory detection/retention threshold (kept
-- separate from opportunity_thresholds_json — same "one tunable place"
-- spirit, but one place PER FEATURE, not one giant mixed blob — see
-- cms_gsc_default_memory_thresholds() in gsc-api.php for shape/defaults),
-- and a timestamp tracking the last detection run for the lazy
-- "if-stale" trigger (same pattern as gsc_settings.last_fetch_at).
-- --------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS `_m017_add_gsc_settings_memory_cols`$$
CREATE PROCEDURE `_m017_add_gsc_settings_memory_cols`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'gsc_settings'
           AND COLUMN_NAME = 'memory_thresholds_json'
    ) THEN
        ALTER TABLE `gsc_settings`
            ADD COLUMN `memory_thresholds_json` LONGTEXT DEFAULT NULL AFTER `opportunity_thresholds_json`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'gsc_settings'
           AND COLUMN_NAME = 'last_memory_detection_at'
    ) THEN
        ALTER TABLE `gsc_settings`
            ADD COLUMN `last_memory_detection_at` TIMESTAMP NULL DEFAULT NULL AFTER `memory_thresholds_json`;
    END IF;
END$$
DELIMITER ;

CALL `_m017_add_gsc_settings_memory_cols`();
DROP PROCEDURE IF EXISTS `_m017_add_gsc_settings_memory_cols`;

-- Verify result
SHOW COLUMNS FROM `growth_agent_memory`;
SHOW COLUMNS FROM `gsc_settings`;
