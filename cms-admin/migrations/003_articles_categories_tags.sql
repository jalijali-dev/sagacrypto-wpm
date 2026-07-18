-- ============================================================
-- Migration 003: Articles module — categories, tags, SEO/engagement
-- columns on the pre-existing `pages` table (Fase 2).
--
-- These tables/columns already auto-create the moment an admin opens
-- cms-admin/pages/pages.php, cms-admin/pages/article-categories.php, or
-- cms-admin/pages/article-tags.php (idempotent cms_ensure_table /
-- cms_ensure_column calls). This file is the formal, version-controlled
-- record of that same schema — safe to run manually (e.g. on a fresh
-- database before the admin panel is ever opened) via phpMyAdmin or:
--   mysql -u <user> -p <database> < 003_articles_categories_tags.sql
-- ============================================================

-- --------------------------------------------------------
-- Table: article_categories
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_category_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: article_tags
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_tag_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: article_tag_map — many-to-many pages <-> article_tags
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_tag_map` (
  `page_id` int(11) NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`page_id`,`tag_id`),
  KEY `idx_article_tag_map_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- pages: pre-existing table (created before this schema-guard system
-- existed — its base CREATE TABLE is NOT in this repo, see README.md).
-- These columns bolt article/SEO/engagement features onto it.
-- --------------------------------------------------------
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED DEFAULT NULL AFTER `slug`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `author_id` INT UNSIGNED DEFAULT NULL AFTER `category_id`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `meta_keywords` VARCHAR(255) DEFAULT NULL AFTER `meta_description`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `canonical_url` VARCHAR(255) DEFAULT NULL AFTER `meta_keywords`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `og_image` VARCHAR(255) DEFAULT NULL AFTER `canonical_url`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `is_featured` TINYINT(1) NOT NULL DEFAULT 0 AFTER `og_image`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `is_trending` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_featured`;
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `views` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_trending`;

-- --------------------------------------------------------
-- Seed: default category set (only meaningful the very first time —
-- INSERT IGNORE means re-running this is always safe)
-- --------------------------------------------------------
INSERT IGNORE INTO `article_categories` (`name`, `slug`) VALUES
('Crypto News',   'crypto-news'),
('Market',        'market'),
('Bitcoin',       'bitcoin'),
('Altcoin',       'altcoin'),
('Blockchain',    'blockchain'),
('Technology',    'technology'),
('Business',      'business'),
('Sports',        'sports'),
('Livescore',     'livescore'),
('Apps',          'apps'),
('Guides',        'guides'),
('General News',  'general-news');

-- Verify result
SHOW COLUMNS FROM `article_categories`;
SHOW COLUMNS FROM `article_tags`;
SHOW COLUMNS FROM `article_tag_map`;
SHOW COLUMNS FROM `pages`;
