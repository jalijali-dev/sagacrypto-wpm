-- ============================================================
-- Migration 015: Google Search Console integration (Growth Agent)
--
-- Two new tables + one ALTER on the pre-existing growth_agent_jobs table
-- (014_growth_agent.sql — that file is NOT edited, this is a new migration
-- adding a column to it, same pattern as 003 bolting columns onto `pages`).
--
-- These tables already auto-create the moment cms-admin/pages/gsc-settings.php
-- or cms-admin/pages/growth-agent.php is opened (idempotent
-- cms_ensure_table()/cms_ensure_column() calls in cms-admin/includes/gsc-api.php).
-- This file is the formal, version-controlled record of that same schema —
-- safe to run manually via phpMyAdmin or:
--   mysql -u <user> -p <database> < 015_gsc_search_console.sql
--
-- No FK constraints, matching this repo's existing convention (app-level
-- integrity, not DB-enforced — see article_tag_map in 003, growth_agent_*
-- in 014).
-- ============================================================

-- --------------------------------------------------------
-- Table: gsc_settings — singleton row, service account + connected
-- property + fetch status. service_account_json_enc is AES-256-CBC
-- encrypted via cms_ai_encrypt() (reused from ai-helpers.php, same
-- encryption already used for ai_credentials.api_key_enc) — never
-- decrypted back to the UI after saving.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gsc_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_account_email` varchar(255) DEFAULT NULL COMMENT 'parsed from JSON client_email, safe to display (not sensitive)',
  `service_account_json_enc` longtext DEFAULT NULL COMMENT 'full service account JSON, encrypted via cms_ai_encrypt()',
  `site_url` varchar(255) DEFAULT NULL COMMENT 'connected GSC property, e.g. sc-domain:sagacrypto.com',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `fetch_lookback_days` int(10) unsigned NOT NULL DEFAULT 14 COMMENT 'no cron in this codebase — each fetch re-pulls N days so infrequent lazy-fetch triggers still backfill',
  `fetch_window_days` int(10) unsigned NOT NULL DEFAULT 90 COMMENT 'retention — gsc_query_data rows older than this are pruned each fetch',
  `last_fetch_status` varchar(20) DEFAULT NULL,
  `last_fetch_message` varchar(255) DEFAULT NULL,
  `last_fetch_rows` int(10) unsigned DEFAULT NULL,
  `last_fetch_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: gsc_query_data — raw query/page/date performance cache pulled
-- from the Search Console API. dedupe_hash (MD5 of query|page_url|date)
-- carries the UNIQUE KEY instead of the three source columns directly —
-- a composite unique key on two long VARCHAR columns + DATE would exceed
-- InnoDB's index byte limit under utf8mb4, so this avoids that entirely
-- while still making re-fetches a clean INSERT ... ON DUPLICATE KEY UPDATE.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gsc_query_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `query` varchar(255) NOT NULL,
  `page_url` varchar(500) NOT NULL,
  `matched_page_id` int(10) unsigned DEFAULT NULL COMMENT 'pages.page_id resolved by URL match at fetch time — NULL means no matching article (signal for "new article idea" opportunity)',
  `clicks` int(10) unsigned NOT NULL DEFAULT 0,
  `impressions` int(10) unsigned NOT NULL DEFAULT 0,
  `ctr` decimal(7,4) DEFAULT NULL,
  `position` decimal(6,2) DEFAULT NULL,
  `data_date` date NOT NULL COMMENT 'the GSC date dimension this row represents, not the fetch date',
  `dedupe_hash` char(32) NOT NULL COMMENT 'MD5(query|page_url|data_date), computed in PHP before insert',
  `fetched_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gsc_dedupe_hash` (`dedupe_hash`),
  KEY `idx_gsc_page` (`matched_page_id`),
  KEY `idx_gsc_date` (`data_date`),
  KEY `idx_gsc_query` (`query`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- growth_agent_jobs: pre-existing table (014_growth_agent.sql, not
-- edited). Bolts on a priority label so GSC-driven recommendations can
-- be flagged HIGH for the operator's attention in the Recent jobs table.
-- Non-GSC jobs (seo_meta/article_draft/faq generated manually) simply
-- keep the column default ('normal') — this label is GSC-analysis-only.
--
-- NOTE: "ADD COLUMN IF NOT EXISTS" requires MySQL 8.0.29+ / MariaDB
-- 10.3+ — not safe to assume on every server (confirmed on production,
-- 18 Jul 2026: this exact clause threw MySQL error #1064 there, despite
-- migrations/README.md's older note claiming the live server was
-- MariaDB 10.6). Guarded via a throwaway stored procedure instead, which
-- works on effectively every MySQL/MariaDB version still in use.
-- --------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS `_m015_add_growth_agent_jobs_priority`$$
CREATE PROCEDURE `_m015_add_growth_agent_jobs_priority`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'growth_agent_jobs'
           AND COLUMN_NAME = 'priority'
    ) THEN
        ALTER TABLE `growth_agent_jobs`
            ADD COLUMN `priority` ENUM('normal','high') NOT NULL DEFAULT 'normal' AFTER `status`;
    END IF;
END$$
DELIMITER ;

CALL `_m015_add_growth_agent_jobs_priority`();
DROP PROCEDURE IF EXISTS `_m015_add_growth_agent_jobs_priority`;

-- Verify result
SHOW COLUMNS FROM `gsc_settings`;
SHOW COLUMNS FROM `gsc_query_data`;
SHOW COLUMNS FROM `growth_agent_jobs`;
