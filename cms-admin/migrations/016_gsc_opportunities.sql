-- ============================================================
-- Migration 016: Prioritized Opportunities (Growth Agent x GSC, revisi)
--
-- Revises the GSC integration from 015_gsc_search_console.sql: instead of
-- eager bulk AI-generation ("Scan GSC Opportunities"), opportunities are
-- now computed as pure data/scoring (no AI call) into gsc_opportunities,
-- shown to the operator for curation, and AI generation only happens
-- on-demand per item the operator picks. See
-- docs/GSC_OPPORTUNITIES_REVISION.md for the full design/rationale.
--
-- This table already auto-creates the moment cms-admin/pages/growth-agent.php
-- is opened (idempotent cms_ensure_table()/cms_ensure_column() calls in
-- cms-admin/includes/gsc-api.php). This file is the formal,
-- version-controlled record of that same schema — safe to run manually via
-- phpMyAdmin or:
--   mysql -u <user> -p <database> < 016_gsc_opportunities.sql
--
-- No FK constraints, matching this repo's existing convention.
-- ============================================================

-- --------------------------------------------------------
-- Table: gsc_opportunities — scored candidates computed purely from
-- gsc_query_data (no AI call at compute time — see cms_gsc_compute_
-- opportunities() in gsc-api.php). Recomputed automatically after every
-- cms_gsc_fetch_and_cache() run, plus a manual "Recompute Opportunities"
-- button. Rows already 'actioned' (AI generation triggered) are frozen —
-- recompute only upserts 'open' rows.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gsc_opportunities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_type` enum('page','query') NOT NULL,
  `matched_page_id` int(10) unsigned DEFAULT NULL COMMENT 'set when item_type=page',
  `query_text` varchar(255) DEFAULT NULL COMMENT 'set when item_type=query',
  `matched_categories` varchar(255) NOT NULL DEFAULT '' COMMENT 'CSV: Low CTR, Zero-click, Page-one, No article',
  `impact_score` tinyint(3) unsigned NOT NULL,
  `effort_score` tinyint(3) unsigned NOT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `recommended_agent` varchar(50) NOT NULL COMMENT 'seo_agent or growth_agent (ai_agent_settings.agent_key)',
  `recommended_action` enum('seo_recommendation','gsc_content_optimization','gsc_article_idea') NOT NULL,
  `reason` text DEFAULT NULL COMMENT 'parametrized narrative template, not AI-generated',
  `metrics_json` text DEFAULT NULL COMMENT 'raw GSC numbers snapshot (impressions/clicks/ctr/position/top_queries) reused at generate time',
  `status` enum('open','actioned') NOT NULL DEFAULT 'open',
  `linked_job_id` int(10) unsigned DEFAULT NULL COMMENT 'growth_agent_jobs.id once Generate is clicked',
  `dedupe_key` char(32) NOT NULL COMMENT 'MD5(item_type|matched_page_id or query_text)',
  `computed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gsc_opp_dedupe` (`dedupe_key`),
  KEY `idx_gsc_opp_status_priority` (`status`, `priority`),
  KEY `idx_gsc_opp_page` (`matched_page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- gsc_settings: pre-existing table (015_gsc_search_console.sql, not
-- edited). Adds one column — every scoring threshold from
-- docs/GSC_OPPORTUNITIES_REVISION.md § 2 lives in this single JSON blob
-- (not scattered across PHP constants), per explicit request so
-- thresholds stay easy to tune later without touching code. See
-- cms_gsc_default_opportunity_thresholds() in gsc-api.php for the shape
-- and default values.
-- --------------------------------------------------------
ALTER TABLE `gsc_settings`
    ADD COLUMN IF NOT EXISTS `opportunity_thresholds_json` LONGTEXT DEFAULT NULL AFTER `fetch_window_days`;

-- --------------------------------------------------------
-- growth_agent_jobs: pre-existing table (014_growth_agent.sql /
-- 015_gsc_search_console.sql, not edited). Widens `priority` from a
-- 2-tier normal/high enum to 3-tier low/medium/high, to match
-- gsc_opportunities.priority 1:1 when a job is generated from an
-- opportunity. MySQL enum rename needs 3 steps (widen -> migrate data ->
-- narrow) since a straight MODIFY would silently blank out any existing
-- 'normal' value that isn't in the new enum list.
-- --------------------------------------------------------
ALTER TABLE `growth_agent_jobs`
    MODIFY COLUMN `priority` ENUM('normal','low','medium','high') NOT NULL DEFAULT 'normal';
UPDATE `growth_agent_jobs` SET `priority` = 'medium' WHERE `priority` = 'normal';
ALTER TABLE `growth_agent_jobs`
    MODIFY COLUMN `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium';

-- Verify result
SHOW COLUMNS FROM `gsc_opportunities`;
SHOW COLUMNS FROM `gsc_settings`;
SHOW COLUMNS FROM `growth_agent_jobs`;
