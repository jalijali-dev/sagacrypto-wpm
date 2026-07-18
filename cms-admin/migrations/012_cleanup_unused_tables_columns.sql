-- ============================================================
-- Migration 012: Cleanup unused tables & columns (DB audit, 14 Jul 2026).
--
-- ⚠️  DESTRUCTIVE — this permanently DROPS 5 tables and 3 columns, plus
-- their data. Back up first if you're unsure:
--   mysqldump -u <user> -p <database> menus portfolio services gallery \
--     special_pages media_library > cleanup_backup_before_012.sql
--
-- Context: full codebase grep audit (every table + every column defined
-- across cms-admin/migrations/*.sql, checked against every .php file
-- outside the migrations folder) requested by the project owner
-- ("field atau table yg ga kepake sebaiknya di hapus saja") to find
-- genuinely dead schema. Findings:
--
-- TABLES — zero references anywhere in the codebase (no query, no admin
-- page, no include):
--   * `menus`          — no admin page ever existed for this in the
--                        current codebase; nothing reads/writes it.
--   * `portfolio`      — same, no admin page, no query.
--   * `services`       — same. (The only "services" hits in a codebase
--                        grep are the unrelated `/services/PromptLoader.php`
--                        folder path — not this table.)
--   * `gallery`        — already flagged unused during the Fase 1 product
--                        cleanup (13 Jul 2026, see 008_remove_products.sql)
--                        and left in place "in case it's wanted later."
--                        Still zero references now — dropping it per
--                        this round's explicit instruction.
--   * `special_pages`  — leftover from the Special Pages admin feature,
--                        which was fully removed (admin UI + frontend
--                        route + nav wiring) on 14 Jul 2026. The table
--                        was intentionally kept at the time "in case data
--                        was worth preserving" (see migrations/README.md,
--                        009's note) — now being dropped per this
--                        instruction since nothing reads it anymore.
--
-- COLUMNS — zero references outside the CREATE TABLE itself (checked
-- against the full admin + frontend codebase):
--   * `media_library.original_name` — app uses `file_name` instead.
--   * `media_library.file_size`     — superseded by `file_size_kb`
--                                      (added later in
--                                      001_media_library_add_columns.sql),
--                                      which IS actively used.
--   * `media_library.uploaded_by`   — no upload flow in this codebase
--                                      ever writes an admin id here;
--                                      has an FK to `admins`, dropped
--                                      first below.
--
-- NOTE: `products` / `product_categories` / `product_images` /
-- `product_tags` / `product_tag_map` were already flagged in
-- 008_remove_products.sql (13 Jul 2026) but that migration was never
-- actually run — if you want a fully clean database, run 008 together
-- with this file.
--
--   mysql -u <user> -p <database> < 012_cleanup_unused_tables_columns.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---- Unused tables ----
DROP TABLE IF EXISTS `menus`;
DROP TABLE IF EXISTS `portfolio`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `gallery`;
DROP TABLE IF EXISTS `special_pages`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---- Unused columns on media_library (still-active table) ----
ALTER TABLE `media_library` DROP FOREIGN KEY `media_library_uploaded_by_fk`;
ALTER TABLE `media_library` DROP INDEX `uploaded_by`;
ALTER TABLE `media_library` DROP COLUMN `uploaded_by`;
ALTER TABLE `media_library` DROP COLUMN `file_size`;
ALTER TABLE `media_library` DROP COLUMN `original_name`;

-- Verify result — none of these five should appear anymore
SHOW TABLES LIKE 'menus';
SHOW TABLES LIKE 'portfolio';
SHOW TABLES LIKE 'services';
SHOW TABLES LIKE 'gallery';
SHOW TABLES LIKE 'special_pages';
DESCRIBE `media_library`;
