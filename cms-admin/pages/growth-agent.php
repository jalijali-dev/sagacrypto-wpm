<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// Same tier as the rest of AI Management — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);

// Lazy auto-cleanup — no cron in this codebase (see sitemap-service.php's
// own note on that), so this runs quietly on every page load instead,
// same "self-maintaining on request" spirit as cms_ensure_table(). Only
// removes already-resolved jobs (failed, or succeeded-and-never-approved)
// older than 90 days; see cms_growth_agent_cleanup_old_jobs() for exactly
// what's protected from deletion. A manual "Bersihkan job lama" button
// further down runs the same function on demand with a chosen window.
cms_growth_agent_cleanup_old_jobs($pdo, 90);

// Lazy GSC fetch — no cron in this codebase (§ 0.1 of the integration
// plan, docs/GSC_INTEGRATION_PLAN.md — an explicit decision, not the
// project default). Re-fetches only if GSC is connected AND the last
// fetch is more than 24h old; a no-op otherwise. Never throws.
cms_gsc_fetch_if_stale($pdo, 24);

$pageTitle = 'Growth Agent';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => ''],
];

$selfUrl = 'growth-agent.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    // ── Job review — feeds the Fase 3 few-shot pool ──────────────────────
    // Approving a job as-is is what makes it eligible as a future
    // GrowthAgentPromptBuilder example (see services/GrowthAgentPromptBuilder.php).
    if ($action === 'approve' || $action === 'reject') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            $redirect('Invalid job.', 'error');
        }

        $feedbackAction = $action === 'approve' ? 'approved_as_is' : 'rejected';
        $newStatus = $action === 'approve' ? 'succeeded' : 'failed';

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => $feedbackAction, 'reviewed_by' => $currentAdminId]);

        $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, updated_at = NOW() WHERE id = :id');
        $upd->execute(['status' => $newStatus, 'id' => $jobId]);

        $redirect($action === 'approve' ? 'Job approved — it may now be used as a future example.' : 'Job rejected.');
    }

    if ($action === 'style_rule_create') {
        $ruleText = trim((string) ($_POST['rule_text'] ?? ''));
        if ($ruleText === '') {
            $redirect('Style rule text is required.', 'error');
        }
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_style_rules (rule_text, source, is_active, created_by, created_at)
             VALUES (:rule_text, :source, 1, :created_by, NOW())'
        );
        $ins->execute(['rule_text' => $ruleText, 'source' => 'manual', 'created_by' => $currentAdminId]);
        $redirect('Style rule added.');
    }

    if ($action === 'style_rule_toggle') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Invalid rule.', 'error');
        }
        $pdo->prepare('UPDATE growth_agent_style_rules SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $ruleId]);
        $redirect('Style rule updated.');
    }

    if ($action === 'style_rule_delete') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Invalid rule.', 'error');
        }
        $pdo->prepare('DELETE FROM growth_agent_style_rules WHERE id = :id')->execute(['id' => $ruleId]);
        $redirect('Style rule removed.');
    }

    // ── "Apply SEO Recommendation" — manual scan trigger ─────────────────
    // Deliberately manual (a button click), not scheduled/automatic — see
    // the approved flow: Scan -> Resolve Target -> SEO child action ->
    // Review & Apply. Each scanned article becomes one manual_action job,
    // reviewed on seo-recommendation-review.php (not the generic
    // Approve/Reject buttons below), since "apply" here writes directly
    // into pages.meta_title / pages.meta_description.
    if ($action === 'scan_seo') {
        $scanStats = cms_growth_agent_scan_seo_recommendations($pdo, 5);
        if ($scanStats['created'] > 0) {
            $redirect($scanStats['created'] . ' rekomendasi SEO baru dibuat dari ' . $scanStats['scanned'] . ' artikel yang di-scan. Cek di tabel "Recent jobs" di bawah.');
        }
        if ($scanStats['scanned'] === 0) {
            $redirect('Tidak ada artikel baru untuk di-scan — semua artikel published sudah pernah di-scan.', 'error');
        }
        $redirect('Scan selesai (' . $scanStats['scanned'] . ' artikel) tapi tidak ada rekomendasi yang berhasil dibuat. Coba lagi nanti.', 'error');
    }

    // ── Recompute Prioritized Opportunities on demand — same pure SQL/
    // scoring pass that already runs automatically after every GSC fetch
    // (cms_gsc_fetch_and_cache() -> cms_gsc_compute_opportunities()); this
    // button exists for refreshing scores without waiting for the next
    // fetch (e.g. after tuning thresholds). See
    // docs/GSC_OPPORTUNITIES_REVISION.md.
    if ($action === 'recompute_opportunities') {
        $result = cms_gsc_compute_opportunities($pdo);
        $redirect($result['ok']
            ? $result['count'] . ' opportunity dihitung ulang.'
            : 'Gagal recompute: ' . $result['error'], $result['ok'] ? 'success' : 'error');
    }

    // ── Generate on-demand from one Prioritized Opportunities row ────────
    // The opportunity table itself is pure scoring (no AI, computed by
    // cms_gsc_compute_opportunities()) — AI is only ever called here, for
    // the ONE row the operator picked. Dispatches by recommended_action,
    // reusing the exact same generate engines as before (single-item
    // calls, not bulk). See docs/GSC_OPPORTUNITIES_REVISION.md § 5.
    if ($action === 'generate_from_opportunity') {
        $oppId = (int) ($_POST['opportunity_id'] ?? 0);
        if ($oppId <= 0) {
            $redirect('Opportunity tidak valid.', 'error');
        }

        $oppStmt = $pdo->prepare("SELECT * FROM gsc_opportunities WHERE id = :id AND status = 'open' LIMIT 1");
        $oppStmt->execute(['id' => $oppId]);
        $opp = $oppStmt->fetch();
        if (!$opp) {
            $redirect('Opportunity tidak ditemukan atau sudah pernah di-generate.', 'error');
        }

        $metrics = json_decode((string) ($opp['metrics_json'] ?? ''), true);
        $metrics = is_array($metrics) ? $metrics : [];
        $priority = (string) $opp['priority'];
        $jobId = 0;
        $ok = false;
        $genError = 'Aksi tidak dikenal untuk opportunity ini.';

        if ($opp['recommended_action'] === 'seo_recommendation') {
            $pageStmt = $pdo->prepare(
                'SELECT page_id, title, slug, excerpt, content, meta_title, meta_description FROM pages WHERE page_id = :id LIMIT 1'
            );
            $pageStmt->execute(['id' => (int) $opp['matched_page_id']]);
            $page = $pageStmt->fetch();
            if (!$page) {
                $redirect('Artikel sumber tidak ditemukan — mungkin sudah dihapus.', 'error');
            }
            $result = cms_growth_agent_run_seo_recommendation_scan($pdo, [$page], [(int) $page['page_id'] => $priority]);
            $ok = $result['created'] > 0;
            $jobId = (int) ($result['job_ids'][0] ?? 0);
            $genError = $ok ? '' : 'AI request gagal atau hasil tidak dalam format yang diharapkan.';
        } elseif ($opp['recommended_action'] === 'gsc_content_optimization') {
            $pageStmt = $pdo->prepare('SELECT page_id, title, slug, excerpt, content FROM pages WHERE page_id = :id LIMIT 1');
            $pageStmt->execute(['id' => (int) $opp['matched_page_id']]);
            $page = $pageStmt->fetch();
            if (!$page) {
                $redirect('Artikel sumber tidak ditemukan — mungkin sudah dihapus.', 'error');
            }
            $page['avg_position'] = $metrics['position'] ?? 0;
            $page['impressions'] = $metrics['impressions'] ?? 0;
            $page['top_queries'] = $metrics['top_queries'] ?? '';
            $result = cms_growth_agent_generate_content_optimization($pdo, $page, $priority);
            $ok = $result['ok'];
            $jobId = $result['job_id'];
            $genError = $result['error'];
        } elseif ($opp['recommended_action'] === 'gsc_article_idea') {
            $queryData = [
                'query' => (string) $opp['query_text'],
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'avg_position' => (float) ($metrics['position'] ?? 0),
            ];
            $result = cms_growth_agent_generate_article_idea($pdo, $queryData, $priority);
            $ok = $result['ok'];
            $jobId = $result['job_id'];
            $genError = $result['error'];
        }

        if ($jobId > 0) {
            $pdo->prepare("UPDATE gsc_opportunities SET status = 'actioned', linked_job_id = :job_id WHERE id = :id")
                ->execute(['job_id' => $jobId, 'id' => $oppId]);
        }

        $redirect($ok
            ? 'Rekomendasi berhasil digenerate — cek tabel "Recent jobs" di bawah untuk review.'
            : 'Generate gagal: ' . $genError, $ok ? 'success' : 'error');
    }

    // ── Manual cleanup — same rules as the lazy auto-cleanup above, just
    // on-demand with a chosen retention window. Never removes 'ready',
    // 'running', or 'manual_action' jobs, and never removes a 'succeeded'
    // job a human approved as-is (the Fase 3 few-shot pool).
    if ($action === 'cleanup_jobs') {
        $days = (int) ($_POST['days'] ?? 90);
        $deleted = cms_growth_agent_cleanup_old_jobs($pdo, $days);
        $redirect($deleted > 0
            ? $deleted . ' job lama (lebih dari ' . max(7, min(365, $days)) . ' hari) berhasil dihapus.'
            : 'Tidak ada job yang perlu dibersihkan untuk jendela waktu itu.');
    }

    $redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

// ── Stats ─────────────────────────────────────────────────────────────
// NOTE: this connection is opened with PDO::ATTR_DEFAULT_FETCH_MODE =>
// PDO::FETCH_ASSOC (see config/database.php) — a plain fetch() here never
// has a numeric index [0], so the previous "$row[0] ?? 0" always silently
// fell through to 0 regardless of the real count. Fixed by aliasing the
// column and reading it by name.
$safeCount = static function (PDO $pdo, string $sql): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row['cnt'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
};

$statsCards = [
    ['label' => 'Approved / Ready', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'ready'"), 'hint' => 'Awaiting execute'],
    ['label' => 'Running', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'running'"), 'hint' => 'In progress'],
    ['label' => 'Succeeded', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'succeeded'"), 'hint' => 'Draft created'],
    ['label' => 'Failed', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'failed'"), 'hint' => 'Retryable'],
    ['label' => 'Manual Actions', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'manual_action'"), 'hint' => 'Operator execution required'],
];

/**
 * Human-readable preview of a job's output_json, shaped per job_type
 * (each generate endpoint produces a different output shape — see
 * cms-admin/api/article-generate.php, faq-generate.php, seo-generate.php,
 * and cms_growth_agent_generate_content_optimization()/
 * cms_growth_agent_generate_article_idea() in growth-agent-service.php).
 * Returns already-escaped HTML — every value is run through cms_esc()
 * before being placed. Used in the "Recent jobs" table so an admin can
 * see what's actually being approved, not just click Approve blind.
 */
function cms_growth_agent_format_job_preview(string $jobType, ?string $outputJson): string
{
    if ($outputJson === null || trim($outputJson) === '') {
        return '<span class="muted">Belum ada output (job belum selesai atau gagal — lihat pesan error di kolom Status).</span>';
    }

    $data = json_decode($outputJson, true);
    if (!is_array($data)) {
        return '<span class="muted">Output tidak bisa dibaca (JSON tidak valid).</span>';
    }

    $field = static function (string $label, string $value, string $marginTop = '0') {
        return $value !== ''
            ? '<div style="margin-top:' . $marginTop . ';"><strong>' . cms_esc($label) . ':</strong> ' . cms_esc($value) . '</div>'
            : '';
    };
    $list = static function (string $label, array $items) {
        if ($items === []) {
            return '';
        }
        $html = '<div style="margin-top:8px;"><strong>' . cms_esc($label) . ':</strong><ul style="margin:4px 0 0;padding-left:18px;">';
        foreach ($items as $item) {
            $html .= '<li>' . cms_esc((string) $item) . '</li>';
        }
        return $html . '</ul></div>';
    };

    switch ($jobType) {
        case 'article_draft':
            $html = $field('Meta title', (string) ($data['meta_title'] ?? ''))
                . $field('Meta description', (string) ($data['meta_description'] ?? ''))
                . $field('Excerpt', (string) ($data['excerpt'] ?? ''), '8px');
            $content = trim(strip_tags((string) ($data['content'] ?? '')));
            if ($content !== '') {
                $truncated = mb_substr($content, 0, 1500);
                $html .= '<div style="margin-top:8px;"><strong>Content (plain text preview):</strong><br>'
                    . nl2br(cms_esc($truncated)) . (mb_strlen($content) > 1500 ? '…' : '') . '</div>';
            }
            return $html !== '' ? $html : '<span class="muted">Output kosong.</span>';

        case 'faq':
            $items = is_array($data['faq'] ?? null) ? $data['faq'] : [];
            if ($items === []) {
                return '<span class="muted">Tidak ada FAQ item.</span>';
            }
            $html = '<ol style="margin:0;padding-left:18px;">';
            foreach ($items as $item) {
                $html .= '<li style="margin-bottom:6px;"><strong>' . cms_esc((string) ($item['question'] ?? '')) . '</strong><br>'
                    . cms_esc((string) ($item['answer'] ?? '')) . '</li>';
            }
            return $html . '</ol>';

        case 'seo_meta':
        case 'seo_recommendation':
            // seo_recommendation has its own dedicated Review page
            // (recommended_meta_title/description keys, not
            // meta_title/meta_description) — still worth a quick glance
            // here before clicking through.
            $html = $field('Meta title', (string) ($data['meta_title'] ?? $data['recommended_meta_title'] ?? ''))
                . $field('Meta description', (string) ($data['meta_description'] ?? $data['recommended_meta_description'] ?? ''), '4px');
            return $html !== '' ? $html : '<span class="muted">Output kosong.</span>';

        case 'gsc_content_optimization':
            $html = $field('Summary', (string) ($data['summary'] ?? ''));
            $html .= $list('Suggested sections', is_array($data['suggested_sections'] ?? null) ? $data['suggested_sections'] : []);
            return $html !== '' ? $html : '<span class="muted">Output kosong.</span>';

        case 'gsc_article_idea':
            $html = $field('Judul usulan', (string) ($data['title'] ?? ''));
            $html .= $list('Outline', is_array($data['outline'] ?? null) ? $data['outline'] : []);
            return $html !== '' ? $html : '<span class="muted">Output kosong.</span>';

        default:
            return '<pre style="white-space:pre-wrap;word-break:break-word;margin:0;font-size:12px;">'
                . cms_esc((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }
}

// ── Recent jobs — with page title (if linked) and whether feedback already exists ──
$jobsStmt = $pdo->query(
    "SELECT j.id, j.job_type, j.agent_key, j.page_id, j.status, j.priority, j.model_used, j.latency_ms,
            j.error_message, j.output_json, j.created_at, p.title AS page_title,
            (SELECT COUNT(*) FROM growth_agent_feedback f WHERE f.job_id = j.id) AS feedback_count
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      ORDER BY j.created_at DESC
      LIMIT 25"
);
$jobs = $jobsStmt->fetchAll();

// ── Style rules ──────────────────────────────────────────────────────
$rulesStmt = $pdo->query(
    'SELECT id, rule_text, source, is_active, created_at FROM growth_agent_style_rules ORDER BY created_at DESC'
);
$styleRules = $rulesStmt->fetchAll();

// ── Agent Memory (docs/GROWTH_AGENT_MEMORY_PLAN.md) ──────────────────
// Moved to its own page (pages/agent-memory.php) — this is just a count
// for the pointer badge in the toolbar below, not the full panel anymore.
$memoryPendingCount = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_memory WHERE status = 'pending_review'");

$statusPill = [
    'ready' => 'muted',
    'running' => 'accent',
    'succeeded' => 'ok',
    'failed' => 'warn',
    'manual_action' => 'info',
];

$gscSettings = cms_gsc_get_settings($pdo);
$gscConnected = !empty($gscSettings['is_active']) && !empty($gscSettings['site_url']);

// ── GSC aggregate stats + Top Queries (docs/GSC_OPPORTUNITIES_REVISION.md § 4) ──
$gscAggregate = null;
$gscTopQueries = [];
if ($gscConnected) {
    try {
        $aggRow = $pdo->query(
            'SELECT SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    AVG(position) AS avg_position, MIN(data_date) AS min_date, MAX(data_date) AS max_date
               FROM gsc_query_data'
        )->fetch();
        if ($aggRow && (int) ($aggRow['total_impressions'] ?? 0) > 0) {
            $impressions = (int) $aggRow['total_impressions'];
            $gscAggregate = [
                'clicks' => (int) $aggRow['total_clicks'],
                'impressions' => $impressions,
                'ctr' => round(((int) $aggRow['total_clicks'] / $impressions) * 100, 2),
                'avg_position' => round((float) $aggRow['avg_position'], 1),
                'min_date' => (string) $aggRow['min_date'],
                'max_date' => (string) $aggRow['max_date'],
            ];
        }

        $topStmt = $pdo->query(
            'SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
               FROM gsc_query_data
              GROUP BY query
              ORDER BY impressions DESC
              LIMIT 10'
        );
        $gscTopQueries = $topStmt->fetchAll();
    } catch (Throwable $e) {
        $gscAggregate = null;
        $gscTopQueries = [];
    }
}

// ── Prioritized Opportunities (docs/GSC_OPPORTUNITIES_REVISION.md) ──────
$opportunities = [];
if ($gscConnected) {
    try {
        $oppStmt = $pdo->query(
            "SELECT o.*, p.title AS page_title, p.slug AS page_slug
               FROM gsc_opportunities o
               LEFT JOIN pages p ON p.page_id = o.matched_page_id
              WHERE o.status = 'open'
              ORDER BY FIELD(o.priority, 'high', 'medium', 'low'), o.impact_score DESC
              LIMIT 30"
        );
        $opportunities = $oppStmt->fetchAll();
    } catch (Throwable $e) {
        $opportunities = [];
    }
}
$priorityPill = ['high' => 'warn', 'medium' => 'accent', 'low' => 'muted'];

// Scopes the panel text/spacing CSS fix in admin.css to this page only —
// see .page-growth-agent rules there.
$bodyClass = 'page-growth-agent';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Growth Agent</h2>
            <p class="section-lead">SEO &amp; content pipeline — drafts, runs, and hands work back for approval.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_nav_href('agent-memory.php')) ?>">
                Agent Memory
                <?php if ($memoryPendingCount > 0) : ?>
                    <span class="pill pill--warn" style="margin-left:6px;"><?= $memoryPendingCount ?> pending</span>
                <?php endif; ?>
            </a>
            <form method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="scan_seo">
                <button type="submit" class="admin-btn admin-btn--primary">Scan for SEO improvements</button>
            </form>
        </div>
    </div>
    <p class="section-lead" style="margin-top:-8px;">Scan checks published articles that haven't been scanned yet (up to 5 per click) and proposes an improved meta title/description for each — nothing is changed until you review and apply it.</p>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Google Search Console</h3>
            <span class="pill pill--<?= $gscConnected ? 'ok' : 'muted' ?>"><?= $gscConnected ? 'Connected' : 'Not connected' ?></span>
        </div>
        <div class="toolbar" style="padding:16px 20px 20px;">
            <div class="toolbar__left">
                <?php if ($gscConnected) : ?>
                    <p class="muted" style="margin:0;font-size:13px;">
                        Property: <code><?= cms_esc((string) $gscSettings['site_url']) ?></code><br>
                        Last fetch:
                        <?php if (!empty($gscSettings['last_fetch_at'])) : ?>
                            <span class="pill pill--<?= $gscSettings['last_fetch_status'] === 'success' ? 'ok' : 'warn' ?>" style="margin-left:4px;"><?= cms_esc((string) $gscSettings['last_fetch_status']) ?></span>
                            <?= (int) $gscSettings['last_fetch_rows'] ?> rows — <?= cms_esc((string) $gscSettings['last_fetch_at']) ?>
                        <?php else : ?>
                            <span class="muted">belum pernah — akan otomatis jalan begitu halaman ini dibuka (atau klik Refresh Data).</span>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="muted" style="margin:0;font-size:13px;">
                        Belum tersambung ke Google Search Console — rekomendasi berbasis data GSC (tabel "Prioritized Opportunities" di bawah) belum bisa jalan.
                        <a class="panel__link" href="<?= cms_esc(cms_nav_href('gsc-settings.php')) ?>">Hubungkan sekarang &rarr;</a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="toolbar__right" style="gap:8px;">
                <?php if ($gscConnected) : ?>
                    <form method="post" action="<?= cms_esc(cms_action_href('gsc-refresh.php')) ?>">
                        <?= cms_csrf_field() ?>
                        <button type="submit" class="admin-btn admin-btn--secondary">Refresh Data</button>
                    </form>
                    <form method="post" action="<?= cms_esc($selfUrl) ?>">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="recompute_opportunities">
                        <button type="submit" class="admin-btn admin-btn--ghost">Recompute Opportunities</button>
                    </form>
                <?php endif; ?>
                <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_nav_href('gsc-settings.php')) ?>">GSC Settings</a>
            </div>
        </div>

        <?php if ($gscAggregate !== null) : ?>
            <div class="table-wrap" style="padding:0 20px 4px;">
                <p class="muted" style="font-size:12px;margin:0 0 12px;">
                    Rentang data: <?= cms_esc($gscAggregate['min_date']) ?> &ndash; <?= cms_esc($gscAggregate['max_date']) ?>
                    (<?= (int) ($gscSettings['fetch_lookback_days'] ?? 14) ?> hari lookback) — Search Console punya delay
                    &sim;3 hari, jadi beberapa hari paling baru belum tentu lengkap.
                </p>
            </div>
            <div class="admin-grid admin-grid--stats" style="padding:0 20px 20px;">
                <article class="stat-card">
                    <div class="stat-card__label">Clicks</div>
                    <div class="stat-card__value"><?= number_format($gscAggregate['clicks']) ?></div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">Impressions</div>
                    <div class="stat-card__value"><?= number_format($gscAggregate['impressions']) ?></div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">CTR</div>
                    <div class="stat-card__value"><?= cms_esc((string) $gscAggregate['ctr']) ?>%</div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">Avg. Position</div>
                    <div class="stat-card__value"><?= cms_esc((string) $gscAggregate['avg_position']) ?></div>
                </article>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($gscTopQueries === []) : ?>
                            <tr><td colspan="5" class="muted">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($gscTopQueries as $q) : ?>
                            <?php $qImpressions = (int) $q['impressions']; $qCtr = $qImpressions > 0 ? round(((int) $q['clicks'] / $qImpressions) * 100, 2) : 0.0; ?>
                            <tr>
                                <td><?= cms_esc((string) $q['query']) ?></td>
                                <td><?= number_format((int) $q['clicks']) ?></td>
                                <td><?= number_format($qImpressions) ?></td>
                                <td><?= cms_esc((string) $qCtr) ?>%</td>
                                <td><?= cms_esc((string) round((float) $q['position'], 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Prioritized Opportunities</h3>
            <span class="panel__meta"><?= count($opportunities) ?> open</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Item</th>
                        <th>Matched Categories</th>
                        <th>Impact</th>
                        <th>Effort</th>
                        <th>Recommended Agent</th>
                        <th>Reason</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($opportunities === []) : ?>
                        <tr><td colspan="8" class="muted">Belum ada opportunity — akan muncul otomatis setelah data GSC di-fetch (atau klik "Recompute Opportunities" di atas).</td></tr>
                    <?php endif; ?>
                    <?php foreach ($opportunities as $opp) : ?>
                        <tr>
                            <td><span class="pill pill--<?= $priorityPill[$opp['priority']] ?? 'muted' ?>"><?= strtoupper(cms_esc((string) $opp['priority'])) ?></span></td>
                            <td>
                                <?php if ($opp['item_type'] === 'page') : ?>
                                    <?= $opp['page_title'] ? cms_esc((string) $opp['page_title']) : '<span class="muted">Artikel #' . (int) $opp['matched_page_id'] . '</span>' ?>
                                    <span class="muted" style="font-size:11px;">(page)</span>
                                <?php else : ?>
                                    <?= cms_esc((string) $opp['query_text']) ?>
                                    <span class="muted" style="font-size:11px;">(query)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach (array_filter(array_map('trim', explode(',', (string) $opp['matched_categories']))) as $cat) : ?>
                                    <span class="pill pill--muted" style="margin:0 3px 3px 0;"><?= cms_esc($cat) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><?= (int) $opp['impact_score'] ?>/10</td>
                            <td><?= (int) $opp['effort_score'] ?>/10</td>
                            <td><code><?= cms_esc((string) $opp['recommended_agent']) ?></code></td>
                            <td class="muted" style="font-size:12px;max-width:320px;"><?= cms_esc((string) $opp['reason']) ?></td>
                            <td class="table-actions">
                                <form method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="generate_from_opportunity">
                                    <input type="hidden" name="opportunity_id" value="<?= (int) $opp['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--primary">Generate</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="admin-grid admin-grid--stats">
        <?php foreach ($statsCards as $card) : ?>
            <article class="stat-card">
                <div class="stat-card__label"><?= cms_esc($card['label']) ?></div>
                <div class="stat-card__value"><?= cms_esc((string) $card['value']) ?></div>
                <div class="stat-card__hint"><?= cms_esc($card['hint']) ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Recent jobs</h3>
            <span class="panel__meta"><?= count($jobs) ?> shown</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Article</th>
                        <th>Status</th>
                        <th>Model</th>
                        <th>Latency</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($jobs === []) : ?>
                        <tr><td colspan="7" class="muted">No jobs yet — they'll show up here every time Generate SEO (or a future Growth Agent job type) runs.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($jobs as $job) : ?>
                        <?php
                        $pill = $statusPill[$job['status']] ?? 'muted';
                        $isSeoRecommendation = $job['job_type'] === 'seo_recommendation';
                        // seo_recommendation jobs get their own review page (Apply
                        // writes straight into pages.meta_title/meta_description),
                        // so they never use the generic Approve/Reject buttons below.
                        $canReviewGeneric = !$isSeoRecommendation && (int) $job['feedback_count'] === 0 && in_array($job['status'], ['succeeded', 'failed', 'manual_action'], true);
                        $canReviewSeo = $isSeoRecommendation && $job['status'] === 'manual_action';
                        ?>
                        <tr>
                            <td>
                                <strong><?= cms_esc((string) $job['job_type']) ?></strong><br>
                                <span class="muted">agent: <code><?= cms_esc((string) $job['agent_key']) ?></code></span>
                            </td>
                            <td><?= $job['page_title'] ? cms_esc((string) $job['page_title']) : '<span class="muted">—</span>' ?></td>
                            <td>
                                <span class="pill pill--<?= $pill ?>"><?= cms_esc((string) $job['status']) ?></span>
                                <?php if (($job['priority'] ?? 'medium') === 'high') : ?>
                                    <span class="pill pill--warn" title="Prioritas tinggi — sinyal GSC kuat, worth ditindaklanjuti duluan">HIGH</span>
                                <?php elseif (($job['priority'] ?? 'medium') === 'low') : ?>
                                    <span class="pill pill--muted" title="Prioritas rendah">LOW</span>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'failed' && $job['error_message']) : ?>
                                    <div class="muted" style="font-size:11px;margin-top:4px;max-width:220px;"><?= cms_esc(mb_substr((string) $job['error_message'], 0, 140)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $job['model_used'] ? cms_esc((string) $job['model_used']) : '<span class="muted">—</span>' ?></td>
                            <td><?= $job['latency_ms'] !== null ? cms_esc((string) $job['latency_ms']) . ' ms' : '<span class="muted">—</span>' ?></td>
                            <td class="muted"><?= cms_esc((string) $job['created_at']) ?></td>
                            <td class="table-actions">
                                <?php if ($canReviewSeo) : ?>
                                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="seo-recommendation-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                                <?php elseif ($canReviewGeneric) : ?>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Approve</button>
                                    </form>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Reject</button>
                                    </form>
                                <?php else : ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($job['output_json'] !== null) : ?>
                            <tr>
                                <td colspan="7" style="padding-top:0;padding-bottom:0;border-top:none;">
                                    <details>
                                        <summary style="cursor:pointer;font-size:12px;color:var(--brown-soft);padding:4px 0;">Preview isi draft</summary>
                                        <div style="font-size:13px;padding:8px 0 12px;max-width:640px;">
                                            <?= cms_growth_agent_format_job_preview((string) $job['job_type'], $job['output_json']) ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Style rules</h3>
            <span class="panel__meta"><?= count($styleRules) ?> item(s)</span>
        </div>
        <p class="section-lead">Active rules are folded into every generate call — see Fase 3 in the GrowthAgent architecture doc.</p>

        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:16px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="style_rule_create">
            <textarea name="rule_text" rows="2" placeholder="e.g. Always write meta_title in Bahasa Indonesia, never use clickbait phrasing." style="flex:1;" required></textarea>
            <button type="submit" class="admin-btn admin-btn--primary">Add rule</button>
        </form>

        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($styleRules === []) : ?>
                        <tr><td colspan="4" class="muted">No style rules yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($styleRules as $rule) : ?>
                        <tr>
                            <td><?= cms_esc((string) $rule['rule_text']) ?></td>
                            <td><span class="muted"><?= cms_esc((string) $rule['source']) ?></span></td>
                            <td>
                                <?php if ((int) $rule['is_active'] === 1) : ?>
                                    <span class="pill pill--ok">Active</span>
                                <?php else : ?>
                                    <span class="pill pill--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_toggle">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary"><?= (int) $rule['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Remove this style rule?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_delete">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Delete</button>
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
            <h3 class="panel__title">Maintenance</h3>
        </div>
        <p class="section-lead">
            Job lama otomatis dibersihkan setiap halaman ini dibuka (job yang sudah selesai &amp; berumur
            &gt; 90 hari). Job berstatus <strong>Manual Actions</strong> (belum di-review) dan job yang sudah
            di-Approve sebagai contoh (few-shot) tidak pernah dihapus otomatis maupun manual.
        </p>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Hapus job selesai/gagal yang lebih tua dari jumlah hari ini? Job yang masih menunggu review tidak akan terhapus.');">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="cleanup_jobs">
            <label class="muted" style="font-size:13px;">Hapus job selesai/gagal yang lebih tua dari</label>
            <input type="number" name="days" value="30" min="7" max="365" style="width:80px;">
            <span class="muted" style="font-size:13px;">hari</span>
            <button type="submit" class="admin-btn admin-btn--ghost">Bersihkan sekarang</button>
        </form>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
