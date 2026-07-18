-- ============================================================
-- Migration 011: Sitemaps module (14 Jul 2026).
--
-- New tables backing the admin "SEO Settings → Sitemaps" module:
--   sitemap_urls      — one row per URL that belongs (or belonged) in the
--                        sitemap, kept in sync with real content via hooks
--                        in pages.php / article-categories.php /
--                        article-tags.php / seo-redirects.php, and served
--                        live by the public /sitemap*.xml endpoints.
--   sitemap_changelog — append-only history of every sitemap-relevant
--                        content change (create/update/publish/unpublish/
--                        delete/slug change/include/exclude/redirect/
--                        manual regenerate/validate).
--   sitemap_settings  — singleton row: per-content-type inclusion +
--                        priority/changefreq defaults ("auto-rules"),
--                        plus last-run bookkeeping.
--
-- Also adds one column to the pre-existing `pages` table: `noindex`
-- (opt-out flag surfaced as a checkbox in the article editor) — nothing
-- else on `pages`/`article_categories`/`article_tags`/`seo_redirects` is
-- touched or renamed.
--
-- Already auto-creates on first visit to cms-admin/pages/sitemaps.php
-- (idempotent cms_ensure_table()/cms_ensure_column() calls in
-- cms-admin/includes/sitemap-service.php). This file is the formal,
-- version-controlled record — safe to run manually:
--   mysql -u <user> -p <database> < 011_sitemaps.sql
-- ============================================================

-- --------------------------------------------------------
-- Table: sitemap_urls
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sitemap_urls` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(500) NOT NULL COMMENT 'Full absolute URL (loc) — always scheme+host+path',
  `content_type` enum('homepage','article','category','tag','page','custom') NOT NULL,
  `content_id` int(10) unsigned DEFAULT NULL COMMENT 'FK-ish to pages.page_id / article_categories.id / article_tags.id — NULL for homepage/static page/custom',
  `content_title` varchar(255) DEFAULT NULL,
  `status` enum('published','draft','scheduled','deleted','redirected','excluded','error') NOT NULL DEFAULT 'published',
  `included` tinyint(1) NOT NULL DEFAULT 1,
  `priority` decimal(2,1) NOT NULL DEFAULT 0.5,
  `changefreq` enum('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
  `lastmod` datetime DEFAULT NULL,
  `last_detected_change` datetime DEFAULT NULL,
  `sitemap_file` varchar(40) NOT NULL DEFAULT 'sitemap-pages.xml',
  `overrides_json` text DEFAULT NULL COMMENT 'Which fields an admin manually pinned (priority/changefreq/included) so auto-rules stop overwriting them',
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sitemap_content` (`content_type`, `content_id`),
  KEY `idx_sitemap_type` (`content_type`),
  KEY `idx_sitemap_included` (`included`),
  KEY `idx_sitemap_status` (`status`),
  KEY `idx_sitemap_file` (`sitemap_file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: sitemap_changelog
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sitemap_changelog` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `action` enum('created','updated','published','unpublished','deleted','restored','slug_changed','included','excluded','redirected','sitemap_generated','validation_executed') NOT NULL,
  `content_type` varchar(30) DEFAULT NULL,
  `content_id` int(10) unsigned DEFAULT NULL,
  `old_url` varchar(500) DEFAULT NULL,
  `new_url` varchar(500) DEFAULT NULL,
  `changed_fields` text DEFAULT NULL COMMENT 'JSON: {field: [old, new]}',
  `triggered_by` varchar(150) NOT NULL DEFAULT 'System',
  `result` enum('success','error') NOT NULL DEFAULT 'success',
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_changelog_occurred` (`occurred_at`),
  KEY `idx_changelog_action` (`action`),
  KEY `idx_changelog_content_type` (`content_type`),
  KEY `idx_changelog_result` (`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: sitemap_settings — singleton row (same pattern as ad_settings/
-- site_settings). `rules_json` holds the per-content-type auto-rule
-- matrix (included/priority/changefreq defaults) as one JSON blob rather
-- than ~15 separate columns — see cms_sitemap_default_rules() in
-- cms-admin/includes/sitemap-service.php for the shape/defaults.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sitemap_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rules_json` text DEFAULT NULL,
  `last_generated_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- pages: opt-out-of-search-engines flag, surfaced as a checkbox in the
-- article editor (cms-admin/pages/pages.php). When set, the article is
-- forced out of the sitemap (status='excluded') and the frontend article
-- template emits <meta name="robots" content="noindex, nofollow">.
-- --------------------------------------------------------
ALTER TABLE `pages`
    ADD COLUMN IF NOT EXISTS `noindex` TINYINT(1) NOT NULL DEFAULT 0 AFTER `canonical_url`;

-- Verify result
SHOW COLUMNS FROM `sitemap_urls`;
SHOW COLUMNS FROM `sitemap_changelog`;
SHOW COLUMNS FROM `sitemap_settings`;
SHOW COLUMNS FROM `pages`;
