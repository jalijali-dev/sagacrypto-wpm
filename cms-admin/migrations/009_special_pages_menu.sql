-- ============================================================
-- Migration 009: Special Pages nav-menu integration (13 Jul 2026).
--
-- Adds two columns to the pre-existing `special_pages` table (base
-- CREATE TABLE lives in 000_base_schema.sql) so a published special page
-- can opt into showing up as its own item in the site's main navbar,
-- between "Tentang Kami" and "Kontak" — see wpm_nav_menu() and
-- wpm_special_pages_in_menu() in includes/site-bootstrap.php.
--
-- Already auto-creates on first visit to cms-admin/pages/special-pages.php
-- (idempotent cms_ensure_column() calls). This file is the formal,
-- version-controlled record — safe to run manually:
--   mysql -u <user> -p <database> < 009_special_pages_menu.sql
-- ============================================================

ALTER TABLE `special_pages`
    ADD COLUMN IF NOT EXISTS `show_in_menu` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;
ALTER TABLE `special_pages`
    ADD COLUMN IF NOT EXISTS `menu_order` INT NOT NULL DEFAULT 0 AFTER `show_in_menu`;

-- Both default to 0 for every existing row (including the pre-pivot
-- TheAwsoft seed data: home/about/services/portfolio/articles/contact) —
-- nothing new appears in the live menu just because these columns now
-- exist. An admin has to explicitly tick "Tampilkan di menu navigasi
-- utama" per page.

-- Verify result
SHOW COLUMNS FROM `special_pages`;
