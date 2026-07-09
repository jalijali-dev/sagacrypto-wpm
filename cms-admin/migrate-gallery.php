<?php
/**
 * One-time migration: add missing columns to gallery table.
 * Run via browser: http://localhost:8008/wpm/cms-admin/migrate-gallery.php
 * DELETE THIS FILE after running successfully.
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
function gl_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): string
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
    // ── gallery: kolom yang dibutuhkan gallery.php ─────────────────────────────

    // media_id – FK opsional ke media_library
    $results[] = gl_add_column_if_missing($pdo, 'gallery', 'media_id',
        "INT(10) UNSIGNED DEFAULT NULL AFTER `id`");

    // description – deskripsi panjang item gallery
    $results[] = gl_add_column_if_missing($pdo, 'gallery', 'description',
        "TEXT DEFAULT NULL AFTER `title`");

    // category – label kategori bebas (autocomplete di form)
    $results[] = gl_add_column_if_missing($pdo, 'gallery', 'category',
        "VARCHAR(100) DEFAULT NULL AFTER `description`");

    // is_featured – tandai item unggulan
    $results[] = gl_add_column_if_missing($pdo, 'gallery', 'is_featured',
        "TINYINT(1) NOT NULL DEFAULT 0 AFTER `sort_order`");

    // status – status publikasi item
    $results[] = gl_add_column_if_missing($pdo, 'gallery', 'status',
        "VARCHAR(20) NOT NULL DEFAULT 'published' AFTER `is_featured`");

    // ── Migrasi data lama: is_active → status ─────────────────────────────────
    // Jika kolom is_active ada dan status baru ditambahkan, sinkronkan nilainya.
    $chk = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'gallery'
           AND COLUMN_NAME  = 'is_active'"
    );
    if ((int) $chk->fetchColumn() > 0) {
        $pdo->exec(
            "UPDATE `gallery`
             SET `status` = CASE WHEN `is_active` = 1 THEN 'published' ELSE 'draft' END
             WHERE `status` = 'published' AND `is_active` = 0"
        );
        $results[] = "OK    [gallery] Data is_active → status disinkronkan.";
    }

    // ── Konfirmasi kolom gallery saat ini ──────────────────────────────────────
    $colStmt = $pdo->query("SHOW COLUMNS FROM `gallery`");
    $cols    = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    $status = 'success';
} catch (Throwable $e) {
    $status    = 'error';
    $results[] = 'ERROR: ' . $e->getMessage();
    $cols      = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration: gallery columns</title>
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
<h1>Migration: gallery columns</h1>
<div class="banner <?= htmlspecialchars($status) ?>">
    <?= $status === 'success' ? '✅ Migration selesai.' : '❌ Migration gagal — lihat error di bawah.' ?>
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
<h2>Kolom gallery saat ini</h2>
<pre>
<?php foreach ($cols as $c) :
    echo htmlspecialchars(str_pad($c['Field'], 20) . '  ' . str_pad($c['Type'], 30) . '  NULL=' . $c['Null']) . "\n";
endforeach; ?>
</pre>
<?php endif; ?>

<h2>Langkah selanjutnya</h2>
<p>
  1. Pastikan semua baris di atas menampilkan <span class="ok">OK</span> atau <span class="skip">SKIP</span>.<br>
  2. Buka <a href="pages/gallery.php">Gallery</a> dan pastikan tidak ada error.<br>
  3. <strong>Hapus file ini:</strong> <code>cms-admin/migrate-gallery.php</code>
</p>
</body>
</html>
