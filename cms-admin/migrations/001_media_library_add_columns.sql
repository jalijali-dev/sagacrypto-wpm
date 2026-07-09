-- ============================================================
-- Migration 001: add missing columns to media_library & gallery
-- Run once via phpMyAdmin, mysql CLI, or the browser script:
--   http://localhost:8008/wpm/cms-admin/migrate-media-library.php
-- ============================================================

-- media_library: add mime_type (after file_type)
ALTER TABLE `media_library`
    ADD COLUMN IF NOT EXISTS `mime_type` VARCHAR(100) DEFAULT NULL AFTER `file_type`;

-- media_library: add file_size_kb (after mime_type)
ALTER TABLE `media_library`
    ADD COLUMN IF NOT EXISTS `file_size_kb` INT(10) UNSIGNED DEFAULT NULL AFTER `mime_type`;

-- media_library: add is_active (after file_size_kb), default 1 = active
ALTER TABLE `media_library`
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size_kb`;

-- media_library: add updated_at (after created_at)
ALTER TABLE `media_library`
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- gallery: add media_id FK column (after id) used by media-library JOIN
ALTER TABLE `gallery`
    ADD COLUMN IF NOT EXISTS `media_id` INT(10) UNSIGNED DEFAULT NULL AFTER `id`;

-- Optional: add index for the FK join
ALTER TABLE `gallery`
    ADD INDEX IF NOT EXISTS `idx_gallery_media_id` (`media_id`);

-- Verify result
SHOW COLUMNS FROM `media_library`;
SHOW COLUMNS FROM `gallery`;
