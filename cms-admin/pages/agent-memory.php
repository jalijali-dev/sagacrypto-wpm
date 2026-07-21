<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// Same tier as Growth Agent — see cms_require_role() in functions.php for
// the full tier breakdown. Split out into its own page (was a panel
// inside pages/growth-agent.php) purely for navigation/screen-space
// reasons — role/logic is unchanged, see docs/GROWTH_AGENT_MEMORY_PLAN.md.
cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);

// Lazy Agent Memory pattern-detection — moved here from growth-agent.php
// now that this feature has its own page. Much longer default interval
// than the GSC fetch on growth-agent.php
// (memory_thresholds_json.detection_interval_days, default 7 days): "is
// this pattern still consistent" changes slowly, so re-running the
// full-window analysis every page load would be wasted work. No-op if
// GSC isn't connected yet. See docs/GROWTH_AGENT_MEMORY_PLAN.md.
cms_growth_agent_detect_memory_if_stale($pdo);

$pageTitle = 'Agent Memory';
$currentNav = 'agent-memory';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Agent Memory', 'href' => ''],
];

$selfUrl = 'agent-memory.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // Manual "Analisis Pola" — same detection pass as the lazy trigger
    // above, just on-demand (also runs the retention sweep in the same
    // call, see cms_growth_agent_detect_memory_patterns()). Logic
    // unchanged from when this lived on growth-agent.php.
    if ($action === 'analyze_memory_patterns') {
        $memStats = cms_growth_agent_detect_memory_patterns($pdo);
        $totalNew = $memStats['winning_pattern'] + $memStats['content_gap'];
        if ($totalNew > 0) {
            $redirect(
                $totalNew . ' pola baru terdeteksi (' . $memStats['winning_pattern'] . ' winning pattern, ' .
                $memStats['content_gap'] . ' content gap) — cek "Pending Review" di bawah.' .
                ($memStats['archived'] > 0 ? ' ' . $memStats['archived'] . ' entry lama di-archive otomatis.' : '')
            );
        }
        $redirect('Tidak ada pola baru terdeteksi.' .
            ($memStats['archived'] > 0 ? ' ' . $memStats['archived'] . ' entry lama di-archive otomatis.' : ''));
    }

    if ($action === 'approve_memory' || $action === 'reject_memory' || $action === 'archive_memory') {
        $memId = (int) ($_POST['memory_id'] ?? 0);
        if ($memId <= 0) {
            $redirect('Entry memory tidak valid.', 'error');
        }
        $reviewerId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

        $memOk = match ($action) {
            'approve_memory' => cms_growth_agent_memory_approve($pdo, $memId, $reviewerId),
            'reject_memory'  => cms_growth_agent_memory_reject($pdo, $memId, $reviewerId),
            'archive_memory' => cms_growth_agent_memory_archive($pdo, $memId, $reviewerId),
        };

        $memMessage = match ($action) {
            'approve_memory' => 'Entry memory diaktifkan — mulai sekarang ikut jadi konteks generate ide artikel baru.',
            'reject_memory'  => 'Entry memory ditolak — tidak akan muncul lagi kecuali polanya benar-benar hilang lalu terdeteksi ulang.',
            'archive_memory' => 'Entry memory di-archive.',
        };

        $redirect($memOk ? $memMessage : 'Entry sudah bukan di status yang diharapkan (mungkin sudah diproses sebelumnya).', $memOk ? 'success' : 'error');
    }

    $redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$memoryPending = $pdo->query(
    "SELECT id, insight_type, title, description, supporting_data_json, detected_at
       FROM growth_agent_memory
      WHERE status = 'pending_review'
      ORDER BY detected_at DESC"
)->fetchAll();
$memoryActive = $pdo->query(
    "SELECT id, insight_type, title, description, last_confirmed_at
       FROM growth_agent_memory
      WHERE status = 'active'
      ORDER BY last_confirmed_at DESC"
)->fetchAll();
$memoryInsightLabel = ['winning_pattern' => 'Winning Pattern', 'content_gap' => 'Content Gap'];
$memoryInsightPill = ['winning_pattern' => 'ok', 'content_gap' => 'info'];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Agent Memory</h2>
            <p class="section-lead">
                Pola/insight dari data GSC historis (winning pattern &amp; content gap yang konsisten dari waktu ke
                waktu — bukan snapshot sekali fetch). Cuma dipakai sebagai konteks tambahan saat generate <strong>ide
                artikel baru</strong> — tidak dipakai untuk revisi artikel existing. Setiap entry wajib direview
                sebelum aktif.
            </p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_nav_href('growth-agent.php')) ?>">&larr; Growth Agent</a>
            <form method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="analyze_memory_patterns">
                <button type="submit" class="admin-btn admin-btn--primary">Analisis Pola</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Pending Review</h3>
            <span class="panel__meta"><?= count($memoryPending) ?> item(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Type</th><th>Title</th><th>Evidence</th><th>Detected</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ($memoryPending === []) : ?>
                        <tr><td colspan="5" class="muted">Tidak ada draft menunggu review — klik "Analisis Pola" untuk cari pola baru dari data GSC.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($memoryPending as $mem) : ?>
                        <?php $memMetrics = json_decode((string) $mem['supporting_data_json'], true) ?: []; ?>
                        <tr>
                            <td><span class="pill pill--<?= $memoryInsightPill[$mem['insight_type']] ?? 'muted' ?>"><?= cms_esc($memoryInsightLabel[$mem['insight_type']] ?? $mem['insight_type']) ?></span></td>
                            <td><?= cms_esc((string) $mem['title']) ?></td>
                            <td class="muted" style="font-size:12px;max-width:320px;">
                                <?= cms_esc((string) $mem['description']) ?>
                                <?php if ($memMetrics !== []) : ?>
                                    <div style="margin-top:4px;">
                                        <?= (int) ($memMetrics['distinct_weeks'] ?? 0) ?> minggu &middot;
                                        <?= number_format((int) ($memMetrics['total_impressions'] ?? 0)) ?> impressions
                                        <?php if (isset($memMetrics['avg_ctr'])) : ?>
                                            &middot; CTR <?= cms_esc((string) round(((float) $memMetrics['avg_ctr']) * 100, 2)) ?>%
                                        <?php endif; ?>
                                        &middot; posisi <?= cms_esc((string) ($memMetrics['avg_position'] ?? '—')) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= cms_esc((string) $mem['detected_at']) ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_memory">
                                    <input type="hidden" name="memory_id" value="<?= (int) $mem['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Approve</button>
                                </form>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_memory">
                                    <input type="hidden" name="memory_id" value="<?= (int) $mem['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Active</h3>
            <span class="panel__meta"><?= count($memoryActive) ?> item(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Type</th><th>Title</th><th>Last confirmed</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ($memoryActive === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada entry aktif — approve dari Pending Review di atas dulu.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($memoryActive as $mem) : ?>
                        <tr>
                            <td><span class="pill pill--<?= $memoryInsightPill[$mem['insight_type']] ?? 'muted' ?>"><?= cms_esc($memoryInsightLabel[$mem['insight_type']] ?? $mem['insight_type']) ?></span></td>
                            <td><?= cms_esc((string) $mem['title']) ?></td>
                            <td class="muted"><?= cms_esc((string) $mem['last_confirmed_at']) ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Nonaktifkan entry memory ini? Tidak akan ikut jadi konteks generate lagi.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="archive_memory">
                                    <input type="hidden" name="memory_id" value="<?= (int) $mem['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Archive</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
