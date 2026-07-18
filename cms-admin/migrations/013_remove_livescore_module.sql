-- ============================================================
-- Migration 013: Remove Livescore Sepak Bola module
-- ============================================================
-- OPT-IN / DESTRUCTIVE. Not auto-run by the app. Read this whole header
-- before running it, then run manually:
--   mysql -u <user> -p <database> < cms-admin/migrations/013_remove_livescore_module.sql
--
-- Context: the Livescore football feature (Matches, League Settings,
-- Livescore API admin pages; the /livescore frontend page, nav item,
-- homepage widget, and footer link; the Featured Content "Data dari
-- Livescore API" section type; the Advertisements "Halaman Livescore"
-- placement) was removed from the PHP/frontend code on 15 Jul 2026 — it
-- will be rebuilt later as a separate site/project. This migration is the
-- matching database cleanup. See SITEMAP.md Update Log (15 Jul 2026) for
-- the full code-side change list.
--
-- What this does, in order:
--   1. Defensively re-points any existing rows that reference the removed
--      feature to a safe fallback value, so nothing is left silently
--      broken/invisible (data-preserving, non-destructive).
--   2. Drops the three tables that exist ONLY for Livescore — confirmed by
--      full-codebase grep to have zero other callers (see report).
--
-- What this deliberately does NOT touch:
--   - `api_error_log` — SHARED with Crypto API (source='crypto' rows).
--     Only DELETEs the livescore-sourced log rows, does not drop the table.
--   - `advertisements.placement_scope` and `featured_sections.content_type`
--     ENUM *definitions* — both still list 'livescore'/'livescore_api' as
--     legal values. Shrinking a live ENUM via ALTER TABLE MODIFY is a
--     genuinely risky operation (can silently truncate/reject rows on some
--     MySQL/MariaDB versions if done wrong) for very little benefit, since
--     the app-level UI no longer offers them as choices anywhere. Left in
--     place intentionally — inert, harmless, documented here instead.
--   - `article_categories` — the "Livescore" row there (if it exists) is a
--     seed CONTENT category for tagging news articles (e.g. an article
--     about the livescore industry), part of the unrelated Pages/Articles
--     taxonomy feature. Not part of this module. Not touched.
--   - Any Crypto table (`crypto_api_settings`, `crypto_cache`,
--     `crypto_coin_settings`) — completely untouched by this file.
--
-- Rollback: this migration is not reversible via a "down" script (the
-- DROPped tables' data is gone). To restore the *schema* only (empty
-- tables, provider re-configured from scratch), re-run the three
-- `CREATE TABLE IF NOT EXISTS` statements from
-- `cms-admin/migrations/007_livescore_api.sql`. Take a manual backup
-- first if you have any doubt:
--   mysqldump -u <user> -p <database> livescore_api_settings livescore_cache livescore_leagues > livescore_backup_before_013.sql
-- ============================================================

-- --------------------------------------------------------
-- Step 1: safe re-pointing of rows that referenced the removed feature
-- --------------------------------------------------------

-- Any Featured Content homepage section still configured as
-- "Data dari Livescore API" falls back to "Artikel terbaru" instead of
-- silently rendering nothing forever (matches the app's own default).
UPDATE `featured_sections`
   SET `content_type` = 'latest'
 WHERE `content_type` = 'livescore_api';

-- Any ad still scoped to the (now-removed) Livescore page becomes a
-- global placement instead of a permanently-unreachable one. Review these
-- in the Ads admin page after running this migration if you want to
-- re-target or pause them instead.
UPDATE `advertisements`
   SET `placement_scope` = 'global'
 WHERE `placement_scope` = 'livescore';

-- Historical error-log noise from the Livescore API integration — safe to
-- clear since the source no longer exists. Comment this out if you want
-- to keep the history for reference.
DELETE FROM `api_error_log` WHERE `source` = 'livescore';

-- --------------------------------------------------------
-- Step 2: drop the three Livescore-only tables
-- --------------------------------------------------------

DROP TABLE IF EXISTS `livescore_leagues`;
DROP TABLE IF EXISTS `livescore_cache`;
DROP TABLE IF EXISTS `livescore_api_settings`;

-- Verify result — all three should return an empty set / error "doesn't exist"
SHOW TABLES LIKE 'livescore_%';
