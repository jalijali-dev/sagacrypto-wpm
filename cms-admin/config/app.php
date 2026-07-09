<?php
declare(strict_types=1);

/**
 * cms-admin/config/app.php — self-contained CMS configuration.
 *
 * This is the single config file for WPM admin panel.
 * It intentionally contains everything cms-admin needs so the panel can be
 * deployed by copying the cms-admin directory into any project root.
 *
 * Nothing inside cms-admin should require /config/app.php (project root).
 */

// ─── CMS identity ─────────────────────────────────────────────────────────────
define('CMS_ADMIN_NAME',    'WPM');
define('CMS_ADMIN_TAGLINE', 'Admin panel');
define('CMS_DEMO_NOTICE',   'Authorized administrators only.');

// ─── Project root filesystem path ─────────────────────────────────────────────
// cms-admin/config/ → dirname(__DIR__)   = cms-admin/
//                  → dirname(__DIR__, 2) = project root
// This single dirname() call is the only reference to the parent directory;
// it is unavoidable because uploads live outside cms-admin by design.
if (!defined('CMS_PROJECT_ROOT')) {
    define('CMS_PROJECT_ROOT', dirname(__DIR__, 2));
}

// ─── Base URL ─────────────────────────────────────────────────────────────────
// Auto-derives the URL to the project root from the server environment.
// Works for HTTP and HTTPS, any host, any subfolder under the web root.
if (!defined('BASE_URL')) {
    $_aw_scheme  = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        ? 'https' : 'http';
    $_aw_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_aw_docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $_aw_self    = rtrim(str_replace('\\', '/', CMS_PROJECT_ROOT), '/');

    $_aw_relPath = ($_aw_docRoot !== '' && str_starts_with($_aw_self, $_aw_docRoot))
        ? substr($_aw_self, strlen($_aw_docRoot))
        : '';

    define('BASE_URL', $_aw_scheme . '://' . $_aw_host . '/' . ltrim($_aw_relPath, '/') . '/');

    unset($_aw_scheme, $_aw_host, $_aw_docRoot, $_aw_self, $_aw_relPath);
}

// ─── Asset preview URL ────────────────────────────────────────────────────────
if (!function_exists('app_asset_preview_url')) {
    /**
     * Convert a stored asset path to a browser-accessible URL.
     *
     * - Empty string      → '' (no preview)
     * - Already https?:// → returned as-is
     * - Relative or /uploads/... path → BASE_URL + path (leading slash stripped)
     */
    function app_asset_preview_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return BASE_URL . ltrim($path, '/');
    }
}

// ─── Safe media disk path ─────────────────────────────────────────────────────
if (!function_exists('app_safe_media_disk_path')) {
    /**
     * Resolve a stored web path to an absolute filesystem path.
     *
     * Guards against directory traversal: returns null unless the resolved path
     * is inside the project uploads/ directory and the file actually exists.
     *
     * @param string $webPath     Stored path, e.g. /uploads/media/2026/05/photo.jpg
     * @param string $projectRoot Absolute filesystem path to project root (no trailing slash).
     */
    function app_safe_media_disk_path(string $webPath, string $projectRoot): ?string
    {
        $rel = ltrim($webPath, '/');
        if ($rel === '' || !str_starts_with($rel, 'uploads/')) {
            return null;
        }
        $diskPath = realpath($projectRoot . '/' . $rel);
        if ($diskPath === false) {
            return null;
        }
        $uploadsRoot = realpath($projectRoot . '/uploads');
        if ($uploadsRoot === false) {
            return null;
        }
        // Ensure resolved path stays inside uploads/ (traversal guard).
        if (!str_starts_with($diskPath, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $diskPath;
    }
}
