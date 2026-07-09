<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Product Categories';
$currentNav = 'product-categories';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Product Categories', 'href' => ''],
];

$selfUrl = 'product-categories.php';

$pc_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$pc_validate = static function (string $name, string $slug, string $sortOrderRaw): ?string {
    if ($name === '') {
        return 'Name is required.';
    }
    if ($slug === '') {
        return 'Slug is required.';
    }
    if ($sortOrderRaw === '' || !is_numeric($sortOrderRaw)) {
        return 'Sort order must be a number.';
    }

    return null;
};

$pc_dup_slug = static function (PDO $pdo, string $slug, ?int $excludeId): ?string {
    $sql = 'SELECT COUNT(*) FROM product_categories WHERE slug = :slug';
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
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $pc_redirect('Invalid category.', 'error');
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
        $countStmt->execute(['id' => $deleteId]);
        if ((int) $countStmt->fetchColumn() > 0) {
            $pc_redirect('Cannot delete: category still has products.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM product_categories WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $pc_redirect('Category not found or already deleted.', 'error');
        }
        $pc_redirect('Category deleted successfully.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));
    $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    $validationError = $pc_validate($name, $slug, $sortOrderRaw);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $pc_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'is_active' => $isActive,
        'sort_order' => (int) $sortOrderRaw,
    ];

    if ($action === 'create') {
        $dup = $pc_dup_slug($pdo, $slug, null);
        if ($dup !== null) {
            $pc_redirect($dup, 'error');
        }
        $insert = $pdo->prepare(
            'INSERT INTO product_categories (name, slug, description, is_active, sort_order)
             VALUES (:name, :slug, :description, :is_active, :sort_order)'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $pc_redirect('Category created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $pc_redirect('Invalid category.', 'error');
        }
        $dup = $pc_dup_slug($pdo, $slug, $updateId);
        if ($dup !== null) {
            $pc_redirect($dup, 'error', 'edit=' . $updateId);
        }
        $update = $pdo->prepare(
            'UPDATE product_categories
             SET name = :name, slug = :slug, description = :description,
                 is_active = :is_active, sort_order = :sort_order
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $pc_redirect('Category updated successfully.', 'success', 'edit=' . $updateId);
    }

    $pc_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query(
    'SELECT c.id, c.name, c.slug, c.is_active, c.sort_order,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM product_categories c
     ORDER BY c.sort_order ASC, c.id DESC'
);
$categories = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, name, slug, description, is_active, sort_order
         FROM product_categories WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Category not found.'];
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
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Product categories</h2>
            <p class="section-lead">Taxonomy for catalog — sort order and visibility.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#category-form') ?>">Add category</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Categories</h3>
                <span class="panel__meta"><?= count($categories) ?> category(ies)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Sort</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($categories === []) : ?>
                            <tr><td colspan="6" class="muted">No categories yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'name')) ?></td>
                                <td><code><?= cms_esc($val($row, 'slug')) ?></code></td>
                                <td><?= (int) ($row['product_count'] ?? 0) ?></td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= cms_esc($val($row, 'sort_order')) ?></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this category?');">
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

        <div class="panel" id="category-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit category' : 'New category' ?></h3>
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
                <label class="field">Name
                    <input type="text" name="name" value="<?= cms_esc($editRow ? $val($editRow, 'name') : '') ?>" required>
                </label>
                <label class="field">Slug
                    <input type="text" name="slug" value="<?= cms_esc($editRow ? $val($editRow, 'slug') : '') ?>" required>
                </label>
                <label class="field">Description
                    <textarea name="description" rows="4"><?= cms_esc($editRow ? $val($editRow, 'description') : '') ?></textarea>
                </label>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>" required>
                </label>
                <label class="field">Status
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create category' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
