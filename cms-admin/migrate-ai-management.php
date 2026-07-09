<?php
/**
 * One-time migration: create ai_credentials, ai_models, ai_agent_settings tables
 * and seed default models (Claude 3.5 Haiku, GPT-4o mini) + agent rows.
 * Run via browser: http://localhost:8008/wpm/cms-admin/migrate-ai-management.php
 * DELETE THIS FILE after running.
 */
declare(strict_types=1);

// Protection: require an authenticated admin session (same check used by
// every other cms-admin page). Replaces the old REMOTE_ADDR === 127.0.0.1
// check, which reliably blocks legitimate access when the app runs behind
// Docker/a reverse proxy (PHP sees the proxy/gateway IP, not 127.0.0.1).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$results = [];

try {
    $sql = file_get_contents(__DIR__ . '/migrations/002_ai_management.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read migrations/002_ai_management.sql');
    }

    // Strip comments, split on ';', run each non-empty statement.
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $cols = [];
    foreach ($statements as $stmt) {
        if ($stmt === '') {
            continue;
        }
        $isShow = stripos($stmt, 'SHOW COLUMNS') === 0;
        if ($isShow) {
            $r = $pdo->query($stmt);
            $cols[] = $r->fetchAll(PDO::FETCH_ASSOC);
            $results[] = 'OK    ' . substr($stmt, 0, 60);
            continue;
        }
        $pdo->exec($stmt);
        $results[] = 'OK    ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
    }

    $status = 'success';
} catch (Throwable $e) {
    $status = 'error';
    $results[] = 'ERROR: ' . $e->getMessage();
    $cols = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration: AI Management</title>
<style>
body{font-family:monospace;max-width:800px;margin:40px auto;padding:0 20px}
pre{background:#f4f4f4;padding:16px;border-radius:6px;overflow-x:auto;white-space:pre-wrap}
.ok{color:green}.skip{color:#888}.err{color:red}
h2{margin-top:32px}
.banner{padding:12px 16px;border-radius:6px;font-weight:bold;margin-bottom:20px}
.banner.success{background:#d4edda;color:#155724}
.banner.error{background:#f8d7da;color:#721c24}
</style>
</head>
<body>
<h1>Migration: AI Management tables</h1>
<div class="banner <?= htmlspecialchars($status) ?>">
    <?= $status === 'success' ? '✅ Migration completed successfully.' : '❌ Migration failed — see error below.' ?>
</div>

<h2>Steps</h2>
<pre>
<?php foreach ($results as $line) :
    $cls = str_starts_with($line, 'OK') ? 'ok' : (str_starts_with($line, 'SKIP') ? 'skip' : 'err');
?>
<span class="<?= $cls ?>"><?= htmlspecialchars($line) ?></span>
<?php endforeach; ?>
</pre>

<h2>Next steps</h2>
<p>
  1. Verify all rows above show <span class="ok">OK</span> (no <span class="err">ERROR</span>).<br>
  2. Open <a href="pages/ai-credentials.php">AI Credentials</a>, add your OpenAI/Anthropic API keys.<br>
  3. Open <a href="pages/ai-models.php">AI Models</a> and <a href="pages/ai-agent-settings.php">AI Agent Settings</a> to confirm defaults.<br>
  4. Try <a href="pages/ai-sandbox.php">AI Sandbox</a> to send a test prompt.<br>
  5. <strong>Delete this file:</strong> <code>cms-admin/migrate-ai-management.php</code>
</p>
</body>
</html>
