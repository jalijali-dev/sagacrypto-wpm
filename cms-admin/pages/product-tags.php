<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Product Tags';
$currentNav = 'product-tags';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Product Tags', 'href' => ''],
];

$selfUrl = 'product-tags.php';

$pt_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$pt_validate = static function (string $name, string $slug): ?string {
    if ($name === '') {
        return 'Name is required.';
    }
    if ($slug === '') {
        return 'Slug is required.';
    }

    return null;
};

$pt_dup_slug = static function (PDO $pdo, string $slug, ?int $excludeId): ?string {
    $sql = 'SELECT COUNT(*) FROM product_tags WHERE slug = :slug';
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
            $pt_redirect('Invalid tag.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM product_tags WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $pt_redirect('Tag not found or already deleted.', 'error');
        }
        $pt_redirect('Tag deleted successfully.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));

    $validationError = $pt_validate($name, $slug);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $pt_redirect($validationError, 'error', $errorQuery);
    }

    $payload = ['name' => $name, 'slug' => $slug];

    if ($action === 'create') {
        $dup = $pt_dup_slug($pdo, $slug, null);
        if ($dup !== null) {
            $pt_redirect($dup, 'error');
        }
        $insert = $pdo->prepare('INSERT INTO product_tags (name, slug) VALUES (:name, :slug)');
        $insert->execute($payload);
        $pt_redirect('Tag created successfully.');
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $pt_redirect('Invalid tag.', 'error');
        }
        $dup = $pt_dup_slug($pdo, $slug, $updateId);
        if ($dup !== null) {
            $pt_redirect($dup, 'error', 'edit=' . $updateId);
        }
        $update = $pdo->prepare('UPDATE product_tags SET name = :name, slug = :slug WHERE id = :id');
        $update->execute($payload + ['id' => $updateId]);
        $pt_redirect('Tag updated successfully.');
    }

    $pt_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query('SELECT id, name, slug FROM product_tags ORDER BY id DESC');
$tags = $listStmt->fetchAll();
$tagCount = count($tags);

if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, name, slug FROM product_tags WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Tag not found.'];
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
            <h2 class="section-title">Product tags</h2>
            <p class="section-lead">Faceted labels for filtering and SEO.</p>
        </div>
        <div class="toolbar__right">
            <span class="pill pill--accent"><?= (int) $tagCount ?> tag(s)</span>
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#tag-form') ?>">New tag</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Tags</h3>
                <span class="panel__meta"><?= (int) $tagCount ?> total</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Slug</th>
                            <th>ID</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tags === []) : ?>
                            <tr><td colspan="4" class="muted">No tags yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($tags as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'name')) ?></td>
                                <td><code><?= cms_esc($val($row, 'slug')) ?></code></td>
                                <td><span class="pill pill--muted">#<?= $rowId ?></span></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this tag?');">
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

        <div class="panel" id="tag-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit tag' : 'New tag' ?></h3>
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
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create tag' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
