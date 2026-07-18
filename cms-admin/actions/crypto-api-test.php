<?php
declare(strict_types=1);

/**
 * Admin-only AJAX endpoint for the "Test Connection" button on
 * cms-admin/pages/crypto-api.php. Performs a live request using the
 * (possibly unsaved) form values and reports back without touching the
 * cache table, then records the outcome on crypto_api_settings so the
 * settings page can show "last tested" info on reload.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/crypto-api.php';

header('Content-Type: application/json');

// AJAX endpoint for pages/crypto-api.php (superadmin-only, holds raw API
// keys) — respond with a JSON error instead of cms_require_role()'s normal
// redirect, since this is fetch()-called, not a page navigation.
if (!cms_is_superadmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

try {
    cms_crypto_ensure_schema($pdo);

    $override = [];
    if (($_POST['provider'] ?? '') !== '') {
        $override['provider'] = trim((string) $_POST['provider']);
    }
    if (($_POST['base_url'] ?? '') !== '') {
        $override['base_url'] = trim((string) $_POST['base_url']);
    }
    if (($_POST['endpoint'] ?? '') !== '') {
        $override['endpoint'] = trim((string) $_POST['endpoint']);
    }
    if (($_POST['api_key_header'] ?? '') !== '') {
        $override['api_key_header'] = trim((string) $_POST['api_key_header']);
    }
    if (($_POST['default_currency'] ?? '') !== '') {
        $override['default_currency'] = trim((string) $_POST['default_currency']);
    }
    if (($_POST['coins_limit'] ?? '') !== '') {
        $override['coins_limit'] = max(1, min(250, (int) $_POST['coins_limit']));
    }
    // Only use a freshly-typed API key for the test; otherwise fall back to
    // the already-saved key so "Test Connection" works without retyping it.
    if (trim((string) ($_POST['api_key'] ?? '')) !== '') {
        $override['api_key'] = trim((string) $_POST['api_key']);
    }

    $result = cms_crypto_test_connection($pdo, $override);

    $pdo->prepare(
        'UPDATE crypto_api_settings
         SET last_test_status = :status, last_test_message = :message, last_test_at = NOW()
         ORDER BY id ASC LIMIT 1'
    )->execute([
        'status'  => $result['ok'] ? 'success' : 'failed',
        'message' => mb_substr($result['message'], 0, 255),
    ]);

    echo json_encode(['ok' => $result['ok'], 'message' => $result['message'], 'sample' => $result['sample']]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'message' => 'Unexpected error: ' . $e->getMessage(), 'sample' => []]);
}
