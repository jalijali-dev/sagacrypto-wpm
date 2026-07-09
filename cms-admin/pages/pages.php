<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Pages & Articles';
$currentNav = 'pages';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Pages & Articles', 'href' => ''],
];

$selfUrl = 'pages.php';

$pg_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    $target = $selfUrl . ($query !== null && $query !== '' ? '?' . $query : '');
    header('Location: ' . $target, true, 302);
    exit;
};

$pg_validate = static function (string $title, string $slug, string $status): ?string {
    if ($title === '') {
        return 'Title is required.';
    }
    if ($slug === '') {
        return 'Slug is required.';
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        return 'Status must be draft or published.';
    }

    return null;
};

$pg_duplicate_slug = static function (PDO $pdo, string $slug, ?int $excludeId): ?string {
    $sql = 'SELECT COUNT(*) FROM pages WHERE slug = :slug';
    $params = ['slug' => $slug];
    if ($excludeId !== null) {
        $sql .= ' AND page_id != :id';
        $params['id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return 'That slug is already in use.';
    }

    return null;
};

$pg_parse_published_at = static function (string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['page_id'] ?? 0);
        if ($deleteId <= 0) {
            $pg_redirect('Invalid article.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM pages WHERE page_id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $pg_redirect('Article not found or already deleted.', 'error');
        }
        $pg_redirect('Article deleted successfully.');
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $featuredImage = trim((string) ($_POST['featured_image'] ?? ''));
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
    $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
    $faqJson = trim((string) ($_POST['faq_json'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $publishedAt = $pg_parse_published_at((string) ($_POST['published_at'] ?? ''));

    // Auto-fill published_at when publishing without a date — keeps draft saves clean
    if ($status === 'published' && $publishedAt === null) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    // Validate faq_json only when provided — empty is always allowed.
    if ($faqJson !== '') {
        $faqDecoded = json_decode($faqJson, true);
        if (!is_array($faqDecoded)) {
            $faqErrorQuery = null;
            if ($action === 'update') {
                $faqFailId = (int) ($_POST['page_id'] ?? 0);
                if ($faqFailId > 0) {
                    $faqErrorQuery = 'edit=' . $faqFailId;
                }
            }
            $pg_redirect('FAQ JSON tidak valid. Periksa kembali atau kosongkan field tersebut.', 'error', $faqErrorQuery);
        }
    }

    $validationError = $pg_validate($title, $slug, $status);
    if ($validationError !== null) {
        $errorQuery = null;
        if ($action === 'update') {
            $failId = (int) ($_POST['page_id'] ?? 0);
            if ($failId > 0) {
                $errorQuery = 'edit=' . $failId;
            }
        }
        $pg_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'title'            => $title,
        'slug'             => $slug,
        'featured_image'   => $featuredImage,
        'excerpt'          => $excerpt,
        'content'          => $content,
        'meta_title'       => $metaTitle,
        'meta_description' => $metaDescription,
        'faq_json'         => $faqJson !== '' ? $faqJson : null,
        'status'           => $status,
        'published_at'     => $publishedAt,
    ];

    if ($action === 'create') {
        $dupError = $pg_duplicate_slug($pdo, $slug, null);
        if ($dupError !== null) {
            $pg_redirect($dupError, 'error');
        }

        $insert = $pdo->prepare(
            'INSERT INTO pages (
                title, slug, featured_image, content, excerpt, meta_title, meta_description,
                faq_json, status, published_at, created_at, updated_at
            ) VALUES (
                :title, :slug, :featured_image, :content, :excerpt, :meta_title, :meta_description,
                :faq_json, :status, :published_at, NOW(), NOW()
            )'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $pg_redirect('Article created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['page_id'] ?? 0);
        if ($updateId <= 0) {
            $pg_redirect('Invalid article.', 'error');
        }

        $dupError = $pg_duplicate_slug($pdo, $slug, $updateId);
        if ($dupError !== null) {
            $pg_redirect($dupError, 'error', 'edit=' . $updateId);
        }

        $update = $pdo->prepare(
            'UPDATE pages
             SET title = :title,
                 slug = :slug,
                 featured_image = :featured_image,
                 content = :content,
                 excerpt = :excerpt,
                 meta_title = :meta_title,
                 meta_description = :meta_description,
                 faq_json = :faq_json,
                 status = :status,
                 published_at = :published_at,
                 updated_at = NOW()
             WHERE page_id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        if ($update->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT page_id FROM pages WHERE page_id = :id LIMIT 1');
            $exists->execute(['id' => $updateId]);
            if (!$exists->fetch()) {
                $pg_redirect('Article not found.', 'error');
            }
        }
        $pg_redirect('Article updated successfully.', 'success', 'edit=' . $updateId);
    }

    $pg_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

// ---- Article list: search + status filter + pagination ----
$listSearchRaw = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
if (mb_strlen($listSearchRaw, 'UTF-8') > 100) {
    $listSearchRaw = mb_substr($listSearchRaw, 0, 100, 'UTF-8');
}
$listStatus = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
if (!in_array($listStatus, ['published', 'draft'], true)) {
    $listStatus = '';
}

$listPerPage  = 20;
$listPage     = max(1, (int) ($_GET['page'] ?? 1));

$listWhere  = [];
$listParams = [];
if ($listSearchRaw !== '') {
    $listEscaped          = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $listSearchRaw);
    $listWhere[]          = '(title LIKE :search OR slug LIKE :search)';
    $listParams['search'] = '%' . $listEscaped . '%';
}
if ($listStatus !== '') {
    $listWhere[]                 = 'status = :status_filter';
    $listParams['status_filter'] = $listStatus;
}

$listWhereClause = $listWhere !== [] ? ' WHERE ' . implode(' AND ', $listWhere) : '';

// Count matching rows to compute pagination
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM pages' . $listWhereClause);
$countStmt->execute($listParams);
$listTotalRows  = (int) $countStmt->fetchColumn();
$listTotalPages = max(1, (int) ceil($listTotalRows / $listPerPage));

// Clamp page to valid range after knowing total
if ($listPage > $listTotalPages) {
    $listPage = $listTotalPages;
}
$listOffset = ($listPage - 1) * $listPerPage;

// Fetch one page of results
$listSql  = 'SELECT page_id, title, slug, status, published_at, updated_at FROM pages'
          . $listWhereClause
          . ' ORDER BY page_id DESC'
          . ' LIMIT :limit OFFSET :offset';
$listStmt = $pdo->prepare($listSql);
// Bind integer params explicitly — PDO LIMIT/OFFSET requires PDO::PARAM_INT
$listStmt->bindValue(':limit',  $listPerPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $listOffset,  PDO::PARAM_INT);
foreach ($listParams as $key => $val_) {
    $listStmt->bindValue(':' . $key, $val_);
}
$listStmt->execute();
$pagesList = $listStmt->fetchAll();

// ---- Article stats (full table, not filtered) ----
$statsRow = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
            SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END) AS draft_count
     FROM pages"
)->fetch();
$articleStats = is_array($statsRow) ? $statsRow : ['total' => 0, 'published_count' => 0, 'draft_count' => 0];

// ---- Pagination URL helper ----
// Preserves search + status params; edit param intentionally excluded.
$paginateUrl = static function (int $targetPage) use ($listSearchRaw, $listStatus, $selfUrl): string {
    $q = [];
    if ($listSearchRaw !== '') {
        $q['search'] = $listSearchRaw;
    }
    if ($listStatus !== '') {
        $q['status'] = $listStatus;
    }
    $q['page'] = $targetPage;
    return $selfUrl . '?' . http_build_query($q);
};

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT page_id, title, slug, featured_image, content, excerpt, meta_title, meta_description,
                faq_json, status, published_at
         FROM pages
         WHERE page_id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Article not found.'];
        $editId = 0;
    }
}

$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

$toDatetimeLocal = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
};

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
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
            <h2 class="section-title">Articles & Content</h2>
            <p class="section-lead">Create and manage articles, guides, tips, and SEO-friendly content for the website.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#create-page') ?>">New Article</a>
        </div>
    </div>

    <?php if ($editRow) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Edit Article</h3>
            <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="page_id" value="<?= (int) $editRow['page_id'] ?>">
            <label class="field">Title
                <input type="text" name="title" value="<?= cms_esc($val($editRow, 'title')) ?>" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" value="<?= cms_esc($val($editRow, 'slug')) ?>" required>
            </label>
            <label class="field">Featured image
                <input type="text" name="featured_image" id="pg-feat-img-edit"
                       value="<?= cms_esc($val($editRow, 'featured_image')) ?>"
                       placeholder="e.g. /uploads/media/2026/05/file.webp"
                       autocomplete="off">
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Recommended: 1200 × 630 px (OG Image). JPG, PNG, atau WEBP. Maks. 5 MB.</small>
            </label>
            <?php $editStatus = strtolower($val($editRow, 'status')); ?>
            <label class="field field--status">Status
                <select name="status" required>
                    <option value="draft"<?= $editStatus === 'draft' ? ' selected' : '' ?>>draft</option>
                    <option value="published"<?= $editStatus === 'published' ? ' selected' : '' ?>>published</option>
                </select>
            </label>
            <label class="field">Published at
                <input type="datetime-local" name="published_at" value="<?= cms_esc($toDatetimeLocal($editRow['published_at'] ?? null)) ?>">
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title" value="<?= cms_esc($val($editRow, 'meta_title')) ?>">
            </label>
            <label class="field" style="grid-column: 1 / -1;">Meta description
                <textarea name="meta_description" rows="3"><?= cms_esc($val($editRow, 'meta_description')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Excerpt
                <textarea name="excerpt" rows="3"><?= cms_esc($val($editRow, 'excerpt')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="pg-content" rows="6"><?= cms_esc($val($editRow, 'content')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">FAQ JSON
                <textarea name="faq_json" rows="8" style="font-family:monospace;font-size:12px;" placeholder="Klik Generate FAQ untuk mengisi otomatis, atau tulis JSON manual."><?= cms_esc($val($editRow, 'faq_json')) ?></textarea>
                <small style="font-size:11px;color:var(--muted,#888);margin-top:3px;">Diisi otomatis oleh AI. Periksa dan edit sebelum menyimpan. Boleh dikosongkan.</small>
            </label>
            <div style="grid-column: 1 / -1;">
                <!-- Notes for Agent SEO — visible by default, no name attr so never submitted to DB -->
                <div style="margin-top:12px;">
                    <label class="field" style="max-width:480px;">Catatan untuk Agent SEO
                        <textarea class="pg-article-notes" rows="4"
                                  placeholder="Target audience description.&#10;Tone and language guidelines.&#10;Mention the site or brand naturally.&#10;Include 3–5 FAQ items.&#10;Add a CTA to the site at the end.&#10;&#10;More examples:&#10;- Focus on a specific region&#10;- Focus on corporate gifting&#10;- Focus on a product category&#10;- Target a specific demographic"></textarea>
                    </label>
                    <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:3px;">
                        Tips: gunakan catatan untuk memberi instruksi tambahan ke Agent SEO. Catatan ini akan menimpa arahan umum jika ada konflik.
                    </small>
                </div>
                <!-- AI action buttons -->
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px;">
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO</button>
                    <span class="js-seo-status" style="font-size:.85em;color:var(--muted);"></span>
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-article">Generate Article</button>
                    <span class="js-article-status" style="font-size:.85em;color:var(--muted);"></span>
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-faq">Generate FAQ</button>
                    <span class="js-faq-status" style="font-size:.85em;color:var(--muted);"></span>
                </div>
                <!-- Helper boxes -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate SEO with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate Article with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Excerpt</li>
                            <li>Content</li>
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate FAQ with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>5 FAQ items</li>
                            <li>Question &amp; Answer</li>
                            <li>Bahasa Indonesia</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php else : ?>
    <div class="panel" id="create-page">
        <div class="panel__head">
            <h3 class="panel__title">New Article</h3>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Title
                <input type="text" name="title" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" required>
            </label>
            <label class="field">Featured image
                <input type="text" name="featured_image" id="pg-feat-img-create"
                       placeholder="e.g. /uploads/media/2026/05/file.webp"
                       autocomplete="off">
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Recommended: 1200 × 630 px (OG Image). JPG, PNG, atau WEBP. Maks. 5 MB.</small>
            </label>
            <label class="field field--status">Status
                <select name="status" required>
                    <option value="draft" selected>draft</option>
                    <option value="published">published</option>
                </select>
            </label>
            <label class="field">Published at
                <input type="datetime-local" name="published_at">
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title">
            </label>
            <label class="field" style="grid-column: 1 / -1;">Meta description
                <textarea name="meta_description" rows="3"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Excerpt
                <textarea name="excerpt" rows="3"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="pg-content" rows="6"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">FAQ JSON
                <textarea name="faq_json" rows="8" style="font-family:monospace;font-size:12px;" placeholder="Klik Generate FAQ untuk mengisi otomatis, atau tulis JSON manual."></textarea>
                <small style="font-size:11px;color:var(--muted,#888);margin-top:3px;">Diisi otomatis oleh AI. Periksa dan edit sebelum menyimpan. Boleh dikosongkan.</small>
            </label>
            <div style="grid-column: 1 / -1;">
                <!-- Notes for Agent SEO — visible by default, no name attr so never submitted to DB -->
                <div style="margin-top:12px;">
                    <label class="field" style="max-width:480px;">Catatan untuk Agent SEO
                        <textarea class="pg-article-notes" rows="4"
                                  placeholder="Target audience description.&#10;Tone and language guidelines.&#10;Mention the site or brand naturally.&#10;Include 3–5 FAQ items.&#10;Add a CTA to the site at the end.&#10;&#10;More examples:&#10;- Focus on a specific region&#10;- Focus on corporate gifting&#10;- Focus on a product category&#10;- Target a specific demographic"></textarea>
                    </label>
                    <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:3px;">
                        Tips: gunakan catatan untuk memberi instruksi tambahan ke Agent SEO. Catatan ini akan menimpa arahan umum jika ada konflik.
                    </small>
                </div>
                <!-- AI action buttons -->
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px;">
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO</button>
                    <span class="js-seo-status" style="font-size:.85em;color:var(--muted);"></span>
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-article">Generate Article</button>
                    <span class="js-article-status" style="font-size:.85em;color:var(--muted);"></span>
                    <button type="button" class="admin-btn admin-btn--secondary js-generate-faq">Generate FAQ</button>
                    <span class="js-faq-status" style="font-size:.85em;color:var(--muted);"></span>
                </div>
                <!-- Helper boxes -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate SEO with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate Article with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Excerpt</li>
                            <li>Content</li>
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate FAQ with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>5 FAQ items</li>
                            <li>Question &amp; Answer</li>
                            <li>Bahasa Indonesia</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Create Article</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Article stats bar -->
    <div class="pg-stats-bar">
        <span>Total Artikel: <strong><?= (int) $articleStats['total'] ?></strong></span>
        <span class="pg-stats-sep">·</span>
        <span>Published: <strong><?= (int) $articleStats['published_count'] ?></strong></span>
        <span class="pg-stats-sep">·</span>
        <span>Draft: <strong><?= (int) $articleStats['draft_count'] ?></strong></span>
    </div>

    <!-- Search + filter form -->
    <form class="pg-list-filter" method="get" action="">
        <input type="text" name="search" class="pg-filter-input"
               placeholder="Cari judul atau slug…"
               value="<?= cms_esc($listSearchRaw) ?>">
        <select name="status" class="pg-filter-select">
            <option value="">Semua Status</option>
            <option value="published" <?= $listStatus === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="draft"     <?= $listStatus === 'draft'     ? 'selected' : '' ?>>Draft</option>
        </select>
        <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
        <?php if ($listSearchRaw !== '' || $listStatus !== ''): ?>
            <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All Articles</h3>
            <?php
                $listFrom = $listTotalRows > 0 ? $listOffset + 1 : 0;
                $listTo   = min($listOffset + $listPerPage, $listTotalRows);
            ?>
            <span class="panel__meta">
                <?= $listFrom ?>–<?= $listTo ?> dari <?= $listTotalRows ?> artikel<?= ($listSearchRaw !== '' || $listStatus !== '') ? ' (filtered)' : '' ?>
            </span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="pg-col-id">ID</th>
                        <th>Title</th>
                        <th>Slug</th>
                        <th>Status</th>
                        <th>Published At</th>
                        <th>Updated At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pagesList === []) : ?>
                        <tr>
                            <td colspan="7" class="muted">
                                <?= ($listSearchRaw !== '' || $listStatus !== '') ? 'Tidak ada artikel yang cocok dengan filter.' : 'No articles yet.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($pagesList as $row) : ?>
                        <?php
                        $rowId     = (int) $row['page_id'];
                        $published = strtolower((string) $row['status']) === 'published';
                        $updatedTs = isset($row['updated_at']) && $row['updated_at'] !== ''
                            ? strtotime((string) $row['updated_at'])
                            : false;
                        $isNew = $updatedTs !== false && (time() - $updatedTs) < 86400;
                        ?>
                        <tr>
                            <td class="pg-col-id pg-id-cell"><?= $rowId ?></td>
                            <td>
                                <?= cms_esc($val($row, 'title')) ?>
                                <?php if ($isNew): ?>
                                    <span class="pg-badge-new">NEW</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= cms_esc($val($row, 'slug')) ?></code></td>
                            <td><span class="pill pill--<?= $published ? 'ok' : 'muted' ?>"><?= cms_esc($val($row, 'status')) ?></span></td>
                            <td><?= cms_esc($formatDt($row['published_at'] ?? null)) ?></td>
                            <td><?= cms_esc($formatDt($row['updated_at'] ?? null)) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this article?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="page_id" value="<?= $rowId ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($listTotalPages > 1): ?>
    <nav class="pg-pagination" aria-label="Navigasi halaman artikel">
        <?php if ($listPage > 1): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listPage - 1)) ?>">« Prev</a>
        <?php else: ?>
            <span class="pg-page-btn pg-page-btn--disabled">« Prev</span>
        <?php endif; ?>

        <?php
        // Show at most 5 page numbers centered on current page.
        $pgWin  = 2; // pages on each side
        $pgMin  = max(1, $listPage - $pgWin);
        $pgMax  = min($listTotalPages, $listPage + $pgWin);
        // Pad window if near edges
        if ($pgMin === 1) {
            $pgMax = min($listTotalPages, 1 + $pgWin * 2);
        }
        if ($pgMax === $listTotalPages) {
            $pgMin = max(1, $listTotalPages - $pgWin * 2);
        }
        if ($pgMin > 1): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl(1)) ?>">1</a>
            <?php if ($pgMin > 2): ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($pgI = $pgMin; $pgI <= $pgMax; $pgI++): ?>
            <?php if ($pgI === $listPage): ?>
                <span class="pg-page-btn pg-page-btn--active"><?= $pgI ?></span>
            <?php else: ?>
                <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($pgI)) ?>"><?= $pgI ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($pgMax < $listTotalPages): ?>
            <?php if ($pgMax < $listTotalPages - 1): ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listTotalPages)) ?>"><?= $listTotalPages ?></a>
        <?php endif; ?>

        <?php if ($listPage < $listTotalPages): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listPage + 1)) ?>">Next »</a>
        <?php else: ?>
            <span class="pg-page-btn pg-page-btn--disabled">Next »</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

</section>
<style>
/* ---- Article stats bar ---- */
.pg-stats-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--line);
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 12px;
}
.pg-stats-bar strong { color: var(--text); }
.pg-stats-sep { color: var(--muted); opacity: .45; }

/* ---- Article list filter form ---- */
.pg-list-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
}
.pg-filter-input {
    flex: 1;
    min-width: 180px;
    max-width: 320px;
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg);
    color: var(--text);
    font-family: inherit;
}
.pg-filter-input:focus { outline: none; border-color: var(--navlink-active-border); box-shadow: 0 0 0 3px var(--ring); }
.pg-filter-select {
    padding: 8px 28px 8px 10px;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg);
    color: var(--text);
    cursor: pointer;
    font-family: inherit;
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23999'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}
.pg-filter-select:focus { outline: none; border-color: var(--navlink-active-border); }

/* ---- ID column ---- */
.pg-col-id { width: 52px; }
.pg-id-cell {
    font-family: monospace;
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
}

/* ---- Pagination ---- */
.pg-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    margin-top: 14px;
}
.pg-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--surface-soft);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s, border-color .12s;
}
.pg-page-btn:hover { background: var(--navlink-hover-bg); border-color: var(--navlink-active-border); }
.pg-page-btn--active {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--accent-text);
    cursor: default;
}
.pg-page-btn--disabled {
    color: var(--muted);
    border-color: var(--line-subtle);
    background: var(--surface-soft);
    cursor: default;
}
.pg-page-ellipsis {
    padding: 0 4px;
    color: var(--muted);
    font-size: 13px;
    line-height: 34px;
}

/* ---- NEW badge ---- */
.pg-badge-new {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    background: var(--badge-new-bg);
    color: var(--badge-new-text);
    vertical-align: middle;
}

/* ---- Status select ---- */
.field--status select {
    min-height: 0;
    height: 52px;
    padding-top: 0;
    padding-bottom: 0;
    display: flex;
    align-items: center;
    line-height: 1.2;
}
.form-grid .field select {
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23999'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
}

/* ---- Generate SEO helper block ---- */
.pg-seo-helper {
    display: inline-flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 8px;
    padding: 7px 12px;
    border-left: 3px solid var(--seo-helper-border);
    background: var(--seo-helper-bg);
    border-radius: 0 6px 6px 0;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.55;
    max-width: 260px;
}
.pg-seo-helper__label {
    font-weight: 600;
    color: var(--seo-helper-label);
    letter-spacing: .01em;
}
.pg-seo-helper__list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.pg-seo-helper__list li::before {
    content: '• ';
    color: var(--seo-helper-label);
}
@media (max-width: 480px) {
    .pg-seo-helper { max-width: 100%; }
}
</style>
<?php require dirname(__DIR__) . '/includes/tinymce-media-picker.php'; ?>
<script>
(function () {
  document.querySelectorAll('.js-generate-seo').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form       = btn.closest('form');
      var statusEl   = form.querySelector('.js-seo-status');

      var titleEl    = form.querySelector('[name="title"]');
      var slugEl     = form.querySelector('[name="slug"]');
      var excerptEl  = form.querySelector('[name="excerpt"]');
      var contentEl  = form.querySelector('[name="content"]');
      var metaTitleEl = form.querySelector('[name="meta_title"]');
      var metaDescEl  = form.querySelector('[name="meta_description"]');

      // Get content from TinyMCE if active, otherwise fall back to textarea value
      var contentValue = '';
      if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        contentValue = tinymce.activeEditor.getContent({ format: 'text' });
      } else if (contentEl) {
        contentValue = contentEl.value;
      }

      var data = new FormData();
      data.append('type',    'page');
      data.append('title',   titleEl   ? titleEl.value.trim()   : '');
      data.append('slug',    slugEl    ? slugEl.value.trim()    : '');
      data.append('excerpt', excerptEl ? excerptEl.value.trim() : '');
      data.append('content', contentValue.trim());
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled   = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/../../api/seo-generate.php') ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (metaTitleEl) { metaTitleEl.value = json.meta_title; }
            if (metaDescEl)  { metaDescEl.value  = json.meta_description; }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — request took too long. Please try again.'
            : 'Request failed: ' + err.message;
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
// ---- Generate Article with Agent SEO (pages.php only) ----
(function () {
  document.querySelectorAll('.js-generate-article').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form        = btn.closest('form');
      var statusEl    = form.querySelector('.js-article-status');
      var titleEl     = form.querySelector('[name="title"]');
      var notesEl     = form.querySelector('.pg-article-notes');
      var excerptEl   = form.querySelector('[name="excerpt"]');
      var metaTitleEl = form.querySelector('[name="meta_title"]');
      var metaDescEl  = form.querySelector('[name="meta_description"]');

      var contentEl = form.querySelector('[name="content"]');

      var data = new FormData();
      data.append('title', titleEl ? titleEl.value.trim() : '');
      data.append('notes', notesEl ? notesEl.value.trim() : '');
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/../../api/article-generate.php') ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (excerptEl)   { excerptEl.value   = json.excerpt; }
            if (metaTitleEl) { metaTitleEl.value = json.meta_title; }
            if (metaDescEl)  { metaDescEl.value  = json.meta_description; }
            // Populate TinyMCE editor — look up fresh at population time,
            // not at click time, so init() is guaranteed to have completed.
            var editor = (typeof tinymce !== 'undefined') ? tinymce.get('pg-content') : null;
            if (editor) {
              editor.setContent(json.content);
              editor.save(); // sync back to hidden textarea for form submit
            } else if (contentEl) {
              contentEl.value = json.content;
            }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          console.error('[Generate Article]', err);
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — artikel membutuhkan waktu terlalu lama. Coba lagi.'
            : 'Error: ' + (err.message || 'Unknown error.');
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
// ---- Generate FAQ with Agent SEO (pages.php only) ----
(function () {
  document.querySelectorAll('.js-generate-faq').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form      = btn.closest('form');
      var statusEl  = form.querySelector('.js-faq-status');
      var titleEl   = form.querySelector('[name="title"]');
      var excerptEl = form.querySelector('[name="excerpt"]');
      var notesEl   = form.querySelector('.pg-article-notes');
      var faqEl     = form.querySelector('[name="faq_json"]');

      // Read content from TinyMCE if active, otherwise fall back to textarea value
      var contentValue = '';
      if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        contentValue = tinymce.activeEditor.getContent({ format: 'text' });
      } else {
        var contentEl = form.querySelector('[name="content"]');
        if (contentEl) { contentValue = contentEl.value; }
      }

      var data = new FormData();
      data.append('title',      titleEl   ? titleEl.value.trim()   : '');
      data.append('content',    contentValue.trim());
      data.append('excerpt',    excerptEl ? excerptEl.value.trim() : '');
      data.append('dev_notes',  notesEl   ? notesEl.value.trim()   : '');
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/../../api/faq-generate.php') ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (faqEl) { faqEl.value = JSON.stringify(json.faq, null, 2); }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          console.error('[Generate FAQ]', err);
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — FAQ membutuhkan waktu terlalu lama. Coba lagi.'
            : 'Error: ' + (err.message || 'Unknown error.');
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
// ---- Featured image path picker (pages.php only) ----
(function () {
    var modal    = document.getElementById('mce-ml-modal');
    var search   = document.getElementById('mce-ml-search');
    var backdrop = document.getElementById('mce-ml-backdrop');
    var closeBtn = document.getElementById('mce-ml-close');
    if (!modal) { return; }

    // Holds the <input name="featured_image"> that triggered the picker.
    // null means the modal was opened by TinyMCE — leave it alone.
    var _targetInput = null;

    function openPicker(input) {
        _targetInput = input;
        // Reset search so all images are visible (mirrors tinymce-media-picker openModal)
        if (search) {
            search.value = '';
            search.dispatchEvent(new Event('input'));
            search.focus();
        }
        modal.hidden = false;
    }

    function closePicker() {
        _targetInput = null;
        modal.hidden = true;
    }

    // "Choose from Media Library" button click — open modal for path picking
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-pg-img-pick');
        if (!btn) { return; }
        var form  = btn.closest('form');
        var input = form ? form.querySelector('[name="featured_image"]') : null;
        if (!input) { return; }
        openPicker(input);
    });

    // Image item click while in path-picker mode — write data-path, close modal
    document.addEventListener('click', function (e) {
        if (!_targetInput) { return; }          // TinyMCE mode or modal not active
        var item = e.target.closest('.mce-ml-item');
        if (!item || modal.hidden) { return; }
        var path = item.getAttribute('data-path') || '';
        if (path) { _targetInput.value = path; }
        closePicker();
    });

    // Clear _targetInput if the modal is dismissed via backdrop, close button, or Escape
    function onDismiss() { _targetInput = null; }
    if (backdrop) { backdrop.addEventListener('click', onDismiss); }
    if (closeBtn) { closeBtn.addEventListener('click', onDismiss); }
    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { onDismiss(); }
    });
})();
</script>
<script>
// ---- Auto-generate slug from title (pages.php only) ----
(function () {
    function slugify(str) {
        return str
            .toLowerCase()
            .replace(/[àáâãäå]/g, 'a').replace(/[èéêë]/g, 'e')
            .replace(/[ìíîï]/g, 'i').replace(/[òóôõöø]/g, 'o')
            .replace(/[ùúûü]/g, 'u').replace(/ñ/g, 'n').replace(/ç/g, 'c')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s-]+/g, '-');
    }

    document.querySelectorAll('form.form-grid').forEach(function (form) {
        var titleEl = form.querySelector('[name="title"]');
        var slugEl  = form.querySelector('[name="slug"]');
        if (!titleEl || !slugEl) { return; }

        // Start locked when slug already has a value (edit form with existing slug).
        // Start unlocked when slug is empty (create form).
        var locked = slugEl.value.trim() !== '';

        titleEl.addEventListener('input', function () {
            if (locked) { return; }
            slugEl.value = slugify(titleEl.value);
        });

        // First manual keystroke in the slug field locks auto-generation permanently.
        slugEl.addEventListener('input', function () {
            locked = true;
        });
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" crossorigin="anonymous"></script>
<script>
(function () {
  var contentField = document.querySelector('textarea[name="content"]');
  if (!contentField) {
    return;
  }

  tinymce.init({
    license_key: 'gpl',
    selector: 'textarea[name="content"]',
    height: 360,
    menubar: false,
    branding: false,
    promotion: false,
    readonly: false,
    plugins: 'lists link image table code',
    toolbar:
      'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | code',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    automatic_uploads: false,
    images_upload_url: false,
    paste_data_images: false,
    image_description: false,
    content_style: window.wpmMlContentStyle || '',
    link_default_target: '_blank',
    link_assume_external_targets: true,
    image_advtab: true,
    image_class_list: [
      { title: 'Center image (default)', value: 'img-center' },
      { title: 'Full width image',       value: 'img-full'   },
      { title: 'Small left image',       value: 'img-left'   },
      { title: 'Small right image',      value: 'img-right'  },
    ],
    file_picker_types: 'image',
    file_picker_callback: window.wpmMlPicker,
    setup: function (editor) {
      if (window.wpmMlSetupEditor) { window.wpmMlSetupEditor(editor); }
      editor.on('change input undo redo', function () {
        editor.save();
      });
    },
  });

  document.querySelectorAll('form.form-grid').forEach(function (form) {
    if (!form.querySelector('textarea[name="content"]')) {
      return;
    }
    form.addEventListener('submit', function () {
      if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
      }
    });
  });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
