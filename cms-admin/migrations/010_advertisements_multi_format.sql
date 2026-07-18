-- ============================================================
-- Migration 010: Advertisements module — multi-format ads (14 Jul 2026).
--
-- Widens the Advertisements module from image/HTML/video-only to 5 ad
-- types: Image banner (existing, unchanged), Custom HTML (existing
-- 'html'/html_code column, unchanged), Video (existing, unchanged), plus
-- two NEW types: Text Ad ('text') and External Ad Code ('external_code').
--
-- Already auto-creates/auto-widens on first visit to
-- cms-admin/pages/ads.php (idempotent cms_ensure_column() calls + a
-- naturally-idempotent MODIFY COLUMN for the two enums). This file is the
-- formal, version-controlled record — safe to run manually on a fresh DB:
--   mysql -u <user> -p <database> < 010_advertisements_multi_format.sql
--
-- Requires migration 004 to have run first (creates the base
-- advertisements/ad_positions/ad_settings tables).
--
-- Compatibility: every column added below is nullable or has a safe
-- default, and NOTHING existing is renamed or dropped — banner_image,
-- video_url, html_code, target_url, cta_text, device, placement_scope all
-- keep their exact names/meaning. Every ad row created before this
-- migration is `ad_type = 'image'` and keeps rendering exactly as before.
-- ============================================================

-- --------------------------------------------------------
-- advertisements: widen ad_type to add 'text' and 'external_code'
-- (MODIFY COLUMN is naturally idempotent — safe to re-run).
-- --------------------------------------------------------
ALTER TABLE `advertisements`
    MODIFY COLUMN `ad_type` ENUM('image','html','video','text','external_code') NOT NULL DEFAULT 'image';

-- --------------------------------------------------------
-- advertisements: widen device targeting to add 'tablet'.
-- --------------------------------------------------------
ALTER TABLE `advertisements`
    MODIFY COLUMN `device` ENUM('all','desktop','mobile','tablet') NOT NULL DEFAULT 'all';

-- --------------------------------------------------------
-- advertisements: new columns for Text Ad, Video Ad extras, and
-- External Ad Code. `custom_html`/`image_path` from the brief map onto
-- the EXISTING `html_code`/`banner_image` columns — not duplicated.
-- --------------------------------------------------------
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `advertiser_label` VARCHAR(50) NOT NULL DEFAULT 'Ad' AFTER `ad_type`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `display_domain` VARCHAR(100) DEFAULT NULL AFTER `advertiser_label`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `headline` VARCHAR(90) DEFAULT NULL AFTER `display_domain`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `description` VARCHAR(180) DEFAULT NULL AFTER `headline`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `open_in_new_tab` TINYINT(1) NOT NULL DEFAULT 1 AFTER `cta_text`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `show_sponsored_label` TINYINT(1) NOT NULL DEFAULT 1 AFTER `open_in_new_tab`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `image_alt` VARCHAR(150) DEFAULT NULL AFTER `banner_image`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_path` VARCHAR(255) DEFAULT NULL AFTER `video_url`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_poster` VARCHAR(255) DEFAULT NULL AFTER `video_path`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_autoplay` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_poster`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_muted` TINYINT(1) NOT NULL DEFAULT 1 AFTER `video_autoplay`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_loop` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_muted`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `video_controls` TINYINT(1) NOT NULL DEFAULT 1 AFTER `video_loop`;
ALTER TABLE `advertisements`
    ADD COLUMN IF NOT EXISTS `external_code` TEXT DEFAULT NULL AFTER `html_code`;

-- --------------------------------------------------------
-- ad_settings: rotation mode when multiple active ads share one position
-- (section 14 of the brief) — priority (existing sort_order behaviour,
-- default), random, or sequential (round-robin per request).
-- --------------------------------------------------------
ALTER TABLE `ad_settings`
    ADD COLUMN IF NOT EXISTS `rotation_mode` ENUM('priority','random','sequential') NOT NULL DEFAULT 'priority' AFTER `show_ad_label`;

-- --------------------------------------------------------
-- ad_positions: new position keys (section 5 of the brief). Existing
-- positions are untouched. 'sidebar' is intentionally left in place
-- (nothing renders it anymore as of this migration — see the
-- reassignment step below) rather than deleted, so it doesn't orphan any
-- historical reporting that joins on it.
-- --------------------------------------------------------
INSERT IGNORE INTO `ad_positions` (`name`, `slug`) VALUES
('Article — Before Title', 'article-before-title'),
('Article — After Title',  'article-after-title'),
('Sidebar (Left)',         'sidebar-left'),
('Sidebar (Right)',        'sidebar-right');

-- --------------------------------------------------------
-- One-time data fix: the old single 'sidebar' position used to be
-- rendered on BOTH sides of the article layout (includes/artikel.php had
-- two wpm_render_ad_slot($pdo, 'sidebar', ...) calls) — the exact
-- duplicate-ad symptom described in the brief. As of this migration the
-- two sides are split into 'sidebar-left' and 'sidebar-right'. Any ad
-- still assigned to the old 'sidebar' slot is moved onto 'sidebar-left'
-- so it keeps appearing somewhere instead of silently disappearing.
-- Safe to re-run: after the first run nothing matches the WHERE clause.
-- --------------------------------------------------------
UPDATE `advertisements`
SET `position_id` = (SELECT id FROM `ad_positions` WHERE slug = 'sidebar-left' LIMIT 1)
WHERE `position_id` = (SELECT id FROM `ad_positions` WHERE slug = 'sidebar' LIMIT 1);

-- Verify result
SHOW COLUMNS FROM `advertisements`;
SHOW COLUMNS FROM `ad_settings`;
SELECT id, name, slug FROM `ad_positions` ORDER BY name ASC;
