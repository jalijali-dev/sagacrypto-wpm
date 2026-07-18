-- ============================================================
-- Migration 005: Featured/Pamungkas homepage section builder (Fase 4).
--
-- Already auto-creates on first visit to
-- cms-admin/pages/featured-content.php (idempotent cms_ensure_table).
-- This file is the formal record — safe to run manually on a fresh DB:
--   mysql -u <user> -p <database> < 005_featured_sections.sql
-- ============================================================

-- --------------------------------------------------------
-- Table: featured_sections — one row per homepage section block
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `featured_sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `content_type` enum(
    'manual','latest','trending','category','crypto_api',
    'livescore_api','ad_banner','app_promo_android','app_promo_ios'
  ) NOT NULL DEFAULT 'latest',
  `category_id` int(10) unsigned DEFAULT NULL,
  `item_count` int(10) unsigned NOT NULL DEFAULT 6,
  `layout` enum('grid','list','carousel','hero') NOT NULL DEFAULT 'grid',
  `show_on_desktop` tinyint(1) NOT NULL DEFAULT 1,
  `show_on_mobile` tinyint(1) NOT NULL DEFAULT 1,
  `ad_position_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: featured_section_items — manual picks for content_type='manual'
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `featured_section_items` (
  `section_id` int(10) unsigned NOT NULL,
  `page_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`section_id`,`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No seed data — sections are created by the admin from
-- cms-admin/pages/featured-content.php, none ship by default.

-- Verify result
SHOW COLUMNS FROM `featured_sections`;
SHOW COLUMNS FROM `featured_section_items`;
