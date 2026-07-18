-- ============================================================
-- Migration 008: Remove the old Products module (Fase 1 cleanup).
--
-- ⚠️  DESTRUCTIVE — this permanently DROPS 5 tables and any data in them.
-- Back up first if you're unsure: mysqldump -u <user> -p <database>
-- products product_categories product_images product_tags product_tag_map
-- > products_backup_before_008.sql
--
-- Context: Products/Product Categories/Product Tags/Product Images/
-- Gallery menus were removed from the admin sidebar back in Fase 1 (see
-- SITEMAP.md), but the underlying PHP files and database tables were left
-- in place at the time. The PHP files (products.php, gallery.php,
-- product-categories.php, product-images.php, product-tags.php,
-- actions/products-delete.php, actions/products-store.php,
-- migrate-gallery.php) have since been confirmed already removed from the
-- server — nothing to delete there anymore.
--
-- This migration finishes the job on the DATABASE side: dropping the 5
-- product-related tables, confirmed via full codebase grep to have ZERO
-- live references anywhere (no query, no include, nothing touches
-- `products`/`product_categories`/`product_images`/`product_tags`/
-- `product_tag_map` in any currently-active file) and holding only
-- leftover dummy/seed rows (1 row each in `products` /
-- `product_categories`, nothing in the other three, as of the 13 Jul 2026
-- export).
--
-- `gallery` is intentionally NOT dropped here even though it was grouped
-- with "produk/gallery lama" in the original request — unlike the
-- products cluster, `gallery` is a general-purpose image gallery (its own
-- title/description/category/image_path columns), not exclusively a
-- product-photos table, and it's plausible a news site wants a photo
-- gallery again later (event/sports photos, etc). It's empty in
-- production right now, so dropping it later costs nothing if you decide
-- you don't want it — see cms-admin/migrations/000_base_schema.sql where
-- it's kept. Uncomment the DROP at the bottom of this file if you want it
-- gone too.
--
--   mysql -u <user> -p <database> < 008_remove_products.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Children first (both have FKs into products/product_tags)
DROP TABLE IF EXISTS `product_tag_map`;
DROP TABLE IF EXISTS `product_images`;

-- Then the parents
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `product_tags`;
DROP TABLE IF EXISTS `product_categories`;

SET FOREIGN_KEY_CHECKS = 1;

-- Uncomment if you also want to drop the (currently empty, unreferenced)
-- gallery table — see note above before doing this:
-- DROP TABLE IF EXISTS `gallery`;

-- Verify result — none of these five should appear anymore
SHOW TABLES LIKE 'product%';
