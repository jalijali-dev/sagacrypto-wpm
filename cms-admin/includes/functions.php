<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

function cms_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Harden session cookie: HttpOnly + SameSite=Lax always,
        // Secure when the request is served over HTTPS.
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Return the current session CSRF token, creating one on first use.
 * One token per session (not rotated per request) so multi-tab admin use
 * keeps working. Token lives in the session only — no schema change.
 */
function cms_csrf_token(): string
{
    cms_session_start();
    if (empty($_SESSION['cms_csrf_token'])) {
        $_SESSION['cms_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cms_csrf_token'];
}

/**
 * Hidden input markup for embedding the CSRF token in a POST form.
 */
function cms_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . cms_esc(cms_csrf_token()) . '">';
}

/**
 * Validate the CSRF token on POST requests. Accepts the token from the
 * csrf_token POST field or the X-CSRF-Token header (for fetch calls).
 * Non-POST requests pass through untouched. On failure: hard 403 + exit.
 */
function cms_verify_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $sent  = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $known = (string) ($_SESSION['cms_csrf_token'] ?? '');

    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        http_response_code(403);
        exit('Invalid or expired form token. Please go back, refresh the page, and try again.');
    }
}

function cms_is_demo_authenticated(): bool
{
    return !empty($_SESSION['cms_demo_auth']);
}

function cms_require_demo_auth(): void
{
    cms_session_start();
    if (!cms_is_demo_authenticated()) {
        header('Location: ' . cms_login_href());
        exit;
    }
}

/**
 * True when the current script lives under cms-admin/pages/.
 */
function cms_is_pages_subdirectory(): bool
{
    $path = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return str_contains($path, DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR);
}

function cms_nav_href(string $pageFilename): string
{
    return cms_is_pages_subdirectory() ? $pageFilename : 'pages/' . $pageFilename;
}

/**
 * Prefix needed to reach cms-admin/pages/*.php from the current script.
 * Used client-side (global search) to turn a bare filename returned by
 * actions/search.php into a link that resolves correctly no matter which
 * admin page the search was triggered from.
 */
function cms_pages_prefix(): string
{
    return cms_is_pages_subdirectory() ? '' : 'pages/';
}

function cms_dashboard_href(): string
{
    return cms_is_pages_subdirectory() ? '../dashboard.php' : 'dashboard.php';
}

function cms_login_href(): string
{
    return cms_is_pages_subdirectory() ? '../login.php' : 'login.php';
}

function cms_logout_href(): string
{
    return cms_is_pages_subdirectory() ? '../logout.php' : 'logout.php';
}

function cms_action_href(string $actionFilename): string
{
    return cms_is_pages_subdirectory() ? '../actions/' . $actionFilename : 'actions/' . $actionFilename;
}

/**
 * ── Admin colour theme (session-backed) ───────────────────────────────
 * The selected theme is stored server-side in $_SESSION so it follows the
 * admin across every page navigation, not just while JS/localStorage is
 * available. cms-admin/actions/theme-update.php writes to this session key.
 */
function cms_valid_themes(): array
{
    return ['deep-purple', 'dark-modern', 'light-modern'];
}

function cms_current_theme(): string
{
    cms_session_start();
    $theme = (string) ($_SESSION['wpm_theme'] ?? '');
    return in_array($theme, cms_valid_themes(), true) ? $theme : 'deep-purple';
}

function cms_settings_href(): string
{
    return cms_nav_href('site-settings.php');
}

function cms_asset_url(string $relativePath): string
{
    // Relative to the current script (not SCRIPT_NAME string-matching), so this
    // works whether cms-admin/ is served as a nested folder (local dev, e.g.
    // domain.test/cms-admin/pages/x.php) OR as the document root of its own
    // (sub)domain (production, e.g. wpm.sagacrypto.com/pages/x.php) — both
    // cases are detected purely from the on-disk script location.
    $relativePath = ltrim($relativePath, '/');
    $prefix = cms_is_pages_subdirectory() ? '../assets/' : 'assets/';
    return $prefix . $relativePath;
}

/**
 * Prefix that reaches the public front-end (project root) from wherever the
 * current admin script lives.
 *
 * - Local dev (cms-admin/ nested under the project root, same host): a plain
 *   relative "../" (or "../../" from pages/) is enough.
 * - Production (wpm.sagacrypto.com's document root pointed straight at
 *   cms-admin/, so the public site is a *different* host entirely — a
 *   relative path can never reach it): build an absolute URL by stripping
 *   the "wpm." prefix off the current host, e.g.
 *   wpm.sagacrypto.com -> sagacrypto.com.
 */
function cms_public_base_prefix(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (str_starts_with($host, 'wpm.')) {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            ? 'https' : 'http';
        return $scheme . '://' . substr($host, 4) . '/';
    }
    return cms_is_pages_subdirectory() ? '../../' : '../';
}

function cms_public_site_url(): string
{
    return cms_public_base_prefix() . 'index.php';
}

function cms_favicon_url(): string
{
    return cms_public_base_prefix() . 'uploads/site/favicon/favicon.png';
}

function cms_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cms_sample_data(): array
{
    static $cache = null;
    if ($cache === null) {
        /** @var array $data */
        $data = require dirname(__DIR__) . '/data/sample-data.php';
        $cache = $data;
    }
    return $cache;
}
