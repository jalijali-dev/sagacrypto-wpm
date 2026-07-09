<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$projectRoot = CMS_PROJECT_ROOT;

/**
 * @return array{path: ?string, error: ?string, disk_path: ?string}
 */
$lp_process_landing_image_upload = static function (string $sectionKey, string $projectRoot): array {
    $noUpload = ['path' => null, 'error' => null, 'disk_path' => null];

    if (!isset($_FILES['image_file']) || !is_array($_FILES['image_file'])) {
        return $noUpload;
    }

    $file = $_FILES['image_file'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return $noUpload;
    }

    if ($error !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'Image upload failed (error code ' . $error . ').', 'disk_path' => null];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => null, 'error' => 'Image upload is invalid.', 'disk_path' => null];
    }

    if ($size <= 0) {
        return ['path' => null, 'error' => 'Image file is empty.', 'disk_path' => null];
    }

    $maxBytes = 5 * 1024 * 1024;
    if ($size > $maxBytes) {
        return ['path' => null, 'error' => 'Image exceeds the maximum allowed file size (5 MB).', 'disk_path' => null];
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        return ['path' => null, 'error' => 'Image has a disallowed file extension.', 'disk_path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($tmpName) ?: '';
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if ($detectedMime === '' || !in_array($detectedMime, $allowedMimes, true)) {
        return ['path' => null, 'error' => 'Image has a disallowed file type.', 'disk_path' => null];
    }

    $diskDir = $projectRoot . '/uploads/landing';
    if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
        return ['path' => null, 'error' => 'Image upload folder is not writable.', 'disk_path' => null];
    }

    $sectionSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $sectionKey) ?? '';
    $sectionSlug = trim($sectionSlug, '-');
    if ($sectionSlug === '') {
        $sectionSlug = 'section';
    }

    do {
        $safeFilename = $sectionSlug . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath = $diskDir . '/' . $safeFilename;
    } while (file_exists($targetPath));

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['path' => null, 'error' => 'Image could not be saved.', 'disk_path' => null];
    }

    @chmod($targetPath, 0644);

    return [
        'path' => '/uploads/landing/' . $safeFilename,
        'error' => null,
        'disk_path' => $targetPath,
    ];
};

$pageTitle = 'Landing Page';
$currentNav = 'landing-page';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Landing Page', 'href' => ''],
];

$selfUrl = 'landing-page.php';

$lp_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    $target = $selfUrl . ($query !== null && $query !== '' ? '?' . $query : '');
    header('Location: ' . $target, true, 302);
    exit;
};

$lp_validate = static function (
    string $pageKey,
    string $sectionKey,
    string $sectionType,
    string $sortOrderRaw,
    string $status
): ?string {
    if ($pageKey === '') {
        return 'Page key is required.';
    }
    if ($sectionKey === '') {
        return 'Section key is required.';
    }
    if ($sectionType === '') {
        return 'Section type is required.';
    }
    if ($sortOrderRaw === '' || !is_numeric($sortOrderRaw)) {
        return 'Sort order must be a number.';
    }
    if ((int) $sortOrderRaw < 0) {
        return 'Sort order must be 0 or greater.';
    }
    if (!in_array($status, ['draft', 'published', 'hidden'], true)) {
        return 'Status must be draft, published, or hidden.';
    }

    return null;
};

$lp_duplicate_error = static function (
    PDO $pdo,
    string $pageKey,
    string $sectionKey,
    ?int $excludeId
): ?string {
    $sql = 'SELECT COUNT(*) FROM landing_sections WHERE page_key = :page_key AND section_key = :section_key';
    $params = ['page_key' => $pageKey, 'section_key' => $sectionKey];
    if ($excludeId !== null) {
        $sql .= ' AND landing_section_id != :id';
        $params['id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return 'That page key and section key combination is already in use.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['landing_section_id'] ?? 0);
        if ($deleteId <= 0) {
            $lp_redirect('Invalid landing section.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM landing_sections WHERE landing_section_id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $lp_redirect('Landing section not found or already deleted.', 'error');
        }
        $lp_redirect('Landing section deleted successfully.');
    }

    $pageKey = trim((string) ($_POST['page_key'] ?? ''));
    $sectionKey = trim((string) ($_POST['section_key'] ?? ''));
    $sectionType = trim((string) ($_POST['section_type'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $image = trim((string) ($_POST['image'] ?? ''));
    $buttonText = trim((string) ($_POST['button_text'] ?? ''));
    $buttonUrl = trim((string) ($_POST['button_url'] ?? ''));
    $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));

    $validationError = $lp_validate($pageKey, $sectionKey, $sectionType, $sortOrderRaw, $status);
    if ($validationError !== null) {
        $errorQuery = null;
        if ($action === 'update') {
            $failId = (int) ($_POST['landing_section_id'] ?? 0);
            if ($failId > 0) {
                $errorQuery = 'edit=' . $failId;
            }
        }
        $lp_redirect($validationError, 'error', $errorQuery);
    }

    $sortOrder = (int) $sortOrderRaw;

    $uploadResult = $lp_process_landing_image_upload($sectionKey, $projectRoot);
    if ($uploadResult['error'] !== null) {
        if ($uploadResult['disk_path'] !== null && is_file($uploadResult['disk_path'])) {
            @unlink($uploadResult['disk_path']);
        }
        $errorQuery = null;
        if ($action === 'update') {
            $failId = (int) ($_POST['landing_section_id'] ?? 0);
            if ($failId > 0) {
                $errorQuery = 'edit=' . $failId;
            }
        }
        $lp_redirect($uploadResult['error'], 'error', $errorQuery);
    }

    if ($uploadResult['path'] !== null) {
        $image = $uploadResult['path'];
    } elseif ($action === 'update') {
        $keepId = (int) ($_POST['landing_section_id'] ?? 0);
        if ($keepId > 0 && $image === '') {
            $keepStmt = $pdo->prepare('SELECT image FROM landing_sections WHERE landing_section_id = :id LIMIT 1');
            $keepStmt->execute(['id' => $keepId]);
            $existingImage = (string) ($keepStmt->fetchColumn() ?: '');
            if ($existingImage !== '') {
                $image = $existingImage;
            }
        }
    }

    $newImageDiskPath = $uploadResult['disk_path'];

    $payload = [
        'page_key' => $pageKey,
        'section_key' => $sectionKey,
        'section_type' => $sectionType,
        'title' => $title,
        'subtitle' => $subtitle,
        'content' => $content,
        'image' => $image,
        'button_text' => $buttonText,
        'button_url' => $buttonUrl,
        'sort_order' => $sortOrder,
        'status' => $status,
    ];

    if ($action === 'create') {
        $dupError = $lp_duplicate_error($pdo, $pageKey, $sectionKey, null);
        if ($dupError !== null) {
            $lp_redirect($dupError, 'error');
        }

        $insert = $pdo->prepare(
            'INSERT INTO landing_sections (
                page_key, section_key, section_type, title, subtitle, content, image,
                button_text, button_url, sort_order, status, created_at, updated_at
            ) VALUES (
                :page_key, :section_key, :section_type, :title, :subtitle, :content, :image,
                :button_text, :button_url, :sort_order, :status, NOW(), NOW()
            )'
        );
        try {
            $insert->execute($payload);
        } catch (PDOException) {
            if ($newImageDiskPath !== null && is_file($newImageDiskPath)) {
                @unlink($newImageDiskPath);
            }
            $lp_redirect('Landing section could not be created. Please try again.', 'error');
        }
        $newId = (int) $pdo->lastInsertId();
        $lp_redirect('Landing section created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['landing_section_id'] ?? 0);
        if ($updateId <= 0) {
            $lp_redirect('Invalid landing section.', 'error');
        }

        $dupError = $lp_duplicate_error($pdo, $pageKey, $sectionKey, $updateId);
        if ($dupError !== null) {
            $lp_redirect($dupError, 'error', 'edit=' . $updateId);
        }

        $update = $pdo->prepare(
            'UPDATE landing_sections
             SET page_key = :page_key,
                 section_key = :section_key,
                 section_type = :section_type,
                 title = :title,
                 subtitle = :subtitle,
                 content = :content,
                 image = :image,
                 button_text = :button_text,
                 button_url = :button_url,
                 sort_order = :sort_order,
                 status = :status,
                 updated_at = NOW()
             WHERE landing_section_id = :id'
        );
        try {
            $update->execute($payload + ['id' => $updateId]);
        } catch (PDOException) {
            if ($newImageDiskPath !== null && is_file($newImageDiskPath)) {
                @unlink($newImageDiskPath);
            }
            $lp_redirect('Landing section could not be updated. Please try again.', 'error', 'edit=' . $updateId);
        }
        if ($update->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT landing_section_id FROM landing_sections WHERE landing_section_id = :id LIMIT 1');
            $exists->execute(['id' => $updateId]);
            if (!$exists->fetch()) {
                if ($newImageDiskPath !== null && is_file($newImageDiskPath)) {
                    @unlink($newImageDiskPath);
                }
                $lp_redirect('Landing section not found.', 'error');
            }
        }
        $lp_redirect('Landing section updated successfully.', 'success', 'edit=' . $updateId);
    }

    $lp_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query(
    'SELECT landing_section_id, section_key, section_type, title, sort_order, status, updated_at
     FROM landing_sections
     ORDER BY sort_order ASC, landing_section_id ASC'
);
$sections = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT landing_section_id, page_key, section_key, section_type, title, subtitle, content,
                image, button_text, button_url, sort_order, status
         FROM landing_sections
         WHERE landing_section_id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Landing section not found.'];
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

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
};

$lp_render_image_field = static function (string $currentImage): void {
    $previewUrl = app_asset_preview_url($currentImage);
    $hasPreview = $previewUrl !== '';
    ?>
    <div class="cms-path-upload" style="grid-column: 1 / -1;">
        <label class="field">Image path
            <input
                type="text"
                name="image"
                class="cms-path-upload__input"
                value="<?= cms_esc($currentImage); ?>"
                placeholder="/uploads/landing/example.webp"
            >
        </label>
        <p class="cms-path-upload__hint">Upload destination: <code>/uploads/landing/</code></p>
        <div class="cms-path-upload__box">
            <img
                class="cms-path-upload__preview"
                alt="Landing section image preview"
                <?= $hasPreview ? 'src="' . cms_esc($previewUrl) . '"' : 'hidden'; ?>
            >
            <input
                type="file"
                name="image_file"
                class="cms-path-upload__file"
                accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
            >
        </div>
        <div class="cms-field-hint" role="note">
            <ul>
                <li>Allowed: JPG, PNG, WEBP — max 5 MB</li>
                <li>Hero/About image: 900×900 px or 1200×900 px</li>
                <li>Uploaded files are saved as <code>/uploads/landing/section-key-random.ext</code></li>
            </ul>
        </div>
    </div>
    <?php
};

$statusPill = static function (string $status): string {
    $normalized = strtolower($status);
    if ($normalized === 'published') {
        return 'ok';
    }
    if ($normalized === 'hidden') {
        return 'muted';
    }

    return 'muted';
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
/* preview height override — admin.css default is 120px */
.cms-path-upload__preview { max-height: 160px; }
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Landing page content</h2>
            <p class="section-lead">Hero, banners, and homepage blocks for the storefront landing page.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#create-landing-section') ?>">New landing section</a>
        </div>
    </div>

    <?php if ($editRow) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Edit landing section</h3>
            <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" enctype="multipart/form-data">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="landing_section_id" value="<?= (int) $editRow['landing_section_id'] ?>">
            <label class="field">Page key
                <input type="text" name="page_key" value="<?= cms_esc($val($editRow, 'page_key')) ?>" required>
            </label>
            <label class="field">Section key
                <input type="text" name="section_key" value="<?= cms_esc($val($editRow, 'section_key')) ?>" required>
            </label>
            <label class="field">Section type
                <input type="text" name="section_type" value="<?= cms_esc($val($editRow, 'section_type')) ?>" required>
            </label>
            <label class="field">Title
                <input type="text" name="title" value="<?= cms_esc($val($editRow, 'title')) ?>">
            </label>
            <label class="field">Sort order
                <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($val($editRow, 'sort_order')) ?>" required>
            </label>
            <?php $editStatus = strtolower($val($editRow, 'status')); ?>
            <label class="field">Status
                <select name="status" required>
                    <option value="draft"<?= $editStatus === 'draft' ? ' selected' : '' ?>>draft</option>
                    <option value="published"<?= $editStatus === 'published' ? ' selected' : '' ?>>published</option>
                    <option value="hidden"<?= $editStatus === 'hidden' ? ' selected' : '' ?>>hidden</option>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Subtitle
                <textarea name="subtitle" rows="3"><?= cms_esc($val($editRow, 'subtitle')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="landing-section-content" rows="6"><?= cms_esc($val($editRow, 'content')) ?></textarea>
            </label>
            <?php $lp_render_image_field($val($editRow, 'image')); ?>
            <label class="field">Button text
                <input type="text" name="button_text" value="<?= cms_esc($val($editRow, 'button_text')) ?>">
            </label>
            <label class="field" style="grid-column: 1 / -1;">Button URL
                <input type="text" name="button_url" value="<?= cms_esc($val($editRow, 'button_url')) ?>">
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php else : ?>
    <div class="panel" id="create-landing-section">
        <div class="panel__head">
            <h3 class="panel__title">New landing section</h3>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" enctype="multipart/form-data">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Page key
                <input type="text" name="page_key" value="home" required>
            </label>
            <label class="field">Section key
                <input type="text" name="section_key" required>
            </label>
            <label class="field">Section type
                <input type="text" name="section_type" required>
            </label>
            <label class="field">Title
                <input type="text" name="title">
            </label>
            <label class="field">Sort order
                <input type="number" name="sort_order" min="0" step="1" value="0" required>
            </label>
            <label class="field">Status
                <select name="status" required>
                    <option value="draft" selected>draft</option>
                    <option value="published">published</option>
                    <option value="hidden">hidden</option>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Subtitle
                <textarea name="subtitle" rows="3"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="landing-section-content" rows="6"></textarea>
            </label>
            <?php $lp_render_image_field(''); ?>
            <label class="field">Button text
                <input type="text" name="button_text">
            </label>
            <label class="field" style="grid-column: 1 / -1;">Button URL
                <input type="text" name="button_url">
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Create landing section</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All landing sections</h3>
            <span class="panel__meta"><?= count($sections) ?> section(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Sort</th>
                        <th>Section Key</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Updated At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sections === []) : ?>
                        <tr>
                            <td colspan="7" class="muted">No landing sections yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($sections as $row) : ?>
                        <?php $rowId = (int) $row['landing_section_id']; ?>
                        <tr>
                            <td><?= cms_esc($val($row, 'sort_order')) ?></td>
                            <td><code><?= cms_esc($val($row, 'section_key')) ?></code></td>
                            <td><?= cms_esc($val($row, 'section_type')) ?></td>
                            <td><?= cms_esc($val($row, 'title')) ?></td>
                            <td><span class="pill pill--<?= cms_esc($statusPill($val($row, 'status'))) ?>"><?= cms_esc($val($row, 'status')) ?></span></td>
                            <td><?= cms_esc($formatDt($row['updated_at'] ?? null)) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this landing section?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="landing_section_id" value="<?= $rowId ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/tinymce-media-picker.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" crossorigin="anonymous"></script>
<script>
(function () {
  var cmsBaseUrl = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
  function previewUrl(path) {
    path = (path || '').trim();
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    return cmsBaseUrl + path.replace(/^\/+/, '');
  }
  document.querySelectorAll('.cms-path-upload').forEach(function (wrap) {
    var pathInput = wrap.querySelector('.cms-path-upload__input');
    var preview = wrap.querySelector('.cms-path-upload__preview');
    var fileInput = wrap.querySelector('.cms-path-upload__file');
    if (!pathInput || !preview) return;
    var objectUrl = '';
    function showPreview(url) {
      if (!url) {
        preview.hidden = true;
        preview.removeAttribute('src');
        return;
      }
      preview.src = url;
      preview.hidden = false;
      preview.onerror = function () {
        preview.hidden = true;
      };
    }
    function syncFromPath() {
      if (objectUrl) return;
      showPreview(previewUrl(pathInput.value));
    }
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (objectUrl) {
          URL.revokeObjectURL(objectUrl);
          objectUrl = '';
        }
        if (!fileInput.files || !fileInput.files[0]) {
          syncFromPath();
          return;
        }
        objectUrl = URL.createObjectURL(fileInput.files[0]);
        showPreview(objectUrl);
      });
    }
    pathInput.addEventListener('input', function () {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = '';
      }
      syncFromPath();
    });
    syncFromPath();
  });
})();
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
