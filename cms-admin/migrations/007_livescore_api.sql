-- ============================================================
-- Migration 007: Livescore API integration (Fase 6), including the
-- show_on_frontend column added later in the same table (baked directly
-- into the CREATE TABLE below — see note in migration 006 about why).
--
-- Already auto-creates on first visit to any Livescore API admin page
-- (cms-admin/includes/livescore-api.php: cms_livescore_ensure_schema(),
-- called idempotently). This file is the formal record — safe to run
-- manually on a fresh DB:
--   mysql -u <user> -p <database> < 007_livescore_api.sql
--
-- Requires migration 006 to have run first (shares the `api_error_log`
-- table created there — not recreated here).
-- ============================================================

-- --------------------------------------------------------
-- Table: livescore_api_settings — single-row provider config
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `livescore_api_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(100) NOT NULL DEFAULT 'API-Football (RapidAPI)',
  `base_url` varchar(255) NOT NULL DEFAULT 'https://api-football-v1.p.rapidapi.com/v3',
  `endpoint` varchar(255) NOT NULL DEFAULT '/fixtures',
  `api_key` varchar(255) DEFAULT NULL,
  `api_key_header` varchar(100) NOT NULL DEFAULT 'x-rapidapi-key',
  `api_host_header` varchar(100) NOT NULL DEFAULT 'x-rapidapi-host',
  `api_host_value` varchar(150) NOT NULL DEFAULT 'api-football-v1.p.rapidapi.com',
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `refresh_interval` int(10) unsigned NOT NULL DEFAULT 60,
  `cache_duration` int(10) unsigned NOT NULL DEFAULT 120,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  -- Separate from is_active: whether the Livescore menu/homepage
  -- section/dedicated page are actually shown to visitors. Off by
  -- default (added 13 Jul 2026, see SITEMAP.md Update Log).
  `show_on_frontend` tinyint(1) NOT NULL DEFAULT 0,
  `last_test_status` varchar(20) DEFAULT NULL,
  `last_test_message` varchar(255) DEFAULT NULL,
  `last_test_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: livescore_cache — keyed by mode ('today' / 'live')
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `livescore_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(30) NOT NULL,
  `payload` longtext NOT NULL,
  `fetched_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_livescore_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: livescore_leagues — league filter/allowlist
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `livescore_leagues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `league_id` int(10) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `country` varchar(100) DEFAULT NULL,
  `season` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_livescore_league` (`league_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Seed: singleton livescore_api_settings row (defaults to API-Football
-- via RapidAPI, inactive + hidden from frontend until an admin configures it)
-- --------------------------------------------------------
INSERT INTO `livescore_api_settings` (`provider`)
SELECT 'API-Football (RapidAPI)'
WHERE NOT EXISTS (SELECT 1 FROM `livescore_api_settings`);

-- Verify result
SHOW COLUMNS FROM `livescore_api_settings`;
SHOW COLUMNS FROM `livescore_cache`;
SHOW COLUMNS FROM `livescore_leagues`;
