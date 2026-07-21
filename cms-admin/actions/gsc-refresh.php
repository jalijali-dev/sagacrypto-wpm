<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// Same tier as pages/growth-agent.php.
cms_require_role(['superadmin', 'admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/growth-agent.php', true, 302);
    exit;
}

$result = cms_gsc_fetch_and_cache($pdo, true);

$_SESSION['cms_flash'] = $result['ok']
    ? ['type' => 'success', 'message' => 'GSC data refreshed — ' . $result['rows_written'] . ' rows written.']
    : ['type' => 'error', 'message' => 'GSC refresh failed: ' . $result['error']];

header('Location: ../pages/growth-agent.php', true, 302);
exit;
