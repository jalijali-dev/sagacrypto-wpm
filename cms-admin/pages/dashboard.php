<?php
declare(strict_types=1);

if (!isset($cmsDashboardFragment)) {
    header('Location: ../dashboard.php', true, 302);
    exit;
}

// ── Stats queries — one fetch per table, safe fallback to [] on empty result ──

$productStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
     FROM products'
)->fetch() ?: [];

$galleryStats = $pdo->query(
    'SELECT COUNT(*) AS total FROM gallery'
)->fetch() ?: [];

$messageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
     FROM contact_messages'
)->fetch() ?: [];

$pageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = \'published\' THEN 1 ELSE 0 END) AS published
     FROM pages'
)->fetch() ?: [];

$redirectStats = $pdo->query(
    'SELECT COUNT(*) AS total FROM seo_redirects'
)->fetch() ?: [];

// media_library may not be present in all environments yet — fail silently.
$mediaTotal = 0;
try {
    $mediaRow = $pdo->query('SELECT COUNT(*) AS total FROM media_library')->fetch();
    $mediaTotal = (int) ($mediaRow['total'] ?? 0);
} catch (\Throwable $e) {
    $mediaTotal = 0;
}

// Build stats cards array — same structure the template iterates.
$statsCards = [
    [
        'label' => 'Active products',
        'value' => (string) (int) ($productStats['active'] ?? 0),
        'hint'  => ((int) ($productStats['total'] ?? 0)) . ' total in catalog',
    ],
    [
        'label' => 'Gallery items',
        'value' => (string) (int) ($galleryStats['total'] ?? 0),
        'hint'  => 'Images in gallery',
    ],
    [
        'label' => 'Contact messages',
        'value' => (string) (int) ($messageStats['total'] ?? 0),
        'hint'  => ((int) ($messageStats['unread'] ?? 0)) . ' unread',
    ],
    [
        'label' => 'Published pages',
        'value' => (string) (int) ($pageStats['published'] ?? 0),
        'hint'  => ((int) ($pageStats['total'] ?? 0)) . ' total articles',
    ],
    [
        'label' => 'SEO redirects',
        'value' => (string) (int) ($redirectStats['total'] ?? 0),
        'hint'  => '301 / 302 rules',
    ],
    [
        'label' => 'Media files',
        'value' => (string) $mediaTotal,
        'hint'  => 'Library uploads',
    ],
];

// ── Recent products ───────────────────────────────────────────────────────────

$recentProducts = $pdo->query(
    'SELECT p.id, p.name, p.slug, p.price, p.is_active,
            c.name AS category_name
     FROM products p
     LEFT JOIN product_categories c ON c.id = p.category_id
     ORDER BY p.id DESC
     LIMIT 5'
)->fetchAll();

// ── Latest contact messages ───────────────────────────────────────────────────

$latestMessages = $pdo->query(
    'SELECT id, full_name, email, subject, is_read, created_at
     FROM contact_messages
     ORDER BY created_at DESC, id DESC
     LIMIT 5'
)->fetchAll();

// ── Date formatter ────────────────────────────────────────────────────────────

$fmtDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};
?>
<section class="admin-stack">
    <div class="admin-grid admin-grid--stats">
        <?php foreach ($statsCards as $card) : ?>
            <article class="stat-card">
                <div class="stat-card__label"><?= cms_esc($card['label']) ?></div>
                <div class="stat-card__value"><?= cms_esc($card['value']) ?></div>
                <div class="stat-card__hint"><?= cms_esc($card['hint']) ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="admin-grid admin-grid--2">
        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Recent products</h2>
                <a class="panel__link" href="<?= cms_esc(cms_nav_href('products.php')) ?>">View all</a>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Slug</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentProducts === []) : ?>
                            <tr><td colspan="5" class="muted">No products yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentProducts as $row) : ?>
                            <?php $active = (int) ($row['is_active'] ?? 0) === 1; ?>
                            <tr>
                                <td><?= cms_esc((string) ($row['name'] ?? '')) ?></td>
                                <td><code><?= cms_esc((string) ($row['slug'] ?? '')) ?></code></td>
                                <td><?= cms_esc((string) ($row['category_name'] ?? '—')) ?></td>
                                <td><?= cms_esc((string) ($row['price'] ?? '')) ?></td>
                                <td>
                                    <span class="pill pill--<?= $active ? 'ok' : 'muted' ?>">
                                        <?= $active ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Latest contact messages</h2>
                <a class="panel__link" href="<?= cms_esc(cms_nav_href('contact-messages.php')) ?>">Open inbox</a>
            </div>
            <ul class="message-list">
                <?php if ($latestMessages === []) : ?>
                    <li class="message-list__item muted">No messages yet.</li>
                <?php endif; ?>
                <?php foreach ($latestMessages as $msg) : ?>
                    <?php $isUnread = (int) ($msg['is_read'] ?? 1) === 0; ?>
                    <li class="message-list__item">
                        <div class="message-list__top">
                            <strong><?= cms_esc((string) ($msg['full_name'] ?? '')) ?></strong>
                            <?php if ($isUnread) : ?>
                                <span class="pill pill--accent">New</span>
                            <?php endif; ?>
                        </div>
                        <div class="message-list__sub">
                            <?= cms_esc((string) ($msg['email'] ?? '')) ?>
                            · <?= cms_esc($fmtDt($msg['created_at'] ?? null)) ?>
                        </div>
                        <div class="message-list__subject"><?= cms_esc((string) ($msg['subject'] ?? '')) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="admin-grid admin-grid--2">
        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Quick actions</h2>
            </div>
            <div class="quick-actions">
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('landing-page.php')) ?>">Edit landing page</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('products.php')) ?>">Manage products</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('gallery.php')) ?>">Manage gallery</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('contact-messages.php')) ?>">Contact messages</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('seo-redirects.php')) ?>">SEO redirects</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('seo-schema.php')) ?>">SEO schema</a>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Traffic snapshot</h2>
                <span class="panel__meta">Placeholder chart</span>
            </div>
            <div class="chart-placeholder" role="img" aria-label="Placeholder chart area">
                <div class="chart-placeholder__bars">
                    <span style="height:42%"></span>
                    <span style="height:68%"></span>
                    <span style="height:55%"></span>
                    <span style="height:80%"></span>
                    <span style="height:63%"></span>
                    <span style="height:74%"></span>
                    <span style="height:48%"></span>
                </div>
                <p class="chart-placeholder__note">Chart wiring will connect later — layout preview only.</p>
            </div>
        </section>
    </div>
</section>
