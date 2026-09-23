-- ============================================================
-- Migration 019: Market Sentiment — BTC Dominance & Fear and Greed Index
-- (Fase A of the "Crypto Intelligence" repositioning). See
-- docs/MARKET_SENTIMENT_PLAN.md for the full design.
--
-- New table `market_sentiment_cache` (separate from `crypto_cache` —
-- different payload shape, own cache lifecycle) + two new columns on the
-- pre-existing `crypto_api_settings` table (006_crypto_api.sql, not
-- edited), same "one place for all crypto-related toggles" pattern as
-- live_ticker_enabled/live_ticker_symbols.
--
-- This schema already auto-creates the moment cms-admin/pages/crypto-api.php
-- is opened (idempotent cms_ensure_table()/cms_ensure_column() calls in
-- cms-admin/includes/market-sentiment.php). This file is the formal,
-- version-controlled record — safe to run manually via phpMyAdmin or:
--   mysql -u <user> -p <database> < 019_market_sentiment.sql
--
-- No FK constraints, matching this repo's existing convention.
--
-- IMPORTANT — learned the hard way from 015/016 (see migrations/README.md
-- "Update (18 Jul 2026)"): "ADD COLUMN IF NOT EXISTS" requires MySQL
-- 8.0.29+/MariaDB 10.3+ and threw #1064 on this project's actual
-- production server. The ADD COLUMN below is guarded by a throwaway
-- stored procedure instead (check INFORMATION_SCHEMA.COLUMNS first),
-- which works on effectively any MySQL/MariaDB version.
-- ============================================================

-- --------------------------------------------------------
-- Table: market_sentiment_cache — last-fetched payload per metric
-- ('btc_dominance' | 'fear_greed'), reused across requests until stale.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `market_sentiment_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `metric` varchar(30) NOT NULL,
  `payload` longtext NOT NULL,
  `fetched_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_market_sentiment_metric` (`metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- crypto_api_settings: pre-existing table (006_crypto_api.sql, not
-- edited). Adds the on/off toggle + cache duration for Market Sentiment,
-- same table as every other crypto-related setting/toggle.
-- --------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS `_m019_add_crypto_settings_sentiment_cols`$$
CREATE PROCEDURE `_m019_add_crypto_settings_sentiment_cols`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'crypto_api_settings'
           AND COLUMN_NAME = 'market_sentiment_enabled'
    ) THEN
        ALTER TABLE `crypto_api_settings`
            ADD COLUMN `market_sentiment_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `live_ticker_symbols`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'crypto_api_settings'
           AND COLUMN_NAME = 'market_sentiment_cache_duration'
    ) THEN
        ALTER TABLE `crypto_api_settings`
            ADD COLUMN `market_sentiment_cache_duration` INT(10) UNSIGNED NOT NULL DEFAULT 900 AFTER `market_sentiment_enabled`;
    END IF;
END$$
DELIMITER ;

CALL `_m019_add_crypto_settings_sentiment_cols`();
DROP PROCEDURE IF EXISTS `_m019_add_crypto_settings_sentiment_cols`;

-- Verify result
SHOW COLUMNS FROM `market_sentiment_cache`;
SHOW COLUMNS FROM `crypto_api_settings`;
