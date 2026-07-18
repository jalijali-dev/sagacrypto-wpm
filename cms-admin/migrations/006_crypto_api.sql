-- ============================================================
-- Migration 006: Crypto API integration (Fase 5), including the Live
-- Ticker columns added later in the same table (live_ticker_enabled,
-- live_ticker_symbols — baked directly into the CREATE TABLE below since
-- this file represents the schema's current state, not a literal replay
-- of every ALTER TABLE that ran historically).
--
-- Already auto-creates on first visit to any Crypto API admin page
-- (cms-admin/includes/crypto-api.php: cms_crypto_ensure_schema(), called
-- idempotently). This file is the formal record — safe to run manually
-- on a fresh DB:
--   mysql -u <user> -p <database> < 006_crypto_api.sql
-- ============================================================

-- --------------------------------------------------------
-- Table: crypto_api_settings — single-row provider config
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crypto_api_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(100) NOT NULL DEFAULT 'CoinGecko',
  `base_url` varchar(255) NOT NULL DEFAULT 'https://api.coingecko.com/api/v3',
  `endpoint` varchar(255) NOT NULL DEFAULT '/coins/markets',
  `api_key` varchar(255) DEFAULT NULL,
  `api_key_header` varchar(100) DEFAULT NULL,
  `default_currency` varchar(10) NOT NULL DEFAULT 'usd',
  `coins_limit` int(10) unsigned NOT NULL DEFAULT 10,
  `refresh_interval` int(10) unsigned NOT NULL DEFAULT 60,
  `cache_duration` int(10) unsigned NOT NULL DEFAULT 300,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  -- Live Ticker (client-side price bar, polls crypto-ticker-data.php —
  -- see includes/site-bootstrap.php) — independent of the REST settings
  -- above, off by default.
  `live_ticker_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `live_ticker_symbols` varchar(255) NOT NULL DEFAULT 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT',
  `last_test_status` varchar(20) DEFAULT NULL,
  `last_test_message` varchar(255) DEFAULT NULL,
  `last_test_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: crypto_cache — last-fetched payload, reused across requests
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crypto_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payload` longtext NOT NULL,
  `fetched_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: crypto_coin_settings — per-coin display overrides
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crypto_coin_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `symbol` varchar(20) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_crypto_coin_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: api_error_log — shared by Crypto API AND Livescore API
-- (source='crypto' / source='livescore'); created here since Fase 5
-- (Crypto) came before Fase 6 (Livescore). Migration 007 does not
-- recreate it, just reuses it.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_error_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_error_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Seed: singleton crypto_api_settings row (defaults to CoinGecko,
-- inactive until an admin adds an API key / flips it on)
-- --------------------------------------------------------
INSERT INTO `crypto_api_settings` (`provider`)
SELECT 'CoinGecko'
WHERE NOT EXISTS (SELECT 1 FROM `crypto_api_settings`);

-- Verify result
SHOW COLUMNS FROM `crypto_api_settings`;
SHOW COLUMNS FROM `crypto_cache`;
SHOW COLUMNS FROM `crypto_coin_settings`;
SHOW COLUMNS FROM `api_error_log`;
