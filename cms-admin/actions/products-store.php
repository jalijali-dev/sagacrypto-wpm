<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/products.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$redirect = '../pages/products.php';

/**
 * @return array{path: ?string, error: ?string}
 */
function products_process_thumbnail_upload(string $slug, string $projectRoot): array
{
    $specs = [
        'thumbnail_file' => [
            'path_field' => 'thumbnail',
            'label'      => 'Thumbnail',
            'disk_dir'   => 'uploads/products',
            'web_prefix' => 'uploads/products/', // no leading slash — preserves existing stored path format
            'max_bytes'  => 5 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
            'basename'   => $slug,               // slug-based filename, e.g. birthday-cake-a1b2c3d4.png
        ],
    ];

    // Pass an empty current path so the helper never queues an old file for
    // deletion — old thumbnail cleanup remains the caller's responsibility,
    // matching the existing behaviour.
    $result = cms_process_file_uploads($specs, ['thumbnail' => ''], $projectRoot);

    // Error during upload.
    if ($result['errors'] !== []) {
        return ['path' => null, 'error' => implode(' ', $result['errors'])];
    }

    // No file was submitted — path stays empty, nothing to report.
    $newPath = $result['paths']['thumbnail'] ?? '';
    if ($newPath === '') {
        return ['path' => null, 'error' => null];
    }

    return ['path' => $newPath, 'error' => null];
}

function products_redirect(string $message, string $type = 'success', ?string $query = null): void
{
    global $redirect;
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $redirect . ($query ? '?' . $query : ''), true, 302);
    exit;
}

function products_validate(string $categoryIdRaw, string $name, string $slug, string $priceRaw): ?string
{
    if ($categoryIdRaw === '' || (int) $categoryIdRaw <= 0) {
        return 'Category is required.';
    }
    if ($name === '') {
        return 'Name is required.';
    }
    if ($slug === '') {
        return 'Slug is required.';
    }
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        return 'Price must be a number.';
    }

    return null;
}

function products_dup_slug(PDO $pdo, string $slug, ?int $excludeId): ?string
{
    $sql = 'SELECT COUNT(*) FROM products WHERE slug = :slug';
    $params = ['slug' => $slug];
    if ($excludeId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return 'That slug is already in use.';
    }

    return null;
}

$action = (string) ($_POST['action'] ?? '');
if ($action !== 'create' && $action !== 'update') {
    products_redirect('Unknown action.', 'error');
}

$categoryId = (int) ($_POST['category_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$slug = trim((string) ($_POST['slug'] ?? ''));
$shortDescription = trim((string) ($_POST['short_description'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$priceRaw = trim((string) ($_POST['price'] ?? ''));
$thumbnail = '';
$isFeatured = isset($_POST['is_featured']) && (int) $_POST['is_featured'] === 1 ? 1 : 0;
$isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
$sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));
$metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
$metaDescription = trim((string) ($_POST['meta_description'] ?? ''));

$validationError = products_validate((string) $categoryId, $name, $slug, $priceRaw);
if ($validationError !== null) {
    $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
        ? 'edit=' . (int) $_POST['id'] : null;
    products_redirect($validationError, 'error', $errorQuery);
}
if ($sortOrderRaw !== '' && !is_numeric($sortOrderRaw)) {
    products_redirect('Sort order must be a number.', 'error');
}

$existingThumbnail = '';
if ($action === 'update') {
    $keepId = (int) ($_POST['id'] ?? 0);
    if ($keepId <= 0) {
        products_redirect('Invalid product.', 'error');
    }
    $keepStmt = $pdo->prepare('SELECT thumbnail FROM products WHERE id = :id LIMIT 1');
    $keepStmt->execute(['id' => $keepId]);
    $existingRow = $keepStmt->fetch();
    if (!is_array($existingRow)) {
        products_redirect('Product not found.', 'error', 'edit=' . $keepId);
    }
    $existingThumbnail = (string) ($existingRow['thumbnail'] ?? '');
}

$thumbnailUpload = products_process_thumbnail_upload($slug, $projectRoot);
if ($thumbnailUpload['error'] !== null) {
    $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
        ? 'edit=' . (int) $_POST['id'] : null;
    products_redirect($thumbnailUpload['error'], 'error', $errorQuery);
}

// Thumbnail is upload-only (readonly path on form is display-only, not from POST).
if ($thumbnailUpload['path'] !== null) {
    $thumbnail = $thumbnailUpload['path'];
} elseif ($action === 'update') {
    $thumbnail = $existingThumbnail;
} else {
    $thumbnail = '';
}

$payload = [
    'category_id' => $categoryId,
    'name' => $name,
    'slug' => $slug,
    'short_description' => $shortDescription,
    'description' => $description,
    'price' => $priceRaw,
    'thumbnail' => $thumbnail,
    'is_featured' => $isFeatured,
    'is_active' => $isActive,
    'sort_order' => $sortOrderRaw === '' ? 0 : (int) $sortOrderRaw,
    'meta_title' => $metaTitle !== '' ? $metaTitle : null,
    'meta_description' => $metaDescription !== '' ? $metaDescription : null,
];

if ($action === 'create') {
    $dup = products_dup_slug($pdo, $slug, null);
    if ($dup !== null) {
        products_redirect($dup, 'error');
    }
    $insert = $pdo->prepare(
        'INSERT INTO products (
            category_id, name, slug, short_description, description, price, thumbnail,
            is_featured, is_active, sort_order, meta_title, meta_description
        ) VALUES (
            :category_id, :name, :slug, :short_description, :description, :price, :thumbnail,
            :is_featured, :is_active, :sort_order, :meta_title, :meta_description
        )'
    );
    $insert->execute($payload);
    $newId = (int) $pdo->lastInsertId();
    products_redirect('Product created successfully.', 'success', 'edit=' . $newId);
}

$updateId = (int) ($_POST['id'] ?? 0);
if ($updateId <= 0) {
    products_redirect('Invalid product.', 'error');
}
$dup = products_dup_slug($pdo, $slug, $updateId);
if ($dup !== null) {
    products_redirect($dup, 'error', 'edit=' . $updateId);
}
$update = $pdo->prepare(
    'UPDATE products
     SET category_id = :category_id, name = :name, slug = :slug,
         short_description = :short_description, description = :description,
         price = :price, thumbnail = :thumbnail, is_featured = :is_featured,
         is_active = :is_active, sort_order = :sort_order,
         meta_title = :meta_title, meta_description = :meta_description
     WHERE id = :id'
);
$update->execute($payload + ['id' => $updateId]);
products_redirect('Product updated successfully.', 'success', 'edit=' . $updateId);
