<?php
/**
 * One-time migration: add missing columns to media_library table.
 * Run via browser: http://localhost:8008/wpm/cms-admin/migrate-media-library.php
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

/**
 * Add a column only if it does not already exist.
 */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): string
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = :table
           AND COLUMN_NAME  = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return "SKIP  [{$table}.{$column}] already exists.";
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    return "OK    [{$table}.{$column}] added.";
}

try {
    // ── media_library ──────────────────────────────────────────────────────────
    $results[] = add_column_if_missing($pdo, 'media_library', 'mime_type',
        "VARCHAR(100) DEFAULT NULL AFTER `file_type`");

    $results[] = add_column_if_missing($pdo, 'media_library', 'file_size_kb',
        "INT(10) UNSIGNED DEFAULT NULL AFTER `mime_type`");

    $results[] = add_column_if_missing($pdo, 'media_library', 'is_active',
        "TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size_kb`");

    $results[] = add_column_if_missing($pdo, 'media_library', 'updated_at',
        "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

    // ── gallery.media_id (used in media-library JOIN) ─────────────────────────
    $results[] = add_column_if_missing($pdo, 'gallery', 'media_id',
        "INT(10) UNSIGNED DEFAULT NULL AFTER `id`");

    // ── Final SHOW COLUMNS for confirmation ───────────────────────────────────
    $colStmt = $pdo->query("SHOW COLUMNS FROM `media_library`");
    $cols    = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    $status = 'success';
} catch (Throwable $e) {
    $status   = 'error';
    $results[] = 'ERROR: ' . $e->getMessage();
    $cols      = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration: media_library</title>
<style>
body{font-family:monospace;max-width:800px;margin:40px auto;padding:0 20px}
pre{background:#f4f4f4;padding:16px;border-radius:6px;overflow-x:auto}
.ok{color:green}.skip{color:#888}.err{color:red}
h2{margin-top:32px}
.banner{padding:12px 16px;border-radius:6px;font-weight:bold;margin-bottom:20px}
.banner.success{background:#d4edda;color:#155724}
.banner.error{background:#f8d7da;color:#721c24}
</style>
</head>
<body>
<h1>Migration: media_library columns</h1>
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

<?php if ($cols) : ?>
<h2>Current media_library columns</h2>
<pre>
<?php foreach ($cols as $c) :
    echo htmlspecialchars(str_pad($c['Field'], 20) . '  ' . str_pad($c['Type'], 30) . '  NULL=' . $c['Null']) . "\n";
endforeach; ?>
</pre>
<?php endif; ?>

<h2>Next steps</h2>
<p>
  1. Verify all rows above show <span class="ok">OK</span> or <span class="skip">SKIP</span>.<br>
  2. Open <a href="pages/media-library.php">Media Library</a> and confirm no errors.<br>
  3. <strong>Delete this file:</strong> <code>cms-admin/migrate-media-library.php</code>
</p>
</body>
</html>
