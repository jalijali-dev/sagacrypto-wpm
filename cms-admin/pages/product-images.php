<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/upload.php';

$pageTitle  = 'Product Images';
$currentNav = 'product-images';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Product Images', 'href' => ''],
];

$selfUrl     = 'product-images.php';
$projectRoot = CMS_PROJECT_ROOT;

$pi_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

// Validates product_id and sort_order only.
// Image path validation is handled per-action after upload processing.
$pi_validate = static function (string $productIdRaw, string $sortOrderRaw): ?string {
    if ($productIdRaw === '' || (int) $productIdRaw <= 0) {
        return 'Product is required.';
    }
    if ($sortOrderRaw !== '' && !is_numeric($sortOrderRaw)) {
        return 'Sort order must be a number.';
    }
    return null;
};

// Shared upload spec for both create and update actions.
$uploadSpec = [
    'image_file' => [
        'path_field' => 'image_path',
        'label'      => 'Image',
        'disk_dir'   => 'uploads/product-images',
        'web_prefix' => '/uploads/product-images/',
        'max_bytes'  => 5 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $pi_redirect('Invalid image record.', 'error');
        }

        // Fetch the stored path before deleting so we can unlink the file afterwards.
        $delStmt = $pdo->prepare('SELECT image_path FROM product_images WHERE id = :id LIMIT 1');
        $delStmt->execute(['id' => $deleteId]);
        $delRow = $delStmt->fetch();

        $delete = $pdo->prepare('DELETE FROM product_images WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $pi_redirect('Image record not found or already deleted.', 'error');
        }

        // Unlink only if the file was uploaded through this module.
        // Never touch sample data paths or anything outside /uploads/product-images/.
        if (is_array($delRow)) {
            $delPath = (string) ($delRow['image_path'] ?? '');
            if ($delPath !== '' && str_starts_with($delPath, '/uploads/product-images/')) {
                $diskPath = $projectRoot . $delPath;
                if (is_file($diskPath)) {
                    @unlink($diskPath);
                }
            }
        }

        $pi_redirect('Product image deleted successfully.');
    }

    $productId    = (int) ($_POST['product_id'] ?? 0);
    $altText      = trim((string) ($_POST['alt_text'] ?? ''));
    $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));

    $validationError = $pi_validate((string) $productId, $sortOrderRaw);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $pi_redirect($validationError, 'error', $errorQuery);
    }

    if ($action === 'create') {
        $uploadResult = cms_process_file_uploads($uploadSpec, ['image_path' => ''], $projectRoot);
        if ($uploadResult['errors'] !== []) {
            $pi_redirect(implode(' ', $uploadResult['errors']), 'error');
        }
        $imagePath = $uploadResult['paths']['image_path'];
        if ($imagePath === '') {
            $pi_redirect('Image file is required.', 'error');
        }

        $insert = $pdo->prepare(
            'INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
             VALUES (:product_id, :image_path, :alt_text, :sort_order)'
        );
        $insert->execute([
            'product_id' => $productId,
            'image_path' => $imagePath,
            'alt_text'   => $altText,
            'sort_order' => $sortOrderRaw === '' ? 0 : (int) $sortOrderRaw,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $pi_redirect('Product image created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $pi_redirect('Invalid image record.', 'error');
        }

        // Fetch current path so we can keep it if no new file is uploaded,
        // and queue it for deletion if it gets replaced.
        $existingStmt = $pdo->prepare(
            'SELECT image_path FROM product_images WHERE id = :id LIMIT 1'
        );
        $existingStmt->execute(['id' => $updateId]);
        $existingRow = $existingStmt->fetch() ?: null;
        if ($existingRow === null) {
            $pi_redirect('Image record not found.', 'error', 'edit=' . $updateId);
        }
        $currentImagePath = (string) ($existingRow['image_path'] ?? '');

        $uploadResult = cms_process_file_uploads(
            $uploadSpec,
            ['image_path' => $currentImagePath],
            $projectRoot
        );
        if ($uploadResult['errors'] !== []) {
            $pi_redirect(implode(' ', $uploadResult['errors']), 'error', 'edit=' . $updateId);
        }
        // If no new file was submitted the helper returns $currentImagePath unchanged.
        $imagePath = $uploadResult['paths']['image_path'];

        $update = $pdo->prepare(
            'UPDATE product_images
             SET product_id = :product_id, image_path = :image_path,
                 alt_text = :alt_text, sort_order = :sort_order
             WHERE id = :id'
        );
        $update->execute([
            'product_id' => $productId,
            'image_path' => $imagePath,
            'alt_text'   => $altText,
            'sort_order' => $sortOrderRaw === '' ? 0 : (int) $sortOrderRaw,
            'id'         => $updateId,
        ]);

        // Remove the old file from disk only if it was replaced and lives
        // under /uploads/product-images/ — the helper only queues paths that
        // start with web_prefix, so sample-data paths are never touched.
        foreach (array_unique($uploadResult['delete_after']) as $oldDisk) {
            if (is_file($oldDisk)) {
                @unlink($oldDisk);
            }
        }

        $pi_redirect('Product image updated successfully.', 'success', 'edit=' . $updateId);
    }

    $pi_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$productOptions = $pdo->query(
    'SELECT id, name FROM products ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$listStmt = $pdo->query(
    'SELECT i.id, i.product_id, i.image_path, i.alt_text, i.sort_order, p.name AS product_name
     FROM product_images i
     LEFT JOIN products p ON p.id = i.product_id
     ORDER BY i.sort_order ASC, i.id DESC'
);
$images = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, product_id, image_path, alt_text, sort_order
         FROM product_images WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Image record not found.'];
        $editId = 0;
    }
}

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<!-- upload-box styles: admin.css -->
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Product images</h2>
            <p class="section-lead">Gallery images per product.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#image-form') ?>">Attach images</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel pi-records-panel">
            <div class="panel__head">
                <h3 class="panel__title">Image records</h3>
                <span class="panel__meta"><?= count($images) ?> image(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Alt text</th>
                            <th>Sort</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($images === []) : ?>
                            <tr><td colspan="4" class="muted">No product images yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($images as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'product_name')) ?></td>
                                <td><?= cms_esc($val($row, 'alt_text')) ?></td>
                                <td><?= cms_esc($val($row, 'sort_order')) ?></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this image?');">
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

        <div class="panel" id="image-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit image' : 'New image' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>" enctype="multipart/form-data">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>

                <label class="field">Product
                    <select name="product_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($productOptions as $prod) : ?>
                            <option value="<?= (int) $prod['id'] ?>"<?= $editRow && (int) $editRow['product_id'] === (int) $prod['id'] ? ' selected' : '' ?>>
                                <?= cms_esc((string) $prod['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="cms-path-upload">
                    <?php if ($editRow && $val($editRow, 'image_path') !== '') : ?>
                        <?php $currentSrc = app_asset_preview_url($val($editRow, 'image_path')); ?>
                        <p class="cms-path-upload__hint">Current image — upload a new file to replace it</p>
                        <div class="cms-path-upload__box">
                            <img class="cms-path-upload__preview"
                                 src="<?= cms_esc($currentSrc) ?>"
                                 alt="<?= cms_esc($val($editRow, 'alt_text')) ?>"
                                 onerror="this.hidden=true">
                        </div>
                        <p class="cms-path-upload__path"><code><?= cms_esc($val($editRow, 'image_path')) ?></code></p>
                    <?php else : ?>
                        <p class="cms-path-upload__hint">Upload an image file (JPG, PNG or WebP, max 5 MB)</p>
                    <?php endif; ?>

                    <label class="field"><?= $editRow ? 'Replace image' : 'Image file' ?>
                        <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp"<?= $editRow ? '' : ' required' ?>>
                    </label>

                    <div class="cms-path-upload__box" id="pi-preview-box" hidden>
                        <img class="cms-path-upload__preview" id="pi-preview-img" alt="Preview">
                    </div>
                </div>

                <label class="field">Alt text
                    <input type="text" name="alt_text" value="<?= cms_esc($editRow ? $val($editRow, 'alt_text') : '') ?>">
                </label>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>">
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create image' ?></button>
            </form>
        </div>
    </div>
</section>
<script>
(function () {
    var fileInput = document.querySelector('input[name="image_file"]');
    var previewBox = document.getElementById('pi-preview-box');
    var previewImg = document.getElementById('pi-preview-img');
    if (!fileInput || !previewBox || !previewImg) return;
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) {
            var reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewBox.hidden = false;
            };
            reader.readAsDataURL(fileInput.files[0]);
        } else {
            previewImg.src = '';
            previewBox.hidden = true;
        }
    });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
