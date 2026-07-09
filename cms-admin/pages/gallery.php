<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

$pageTitle = 'Gallery';
$currentNav = 'gallery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Gallery', 'href' => ''],
];

$selfUrl = 'gallery.php';

/**
 * Auto-migration: this page needs a handful of columns on `gallery` and
 * `media_library` that older databases won't have yet (media_id,
 * description, category, is_featured, status / mime_type, file_size_kb,
 * is_active, updated_at). Instead of erroring out and pointing the admin
 * at migrate-gallery.php / migrate-media-library.php, just add whatever is
 * missing right here, every time the page loads. cms_ensure_column() only
 * ALTERs when a column is truly absent, so this never duplicates columns
 * and is safe to run on every request.
 */
$gallerySchemaError = null;
try {
    cms_ensure_column($pdo, 'gallery', 'media_id', 'INT(10) UNSIGNED DEFAULT NULL AFTER `id`');
    cms_ensure_column($pdo, 'gallery', 'description', 'TEXT DEFAULT NULL AFTER `title`');
    cms_ensure_column($pdo, 'gallery', 'category', 'VARCHAR(100) DEFAULT NULL AFTER `description`');
    cms_ensure_column($pdo, 'gallery', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `sort_order`');
    cms_ensure_column($pdo, 'gallery', 'status', "VARCHAR(20) NOT NULL DEFAULT 'published' AFTER `is_featured`");

    cms_ensure_column($pdo, 'media_library', 'mime_type', 'VARCHAR(100) DEFAULT NULL AFTER `file_type`');
    cms_ensure_column($pdo, 'media_library', 'file_size_kb', 'INT(10) UNSIGNED DEFAULT NULL AFTER `mime_type`');
    cms_ensure_column($pdo, 'media_library', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size_kb`');
    cms_ensure_column($pdo, 'media_library', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
} catch (Throwable $e) {
    // Only happens if the DB user lacks ALTER privilege or something else
    // unexpected is wrong — surfaced as a clear alert below (with a manual
    // migration link) rather than a Fatal Error.
    $gallerySchemaError = $e->getMessage();
}

$gl_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$gl_validate = static function (string $title, string $status, string $sortOrderRaw): ?string {
    if ($title === '') {
        return 'Title is required.';
    }
    if ($status === '') {
        return 'Status is required.';
    }
    if ($sortOrderRaw === '' || !is_numeric($sortOrderRaw)) {
        return 'Sort order must be a number.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $gl_redirect('Invalid gallery item.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM gallery WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $gl_redirect('Gallery item not found or already deleted.', 'error');
        }
        $gl_redirect('Gallery item deleted successfully.');
    }

    $mediaIdRaw = trim((string) ($_POST['media_id'] ?? ''));
    $mediaId = $mediaIdRaw === '' ? null : (int) $mediaIdRaw;
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));
    $isFeatured = isset($_POST['is_featured']) && (int) $_POST['is_featured'] === 1 ? 1 : 0;
    $status = trim((string) ($_POST['status'] ?? ''));

    $validationError = $gl_validate($title, $status, $sortOrderRaw);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $gl_redirect($validationError, 'error', $errorQuery);
    }

    if ($mediaId !== null && $mediaId > 0) {
        $mediaCheck = $pdo->prepare('SELECT id FROM media_library WHERE id = :id AND is_active = 1 LIMIT 1');
        $mediaCheck->execute(['id' => $mediaId]);
        if (!$mediaCheck->fetch()) {
            $gl_redirect('Selected media file is invalid or inactive.', 'error');
        }
    }

    $payload = [
        'media_id' => $mediaId,
        'title' => $title,
        'description' => $description,
        'category' => $category,
        'sort_order' => (int) $sortOrderRaw,
        'is_featured' => $isFeatured,
        'status' => $status,
    ];

    if ($action === 'create') {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO gallery (
                    media_id, title, description, category, sort_order, is_featured, status, created_at, updated_at
                ) VALUES (
                    :media_id, :title, :description, :category, :sort_order, :is_featured, :status, NOW(), NOW()
                )'
            );
            $insert->execute($payload);
            $newId = (int) $pdo->lastInsertId();
            $gl_redirect('Gallery item created successfully.', 'success', 'edit=' . $newId);
        } catch (PDOException $e) {
            $gl_redirect('Gagal menyimpan gallery item: ' . $e->getMessage(), 'error');
        }
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $gl_redirect('Invalid gallery item.', 'error');
        }
        try {
            $update = $pdo->prepare(
                'UPDATE gallery
                 SET media_id = :media_id,
                     title = :title,
                     description = :description,
                     category = :category,
                     sort_order = :sort_order,
                     is_featured = :is_featured,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute($payload + ['id' => $updateId]);
            $gl_redirect('Gallery item updated successfully.', 'success', 'edit=' . $updateId);
        } catch (PDOException $e) {
            $gl_redirect('Gagal memperbarui gallery item: ' . $e->getMessage(), 'error');
        }
    }

    $gl_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

// If the auto-migration above couldn't run (e.g. the DB user lacks ALTER
// privilege), show ONE clear, clickable alert instead of a wall of raw
// PDO exceptions from every query below. 'raw' => true means this exact
// HTML renders as-is (a real clickable link), not escaped text.
if ($gallerySchemaError !== null) {
    $alerts[] = [
        'type' => 'error',
        'raw' => true,
        'message' => 'Gallery belum bisa dipakai sepenuhnya: skema database belum lengkap dan '
            . 'perbaikan otomatis gagal dijalankan (' . cms_esc($gallerySchemaError) . '). '
            . 'Jalankan migration manual: <a href="../migrate-gallery.php">Jalankan Migration Gallery</a> '
            . 'lalu <a href="../migrate-media-library.php">Jalankan Migration Media Library</a>.',
    ];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

try {
    $mediaOptions = $pdo->query(
        'SELECT id, file_name, file_path, alt_text, caption, mime_type
         FROM media_library WHERE is_active = 1 ORDER BY file_name ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // Fallback for missing columns (schema migration not yet applied).
    $mediaOptions = $pdo->query(
        'SELECT id, file_name, file_path, alt_text, caption
         FROM media_library ORDER BY file_name ASC, id ASC'
    )->fetchAll();
}

try {
    $listStmt = $pdo->query(
        'SELECT g.id, g.title, g.category, g.sort_order, g.is_featured, g.status, g.updated_at,
                m.file_name AS media_file_name
         FROM gallery g
         LEFT JOIN media_library m ON m.id = g.media_id
         ORDER BY g.sort_order ASC, g.id DESC'
    );
    $galleryItems = $listStmt->fetchAll();
} catch (PDOException $e) {
    $galleryItems = [];
    if ($gallerySchemaError === null) {
        $alerts[] = [
            'type' => 'error',
            'message' => 'Gagal memuat daftar gallery: ' . $e->getMessage(),
        ];
    }
}

if ($editId > 0) {
    try {
        $editStmt = $pdo->prepare(
            'SELECT id, media_id, title, description, category, sort_order, is_featured, status
             FROM gallery WHERE id = :id LIMIT 1'
        );
        $editStmt->execute(['id' => $editId]);
        $editRow = $editStmt->fetch() ?: null;
        if ($editRow === null) {
            $alerts[] = ['type' => 'error', 'message' => 'Gallery item not found.'];
            $editId = 0;
        }
    } catch (PDOException $e) {
        $editRow = null;
        $editId  = 0;
        if ($gallerySchemaError === null) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Tidak dapat memuat data item: ' . $e->getMessage(),
            ];
        }
    }
}

// Existing distinct categories for the datalist autocomplete suggestion.
try {
    $categoryOptions = $pdo->query(
        'SELECT DISTINCT category
         FROM gallery
         WHERE category IS NOT NULL AND category <> \'\'
         ORDER BY category ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categoryOptions = [];
}

// Resolve the currently-selected media record for the picker widget (edit mode).
$selectedMedia = null;
if ($editRow !== null && (int) ($editRow['media_id'] ?? 0) > 0) {
    $selId = (int) $editRow['media_id'];
    foreach ($mediaOptions as $mOpt) {
        if ((int) $mOpt['id'] === $selId) {
            $selectedMedia = $mOpt;
            break;
        }
    }
}

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
/* ---- Media picker widget (inline in form) ---- */
.gl-media-picker { display:flex; flex-direction:column; gap:8px; }
.gl-media-picker__summary {
    font-size:13px; color:var(--text);
    padding:7px 10px;
    background:var(--surface-soft);
    border:1px solid var(--line);
    border-radius:8px;
    min-height:34px;
    word-break:break-all;
    line-height:1.5;
}
.gl-media-picker__preview {
    display:block; max-width:100%; max-height:90px;
    border-radius:8px; object-fit:contain;
    border:1px solid var(--line);
}
.gl-media-picker__preview[hidden] { display:none !important; }
.gl-media-picker__actions { display:flex; gap:8px; flex-wrap:wrap; }

/* ---- Gallery modal overlay ---- */
#gl-media-modal {
    position:fixed; inset:0; z-index:500;
    display:flex; align-items:center; justify-content:center;
}
#gl-media-modal[hidden] { display:none !important; }
#gl-media-backdrop {
    position:absolute; inset:0;
    background:var(--modal-overlay);
}
#gl-media-dialog {
    position:relative;
    background:var(--surface);
    border:1px solid var(--modal-border);
    border-radius:18px;
    box-shadow:var(--modal-shadow);
    width:min(700px,95vw);
    max-height:82vh;
    display:flex; flex-direction:column;
    overflow:hidden;
}
#gl-media-dialog-head {
    display:flex; align-items:center; gap:10px;
    padding:14px 16px 12px;
    border-bottom:1px solid var(--line-subtle);
    flex-shrink:0;
}
#gl-media-dialog-head h3 {
    margin:0; font-size:15px; font-weight:700;
    flex-shrink:0; white-space:nowrap;
    color:var(--text);
}
#gl-media-search {
    flex:1; min-width:0;
    padding:7px 11px;
    border:1px solid var(--line);
    border-radius:8px;
    background:var(--input-bg);
    color:var(--text);
    font-size:13px;
    font-family:inherit;
}
#gl-media-list { overflow-y:auto; flex:1; padding:4px 0; }
.gl-media-item {
    display:flex; align-items:center; gap:12px;
    padding:9px 16px;
    border-bottom:1px solid var(--line-subtle);
    transition:background .12s ease;
    cursor:pointer;
}
.gl-media-item:hover { background:var(--navlink-hover-bg); }
.gl-media-item[hidden] { display:none !important; }
.gl-media-item__thumb {
    flex-shrink:0; width:52px; height:52px;
    object-fit:cover; border-radius:8px;
    border:1px solid var(--line);
}
.gl-media-item__thumb--ph {
    display:flex; align-items:center; justify-content:center;
    font-size:20px; background:var(--accent-soft);
}
.gl-media-item__info {
    flex:1; min-width:0;
    display:flex; flex-direction:column; gap:1px;
    font-size:12px;
}
.gl-media-item__info strong { font-size:13px; word-break:break-all; color:var(--text); }
.gl-media-item__info span  { color:var(--muted); word-break:break-all; }
.gl-media-empty { padding:20px; text-align:center; color:var(--muted); }
</style>

<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Gallery</h2>
            <p class="section-lead">Storefront showcase items linked to media library files.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#gallery-form') ?>">Add gallery item</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Gallery items</h3>
                <span class="panel__meta"><?= count($galleryItems) ?> item(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Media</th>
                            <th>Category</th>
                            <th>Sort</th>
                            <th>Featured</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($galleryItems === []) : ?>
                            <tr><td colspan="7" class="muted">No gallery items yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($galleryItems as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'title')) ?></td>
                                <td><?= cms_esc($val($row, 'media_file_name') !== '' ? $val($row, 'media_file_name') : '—') ?></td>
                                <td><?= cms_esc($val($row, 'category')) ?></td>
                                <td><?= cms_esc($val($row, 'sort_order')) ?></td>
                                <td>
                                    <?php if ((int) ($row['is_featured'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Featured</span>
                                    <?php else : ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="pill pill--muted"><?= cms_esc($val($row, 'status')) ?></span></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this gallery item?');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" id="gallery-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit gallery item' : 'New gallery item' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <?php
                $selPreviewSrc = $selectedMedia
                    ? app_asset_preview_url((string) ($selectedMedia['file_path'] ?? ''))
                    : '';
                ?>
                <div class="field">
                    <span style="font-size:13px;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">Media file</span>
                    <input type="hidden" name="media_id" id="gl-media-id"
                           value="<?= $selectedMedia ? (int) $selectedMedia['id'] : '' ?>">
                    <div class="gl-media-picker">
                        <div class="gl-media-picker__summary" id="gl-media-summary">
                            <?php if ($selectedMedia) : ?>
                                <?= cms_esc((string) $selectedMedia['file_name']) ?>
                            <?php else : ?>
                                <span class="muted">No media selected</span>
                            <?php endif; ?>
                        </div>
                        <img id="gl-media-preview"
                             class="gl-media-picker__preview"
                             src="<?= cms_esc($selPreviewSrc) ?>"
                             alt=""
                             <?= $selPreviewSrc === '' ? 'hidden' : '' ?>
                             onerror="this.hidden=true">
                        <div class="gl-media-picker__actions">
                            <button type="button"
                                    class="admin-btn admin-btn--secondary admin-btn--sm"
                                    id="gl-media-open">Choose from Media Library</button>
                            <button type="button"
                                    class="admin-btn admin-btn--ghost admin-btn--sm"
                                    id="gl-media-clear"
                                    <?= $selectedMedia ? '' : 'hidden' ?>>Clear</button>
                        </div>
                    </div>
                </div>
                <label class="field">Title
                    <input type="text" name="title" value="<?= cms_esc($editRow ? $val($editRow, 'title') : '') ?>" required>
                </label>
                <label class="field">Description
                    <textarea name="description" rows="3"><?= cms_esc($editRow ? $val($editRow, 'description') : '') ?></textarea>
                </label>
                <label class="field">Category
                    <input type="text" name="category"
                           list="gallery-category-list"
                           value="<?= cms_esc($editRow ? $val($editRow, 'category') : '') ?>"
                           placeholder="e.g. cakes, events"
                           autocomplete="off">
                    <datalist id="gallery-category-list">
                        <?php foreach ($categoryOptions as $catOption) : ?>
                            <option value="<?= cms_esc($catOption) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>" required>
                </label>
                <label class="field">Featured
                    <select name="is_featured">
                        <option value="0"<?= !$editRow || (int) ($editRow['is_featured'] ?? 0) === 0 ? ' selected' : '' ?>>No</option>
                        <option value="1"<?= $editRow && (int) ($editRow['is_featured'] ?? 0) === 1 ? ' selected' : '' ?>>Yes</option>
                    </select>
                </label>
                <label class="field">Status
                    <input type="text" name="status" value="<?= cms_esc($editRow ? $val($editRow, 'status') : '') ?>" required placeholder="e.g. published, draft">
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create gallery item' ?></button>
            </form>
        </div>
    </div>
</section>
<!-- =====================================================================
     Media Library picker modal
     Opens when admin clicks "Choose from Media Library".
     Posts gallery.media_id via hidden input — no schema change.
===================================================================== -->
<div id="gl-media-modal" hidden role="dialog" aria-modal="true" aria-labelledby="gl-modal-title">
    <div id="gl-media-backdrop"></div>
    <div id="gl-media-dialog">

        <div id="gl-media-dialog-head">
            <h3 id="gl-modal-title">Choose Media File</h3>
            <input type="search" id="gl-media-search"
                   placeholder="Search by name, path, alt text, caption…"
                   autocomplete="off">
            <button type="button"
                    class="admin-btn admin-btn--sm admin-btn--ghost"
                    id="gl-media-close"
                    aria-label="Close">✕</button>
        </div>

        <div id="gl-media-list">
            <?php if ($mediaOptions === []) : ?>
                <p class="gl-media-empty muted">No active media files found in the library.</p>
            <?php endif; ?>
            <?php foreach ($mediaOptions as $mRow) : ?>
                <?php
                $mId      = (int)    $mRow['id'];
                $mName    = (string) $mRow['file_name'];
                $mPath    = trim((string) ($mRow['file_path'] ?? ''));
                $mAlt     = trim((string) ($mRow['alt_text']  ?? ''));
                $mCaption = trim((string) ($mRow['caption']   ?? ''));
                $mMime    = trim((string) ($mRow['mime_type'] ?? ''));
                $mPreview = app_asset_preview_url($mPath);
                $isImage  = $mPreview !== '' && stripos($mMime, 'image') !== false;
                ?>
                <div class="gl-media-item"
                     data-id="<?= $mId ?>"
                     data-name="<?= cms_esc($mName) ?>"
                     data-path="<?= cms_esc($mPath) ?>"
                     data-alt="<?= cms_esc($mAlt) ?>"
                     data-caption="<?= cms_esc($mCaption) ?>"
                     data-preview="<?= cms_esc($mPreview) ?>">
                    <?php if ($isImage) : ?>
                        <img class="gl-media-item__thumb"
                             src="<?= cms_esc($mPreview) ?>"
                             alt=""
                             loading="lazy"
                             onerror="this.classList.add('gl-media-item__thumb--ph');this.removeAttribute('src');this.textContent='📄'">
                    <?php else : ?>
                        <div class="gl-media-item__thumb gl-media-item__thumb--ph" aria-hidden="true">📄</div>
                    <?php endif; ?>
                    <div class="gl-media-item__info">
                        <strong><?= cms_esc($mName) ?></strong>
                        <?php if ($mPath    !== '') : ?><span><?= cms_esc($mPath) ?></span><?php endif; ?>
                        <?php if ($mAlt     !== '') : ?><span><?= cms_esc($mAlt) ?></span><?php endif; ?>
                        <?php if ($mCaption !== '') : ?><span><?= cms_esc($mCaption) ?></span><?php endif; ?>
                    </div>
                    <button type="button"
                            class="admin-btn admin-btn--sm admin-btn--primary gl-media-select">Select</button>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<script>
(function () {
    var modal    = document.getElementById('gl-media-modal');
    var backdrop = document.getElementById('gl-media-backdrop');
    var searchEl = document.getElementById('gl-media-search');
    var closeBtn = document.getElementById('gl-media-close');
    var openBtn  = document.getElementById('gl-media-open');
    var clearBtn = document.getElementById('gl-media-clear');
    var hiddenId = document.getElementById('gl-media-id');
    var summary  = document.getElementById('gl-media-summary');
    var preview  = document.getElementById('gl-media-preview');
    var items    = document.querySelectorAll('.gl-media-item');

    if (!modal || !openBtn) return;

    /* ---- open / close ---- */
    function openModal() {
        modal.hidden = false;
        if (searchEl) { searchEl.value = ''; filterItems(''); searchEl.focus(); }
    }
    function closeModal() {
        modal.hidden = true;
    }

    /* ---- search filter ---- */
    function filterItems(q) {
        q = q.toLowerCase().trim();
        items.forEach(function (item) {
            if (q === '') { item.hidden = false; return; }
            var haystack = [
                item.getAttribute('data-name')    || '',
                item.getAttribute('data-path')    || '',
                item.getAttribute('data-alt')     || '',
                item.getAttribute('data-caption') || ''
            ].join(' ').toLowerCase();
            item.hidden = haystack.indexOf(q) === -1;
        });
    }

    /* ---- select a media item ---- */
    function selectItem(id, name, previewSrc) {
        hiddenId.value = id;
        summary.textContent = name;
        if (previewSrc) {
            preview.src     = previewSrc;
            preview.hidden  = false;
        } else {
            preview.src    = '';
            preview.hidden = true;
        }
        clearBtn.hidden = false;
        closeModal();
    }

    /* ---- clear selection ---- */
    function clearSelection() {
        hiddenId.value  = '';
        summary.innerHTML = '<span class="muted">No media selected</span>';
        preview.src    = '';
        preview.hidden = true;
        clearBtn.hidden = true;
    }

    /* ---- event wires ---- */
    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { closeModal(); }
    });

    if (searchEl) {
        searchEl.addEventListener('input', function () { filterItems(searchEl.value); });
    }

    items.forEach(function (item) {
        var btn = item.querySelector('.gl-media-select');
        if (!btn) return;
        btn.addEventListener('click', function () {
            selectItem(
                item.getAttribute('data-id'),
                item.getAttribute('data-name'),
                item.getAttribute('data-preview')
            );
        });
    });

    clearBtn.addEventListener('click', clearSelection);
})();
</script>

<?php
require dirname(__DIR__) . '/includes/footer.php';
