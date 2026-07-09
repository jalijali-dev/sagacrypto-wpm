<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle  = 'SEO Dashboard';
$currentNav = 'seo-dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard',      'href' => cms_dashboard_href()],
    ['label' => 'SEO Dashboard',  'href' => ''],
];

// ── Summary + audit counts ────────────────────────────────────────────────────
// One query per table returns total + two missing-field counts in a single pass.

$productStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN meta_title       IS NULL OR TRIM(meta_title)       = \'\' THEN 1 ELSE 0 END) AS missing_title,
        SUM(CASE WHEN meta_description IS NULL OR TRIM(meta_description) = \'\' THEN 1 ELSE 0 END) AS missing_desc
     FROM products'
)->fetch();

$pageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN meta_title       IS NULL OR TRIM(meta_title)       = \'\' THEN 1 ELSE 0 END) AS missing_title,
        SUM(CASE WHEN meta_description IS NULL OR TRIM(meta_description) = \'\' THEN 1 ELSE 0 END) AS missing_desc
     FROM pages'
)->fetch();

$specialStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN meta_title       IS NULL OR TRIM(meta_title)       = \'\' THEN 1 ELSE 0 END) AS missing_title,
        SUM(CASE WHEN meta_description IS NULL OR TRIM(meta_description) = \'\' THEN 1 ELSE 0 END) AS missing_desc
     FROM special_pages'
)->fetch();

// ── Detail rows: only records with at least one SEO field missing ─────────────

$productsWithIssues = $pdo->query(
    'SELECT id, name, meta_title, meta_description
     FROM products
     WHERE (meta_title       IS NULL OR TRIM(meta_title)       = \'\')
        OR (meta_description IS NULL OR TRIM(meta_description) = \'\')
     ORDER BY id ASC'
)->fetchAll();

$pagesWithIssues = $pdo->query(
    'SELECT page_id, title, meta_title, meta_description
     FROM pages
     WHERE (meta_title       IS NULL OR TRIM(meta_title)       = \'\')
        OR (meta_description IS NULL OR TRIM(meta_description) = \'\')
     ORDER BY page_id ASC'
)->fetchAll();

$specialWithIssues = $pdo->query(
    'SELECT special_page_id, page_key, title, meta_title, meta_description
     FROM special_pages
     WHERE (meta_title       IS NULL OR TRIM(meta_title)       = \'\')
        OR (meta_description IS NULL OR TRIM(meta_description) = \'\')
     ORDER BY special_page_id ASC'
)->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Return an integer value from a stats row, defaulting to 0. */
$stat = static fn (mixed $row, string $key): int => (int) ($row[$key] ?? 0);

/** Render a pill for a meta field: danger if empty, ok if present. */
$seoPill = static function (?string $value): string {
    $empty = ($value === null || trim($value) === '');
    $cls   = $empty ? 'pill--danger' : 'pill--ok';
    $label = $empty ? 'Missing'      : 'OK';
    return '<span class="pill ' . $cls . '">' . $label . '</span>';
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">

    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">SEO Dashboard</h2>
            <p class="section-lead">Meta title and description coverage across products, pages, and special pages.</p>
        </div>
    </div>

    <!-- Section 1: Summary cards -->
    <div class="admin-grid admin-grid--stats">
        <article class="stat-card">
            <div class="stat-card__label">Total Products</div>
            <div class="stat-card__value"><?= $stat($productStats, 'total') ?></div>
            <div class="stat-card__hint">All catalog SKUs</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Total Pages</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'total') ?></div>
            <div class="stat-card__hint">Articles &amp; content</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Total Special Pages</div>
            <div class="stat-card__value"><?= $stat($specialStats, 'total') ?></div>
            <div class="stat-card__hint">Home, about, contact, etc.</div>
        </article>
    </div>

    <!-- Section 2: SEO audit cards -->
    <div class="admin-grid admin-grid--stats">
        <article class="stat-card">
            <div class="stat-card__label">Products — Missing Meta Title</div>
            <div class="stat-card__value"><?= $stat($productStats, 'missing_title') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($productStats, 'missing_title') > 0) : ?>
                    <a href="#tbl-products">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Products — Missing Meta Desc</div>
            <div class="stat-card__value"><?= $stat($productStats, 'missing_desc') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($productStats, 'missing_desc') > 0) : ?>
                    <a href="#tbl-products">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Pages — Missing Meta Title</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'missing_title') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($pageStats, 'missing_title') > 0) : ?>
                    <a href="#tbl-pages">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Pages — Missing Meta Desc</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'missing_desc') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($pageStats, 'missing_desc') > 0) : ?>
                    <a href="#tbl-pages">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Special Pages — Missing Meta Title</div>
            <div class="stat-card__value"><?= $stat($specialStats, 'missing_title') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($specialStats, 'missing_title') > 0) : ?>
                    <a href="#tbl-special">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Special Pages — Missing Meta Desc</div>
            <div class="stat-card__value"><?= $stat($specialStats, 'missing_desc') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($specialStats, 'missing_desc') > 0) : ?>
                    <a href="#tbl-special">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
    </div>

    <!-- Section 3a: Products with SEO issues -->
    <div class="panel" id="tbl-products">
        <div class="panel__head">
            <h3 class="panel__title">Products with SEO Issues</h3>
            <span class="panel__meta"><?= count($productsWithIssues) ?> issue(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Meta Title</th>
                        <th>Meta Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productsWithIssues === []) : ?>
                        <tr><td colspan="5" class="muted">No SEO issues found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($productsWithIssues as $row) : ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= cms_esc((string) ($row['name'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_description'] ?? '')) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary"
                                   href="products.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 3b: Pages with SEO issues -->
    <div class="panel" id="tbl-pages">
        <div class="panel__head">
            <h3 class="panel__title">Pages with SEO Issues</h3>
            <span class="panel__meta"><?= count($pagesWithIssues) ?> issue(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Meta Title</th>
                        <th>Meta Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pagesWithIssues === []) : ?>
                        <tr><td colspan="5" class="muted">No SEO issues found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pagesWithIssues as $row) : ?>
                        <tr>
                            <td><?= (int) $row['page_id'] ?></td>
                            <td><?= cms_esc((string) ($row['title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_description'] ?? '')) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary"
                                   href="pages.php?edit=<?= (int) $row['page_id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 3c: Special Pages with SEO issues -->
    <div class="panel" id="tbl-special">
        <div class="panel__head">
            <h3 class="panel__title">Special Pages with SEO Issues</h3>
            <span class="panel__meta"><?= count($specialWithIssues) ?> issue(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Page Key</th>
                        <th>Title</th>
                        <th>Meta Title</th>
                        <th>Meta Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($specialWithIssues === []) : ?>
                        <tr><td colspan="6" class="muted">No SEO issues found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($specialWithIssues as $row) : ?>
                        <tr>
                            <td><?= (int) $row['special_page_id'] ?></td>
                            <td><code><?= cms_esc((string) ($row['page_key'] ?? '')) ?></code></td>
                            <td><?= cms_esc((string) ($row['title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_description'] ?? '')) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary"
                                   href="special-pages.php?edit=<?= (int) $row['special_page_id'] ?>">Edit</a>
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
