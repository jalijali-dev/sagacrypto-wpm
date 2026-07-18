-- ============================================================
-- Migration 004: Advertisement management (Fase 3) — ad_settings,
-- ad_positions (+ default 11 positions), advertisements.
--
-- Already auto-creates on first visit to cms-admin/pages/ads.php,
-- ad-positions.php, or ad-settings.php (idempotent cms_ensure_table).
-- This file is the formal record — safe to run manually on a fresh DB:
--   mysql -u <user> -p <database> < 004_advertisements.sql
-- ============================================================

-- --------------------------------------------------------
-- Table: ad_settings — single-row global ad configuration
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ads_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `popup_frequency_hours` int(10) unsigned NOT NULL DEFAULT 24,
  `sticky_mobile_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `show_ad_label` tinyint(1) NOT NULL DEFAULT 1,
  `global_header_script` text DEFAULT NULL,
  `global_footer_script` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: ad_positions — the 11 fixed slots referenced throughout the
-- public site (wpm_render_ad_slot($pdo, '<slug>') calls in
-- includes/site-header.php, site-footer.php, index.php, artikel.php,
-- kategori.php, crypto.php, livescore.php) — slugs below must match
-- those call sites exactly.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_positions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ad_position_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: advertisements
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `advertisements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `ad_type` enum('image','html','video') NOT NULL DEFAULT 'image',
  `banner_image` varchar(255) DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `html_code` text DEFAULT NULL,
  `target_url` varchar(255) DEFAULT NULL,
  `cta_text` varchar(100) DEFAULT NULL,
  `position_id` int(10) unsigned DEFAULT NULL,
  `placement_scope` enum('global','homepage','category','article','crypto','livescore','apps') NOT NULL DEFAULT 'global',
  `placement_target_id` int(11) DEFAULT NULL,
  `device` enum('all','desktop','mobile') NOT NULL DEFAULT 'all',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `impressions` int(10) unsigned NOT NULL DEFAULT 0,
  `clicks` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ads_position` (`position_id`),
  KEY `idx_ads_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Seed: singleton ad_settings row
-- --------------------------------------------------------
INSERT INTO `ad_settings` (`ads_enabled`, `popup_frequency_hours`, `sticky_mobile_enabled`, `show_ad_label`)
SELECT 1, 24, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `ad_settings`);

-- --------------------------------------------------------
-- Seed: the 11 default ad positions (slugs generated the same way
-- cms_slugify() does: lowercase, non-alphanumeric runs -> single hyphen)
-- --------------------------------------------------------
INSERT IGNORE INTO `ad_positions` (`name`, `slug`) VALUES
('Header',                 'header'),
('Below Main Menu',        'below-main-menu'),
('Homepage Hero',          'homepage-hero'),
('Sidebar',                'sidebar'),
('Above Article',          'above-article'),
('Middle of Article',      'middle-of-article'),
('Below Article',          'below-article'),
('Between Article Cards',  'between-article-cards'),
('Footer',                 'footer'),
('Popup',                  'popup'),
('Sticky Bottom Mobile',   'sticky-bottom-mobile');

-- Verify result
SHOW COLUMNS FROM `ad_settings`;
SHOW COLUMNS FROM `ad_positions`;
SHOW COLUMNS FROM `advertisements`;
