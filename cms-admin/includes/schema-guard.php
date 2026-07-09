<?php
declare(strict_types=1);

/**
 * Lightweight, idempotent "add column if missing" helper shared by any
 * admin page that needs to self-heal its own schema on load (Gallery,
 * Media Library, etc.) instead of showing a Fatal Error or a raw PDO
 * exception to the admin.
 *
 * Safe to call on every page load: it checks INFORMATION_SCHEMA.COLUMNS
 * first and only runs ALTER TABLE when the column truly doesn't exist yet,
 * so repeat calls never duplicate a column and never fail on a re-run.
 */
if (!function_exists('cms_ensure_column')) {
    function cms_ensure_column(PDO $pdo, string $table, string $column, string $definition): bool
    {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :table
               AND COLUMN_NAME  = :column'
        );
        $check->execute(['table' => $table, 'column' => $column]);
        if ((int) $check->fetchColumn() > 0) {
            return false; // already existed — nothing to do
        }

        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");

        return true; // column just added
    }
}
