-- ============================================================
-- Migration 020: Contact form anti-spam (Cloudflare Turnstile)
--
-- Adds 2 new columns to the pre-existing `site_settings` table
-- (000_base_schema.sql, not edited) so the public "Kontak" form on
-- index.php can be protected by Cloudflare Turnstile (free, invisible
-- checkbox widget) -- see cms-admin/includes/turnstile.php.
--
-- Both columns are nullable/empty by default: Turnstile stays OFF
-- (contact-submit.php falls back to the existing honeypot-only check)
-- until an admin pastes a site key + secret key into Site Settings.
--
-- Same "throwaway stored procedure" guard as migration 019 -- this
-- project's production MySQL/MariaDB version does not support
-- "ADD COLUMN IF NOT EXISTS" (see migrations/README.md).
--
-- Run via phpMyAdmin or:
--   mysql -u <user> -p <database> < 020_contact_antispam.sql
-- ============================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `_m020_add_site_settings_turnstile_cols`$$
CREATE PROCEDURE `_m020_add_site_settings_turnstile_cols`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'site_settings'
           AND COLUMN_NAME = 'turnstile_site_key'
    ) THEN
        ALTER TABLE `site_settings`
            ADD COLUMN `turnstile_site_key` VARCHAR(255) DEFAULT NULL AFTER `google_analytics_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'site_settings'
           AND COLUMN_NAME = 'turnstile_secret_key'
    ) THEN
        ALTER TABLE `site_settings`
            ADD COLUMN `turnstile_secret_key` VARCHAR(255) DEFAULT NULL AFTER `turnstile_site_key`;
    END IF;
END$$
DELIMITER ;

CALL `_m020_add_site_settings_turnstile_cols`();
DROP PROCEDURE IF EXISTS `_m020_add_site_settings_turnstile_cols`;

-- Verify
SHOW COLUMNS FROM `site_settings`;
