# Migrations — SagaCrypto/WPM

Formal, version-controlled `.sql` record of the database schema (Fase 10).

**Verified against a live export** (`wpm_cms`, 13 Jul 2026, 38 tables) —
every file below was cross-checked column-by-column against the real
production database, not just inferred from code. `000_base_schema.sql`
was added specifically because that export revealed several tables that
existed live but had no `CREATE TABLE` anywhere in this repo.

## Why these exist alongside the auto-migration system

Most tables in this app (`crypto_api_settings`, `livescore_api_settings`,
`advertisements`, `ad_positions`, `ad_settings`, `article_categories`,
`article_tags`, `article_tag_map`, `featured_sections`,
`featured_section_items`, and a handful of columns bolted onto the
pre-existing `pages`/`media_library`/`gallery` tables) already
**self-create automatically** the moment an admin opens the relevant page,
via idempotent helpers in `cms-admin/includes/schema-guard.php`
(`cms_ensure_table()` / `cms_ensure_column()`). That live system is not
being removed or replaced — it stays as the safety net it's always been.
(One exception noted below: `ad_settings` hadn't actually been created
live yet as of the 13 Jul export — the admin simply hasn't opened Ad
Settings — so it'll appear the first time that page loads, or if you run
`004_advertisements.sql` manually.)

These `.sql` files exist for three things the live auto-migration can't do:

1. **Fresh installs** — stand up a brand-new database in one shot
   (`mysql < 000_....sql`, `001_...`, etc.) instead of having to click
   through every single admin page in the right order to trigger each
   lazy migration.
2. **Documentation / audit trail** — a readable, greppable record of
   exactly what the schema looks like and when each piece was added,
   independent of having to read PHP across a dozen files.
3. **Disaster recovery / staging setup** — restoring or cloning the app
   onto a new server without necessarily wanting to re-trigger every
   PHP auto-migration path first.

## How to run

Pick whichever you have available:

```bash
mysql -u <user> -p <database> < cms-admin/migrations/000_base_schema.sql
mysql -u <user> -p <database> < cms-admin/migrations/001_media_library_add_columns.sql
mysql -u <user> -p <database> < cms-admin/migrations/002_ai_management.sql
mysql -u <user> -p <database> < cms-admin/migrations/003_articles_categories_tags.sql
mysql -u <user> -p <database> < cms-admin/migrations/004_advertisements.sql
mysql -u <user> -p <database> < cms-admin/migrations/005_featured_sections.sql
mysql -u <user> -p <database> < cms-admin/migrations/006_crypto_api.sql
mysql -u <user> -p <database> < cms-admin/migrations/007_livescore_api.sql
# 008 is destructive/opt-in — read it before running, see its own header:
mysql -u <user> -p <database> < cms-admin/migrations/008_remove_products.sql
mysql -u <user> -p <database> < cms-admin/migrations/009_special_pages_menu.sql
```

Or import each file in order through phpMyAdmin (Import tab). Every
statement in 000-007 uses `CREATE TABLE IF NOT EXISTS` /
`ADD COLUMN IF NOT EXISTS` / `INSERT IGNORE` (or an equivalent
`NOT EXISTS` guard), so **running them again later is always safe** — same
idempotent philosophy as the PHP side. 008 is the one exception: it
**drops tables**, is not idempotent-safe in the sense of being undoable,
and is meant to be run once, deliberately, after reading its header.

Run them **in numeric order starting from 000**; 001 and 003 ALTER tables
that 000 creates, 007 assumes 006 already created `api_error_log`, etc.

Note: `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` requires
MySQL 8.0.29+ or MariaDB 10.3+ (the live server is MariaDB 10.6, so this
is fine there). If your server is older, drop the `IF NOT EXISTS` clause
and check manually first (or just let the live PHP auto-migration handle
it instead — that route doesn't need this syntax).

**Update (18 Jul 2026):** the assumption above turned out wrong in
practice — `015_gsc_search_console.sql` hit MySQL error #1064 ("syntax
error near IF NOT EXISTS") on the actual production server on its first
real run, contradicting this file's own claim about MariaDB 10.6. (Worth
checking the real server version if this matters again — every migration
000-014 uses this same `ADD COLUMN IF NOT EXISTS` clause and has
apparently never actually been run raw against production before now;
they were all effectively exercised only via the PHP lazy auto-migration
path, which doesn't have this syntax problem — see `cms_ensure_column()`
in `schema-guard.php`, it checks `information_schema.COLUMNS` first, same
spirit as the fix below.) `015` and `016` were patched to guard every
`ADD COLUMN` behind a throwaway stored procedure
(`IF NOT EXISTS (SELECT ... FROM INFORMATION_SCHEMA.COLUMNS ...) THEN
ALTER TABLE ... END IF`) instead of the bare SQL clause — portable across
effectively any MySQL/MariaDB version. Migrations `000`-`014` were **not**
touched (per this project's own rule: never edit old migration files) —
if any of them ever need a real raw run against this production server,
they'll hit the same #1064 and need the same treatment at that time.

## File index

| File | Fase | Tables |
|---|---|---|
| `000_base_schema.sql` | — | `admins`, `pages` (base), `media_library` (base), `gallery` (base), `site_settings`, `banners`, `testimonials`, `seo_redirects`, `seo_schema`, `contact_messages`, `special_pages`, `menus`, `landing_sections`, `portfolio`, `services`, `agent_prompts` |
| `001_media_library_add_columns.sql` | — | `media_library` (+columns), `gallery` (+column) |
| `002_ai_management.sql` | — | `ai_credentials`, `ai_models`, `ai_agent_settings` |
| `003_articles_categories_tags.sql` | Fase 2 | `article_categories`, `article_tags`, `article_tag_map`, `pages` (+columns) |
| `004_advertisements.sql` | Fase 3 | `ad_settings`, `ad_positions`, `advertisements` |
| `005_featured_sections.sql` | Fase 4 | `featured_sections`, `featured_section_items` |
| `006_crypto_api.sql` | Fase 5 | `crypto_api_settings` (incl. Live Ticker columns), `crypto_cache`, `crypto_coin_settings`, `api_error_log` |
| `007_livescore_api.sql` | Fase 6 | `livescore_api_settings` (incl. `show_on_frontend`), `livescore_cache`, `livescore_leagues` — **Note (15 Jul 2026):** the Livescore football feature (admin pages, frontend page/widgets, all PHP references) was removed from this project — it'll be rebuilt as a separate site/project. These three tables are now dropped by `013_remove_livescore_module.sql` below. |
| `008_remove_products.sql` | Fase 1 cleanup | **DROPS** `products`, `product_categories`, `product_images`, `product_tags`, `product_tag_map` — opt-in, destructive, read before running |
| `009_special_pages_menu.sql` | — | `special_pages` (+columns `show_in_menu`, `menu_order`) — nav-menu integration, 13 Jul 2026. **Note (14 Jul 2026):** the Special Pages admin feature and its frontend route/nav-menu wiring were removed the next day (see SITEMAP.md Update Log). The `special_pages` table was initially kept as-is since dropping it wasn't requested at the time — it is now dropped by `012_cleanup_unused_tables_columns.sql` below, per a later explicit request. |
| `010_advertisements_multi_format.sql` | — | `advertisements` (+15 columns for Text Ad/Video/External Ad Code, widens `ad_type` and `device` enums), `ad_settings` (+`rotation_mode`), `ad_positions` (+4 new position keys, fixes the sidebar-duplicate-ad bug) — 14 Jul 2026 |
| `011_sitemaps.sql` | — | `sitemap_urls`, `sitemap_changelog`, `sitemap_settings` (new Sitemaps module), `pages` (+`noindex`) — 14 Jul 2026 |
| `012_cleanup_unused_tables_columns.sql` | DB cleanup | **DROPS** `menus`, `portfolio`, `services`, `gallery`, `special_pages` (zero code references anywhere) + `media_library` (-`original_name`, -`file_size`, -`uploaded_by`, dead columns) — opt-in, destructive, read before running — 14 Jul 2026 |
| `013_remove_livescore_module.sql` | Module removal | **DROPS** `livescore_api_settings`, `livescore_cache`, `livescore_leagues` (Livescore football feature removed from the app, 15 Jul 2026 — will be rebuilt as a separate project). Also re-points any `featured_sections`/`advertisements` rows that referenced the removed feature to a safe fallback, and clears `api_error_log` rows with `source='livescore'` — opt-in, destructive, read before running |
| `014_growth_agent.sql` | Fase 2 (Growth Agent) | `growth_agent_jobs`, `growth_agent_feedback`, `growth_agent_style_rules`, `growth_agent_performance` (schema only, no ingestion yet) — instrumentation behind the new Growth Agent sidebar item (AI Management), 17 Jul 2026 |
| `015_gsc_search_console.sql` | Growth Agent × GSC | `gsc_settings` (service account credential + connected property, singleton), `gsc_query_data` (query/page/date performance cache), `growth_agent_jobs` (+`priority` enum `normal`/`high`) — 18 Jul 2026. See `docs/GSC_INTEGRATION_PLAN.md` for the full design (no cron — lazy-fetch on page load, no Composer — hand-rolled JWT + REST). |
| `016_gsc_opportunities.sql` | Growth Agent × GSC (revisi) | `gsc_opportunities` (scored candidates, no AI call at compute time), `gsc_settings` (+`opportunity_thresholds_json` — single tunable JSON blob for every scoring threshold), `growth_agent_jobs.priority` widened from 2-tier (`normal`/`high`) to 3-tier (`low`/`medium`/`high`, default `medium`) — 18 Jul 2026. Replaces the bulk "Scan GSC Opportunities" flow from `015` with a curated Prioritized Opportunities table + on-demand per-item generate. See `docs/GSC_OPPORTUNITIES_REVISION.md`. |
| `017_growth_agent_memory.sql` | Growth Agent "Agent Memory" | `growth_agent_memory` (winning_pattern/content_gap insights detected from `gsc_query_data` aggregated across its full retention window, admin-reviewed pending_review→active/archived before use), `gsc_settings` (+`memory_thresholds_json` — dedicated tunable JSON blob for detection/retention thresholds, kept separate from `opportunity_thresholds_json`; +`last_memory_detection_at` for the lazy if-stale trigger) — 19 Jul 2026. Only folded into AI context for `job_type=gsc_article_idea`, via `GrowthAgentPromptBuilder`. See `docs/GROWTH_AGENT_MEMORY_PLAN.md`. |

Every table that exists in the live database is now accounted for
somewhere in 000-007 — nothing is "unreconstructable from code" anymore.

## Products/Gallery cleanup (13 Jul 2026)

The old Products admin PHP files (`products.php`, `gallery.php`,
`gallery.php.bak`, `product-categories.php`, `product-images.php`,
`product-tags.php`, `actions/products-delete.php`,
`actions/products-store.php`, `migrate-gallery.php`) — flagged as
"not yet confirmed deleted" in earlier project notes — were checked and
are **already gone** from the server. Nothing left to delete there.

On the database side, `products` / `product_categories` / `product_images`
/ `product_tags` / `product_tag_map` were confirmed (via full-codebase
grep) to have zero live references anywhere, and hold only leftover dummy
seed rows (1 row each in `products`/`product_categories`, nothing in the
other three). `008_remove_products.sql` drops all five — run it whenever
you're ready (see its header for the backup command first).

`gallery` was **not** dropped, even though the original cleanup note
grouped it with "produk/gallery lama" — it's a general-purpose image
gallery table (own title/description/category columns), not exclusively
tied to products, currently empty, and plausibly useful again later for a
news site (event/sports photo galleries). It's kept in
`000_base_schema.sql`. If you want it gone too, `008`'s header has a
commented-out `DROP TABLE` for it.

**Update (Fase 11 verification pass, 13 Jul 2026):** `cms-admin/data/sample-data.php`
+ `cms_sample_data()` in `cms-admin/includes/functions.php` — the dead
pre-pivot placeholder data mentioned above — have now been removed
(zero call sites confirmed, safe deletion). Also removed as part of the
same pass: `cms-admin/pages/media-library.php.bak` (stale backup file),
`cms-admin/migrate-media-library.php` and `cms-admin/migrate-ai-management.php`
(one-off runner scripts, both self-documented as "delete after running" —
their migrations are already formally captured in `001_media_library_add_columns.sql`
and `002_ai_management.sql`, so nothing is lost). Testimonials was also
removed from the admin global search results (`cms-admin/actions/search.php`)
for consistency with its sidebar removal — the page and its data are still
untouched, just no longer surfaced anywhere in the admin UI.

## Known discrepancy (flagging, not fixed)

The migration-001 columns/index discrepancy below is now historical —
the one-off PHP runner script that caused it has been deleted (see
update above). Still worth knowing in case the index was never actually
applied to the live database:

`cms-admin/migrate-media-library.php` (deleted, formerly the one-off runner
script for migration 001) added `gallery.media_id` but did **not** add the
`idx_gallery_media_id` index that `001_media_library_add_columns.sql`
includes. Confirmed against the live
13 Jul export: the index is indeed missing in production right now — the
`.sql` file is the source of truth, the PHP script's version is what
actually ran. Harmless (no query currently depends on it for correctness,
only lookup speed) but worth adding manually if you notice slow
`gallery`/`media_library` joins at scale:

```sql
ALTER TABLE `gallery` ADD INDEX IF NOT EXISTS `idx_gallery_media_id` (`media_id`);
```
