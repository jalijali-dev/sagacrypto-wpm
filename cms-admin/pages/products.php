<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';


$pageTitle = 'Products';
$currentNav = 'products';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Products', 'href' => ''],
];

$selfUrl = 'products.php';
$formAction = '../actions/products-store.php';
$projectRoot = CMS_PROJECT_ROOT;

/**
 * Build a public URL for a stored product thumbnail (e.g. uploads/products/file.png).
 */
$pr_thumbnail_preview_url = static function (string $storedPath) use ($projectRoot): string {
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $storedPath) === 1) {
        return $storedPath;
    }

    $relative = ltrim($storedPath, '/');
    $diskPath = $projectRoot . '/' . $relative;
    if (!is_file($diskPath)) {
        return '';
    }

    return app_asset_preview_url($relative);
};

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$categoryOptions = $pdo->query(
    'SELECT id, name FROM product_categories ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$listStmt = $pdo->query(
    'SELECT p.id, p.name, p.slug, p.price, p.is_featured, p.is_active, p.sort_order,
            c.name AS category_name
     FROM products p
     LEFT JOIN product_categories c ON c.id = p.category_id
     ORDER BY p.sort_order ASC, p.id DESC'
);
$products = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, category_id, name, slug, short_description, description, price, thumbnail,
                is_featured, is_active, sort_order, meta_title, meta_description
         FROM products WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Product not found.'];
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
            <h2 class="section-title">Products</h2>
            <p class="section-lead">Catalog SKUs, pricing, and publish state.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#product-form') ?>">Add product</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Products</h3>
                <span class="panel__meta"><?= count($products) ?> product(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Slug</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Featured</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products === []) : ?>
                            <tr><td colspan="7" class="muted">No products yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($products as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'name')) ?></td>
                                <td><code><?= cms_esc($val($row, 'slug')) ?></code></td>
                                <td><?= cms_esc($val($row, 'category_name')) ?></td>
                                <td><?= cms_esc($val($row, 'price')) ?></td>
                                <td>
                                    <?php if ((int) ($row['is_featured'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Featured</span>
                                    <?php else : ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="../actions/products-delete.php" onsubmit="return confirm('Delete this product?');">
                                        <?= cms_csrf_field() ?>
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

        <div class="panel" id="product-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit product' : 'New product' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($formAction) ?>" enctype="multipart/form-data">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <label class="field">Category
                    <select name="category_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($categoryOptions as $cat) : ?>
                            <option value="<?= (int) $cat['id'] ?>"<?= $editRow && (int) $editRow['category_id'] === (int) $cat['id'] ? ' selected' : '' ?>>
                                <?= cms_esc((string) $cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Name
                    <input type="text" name="name" value="<?= cms_esc($editRow ? $val($editRow, 'name') : '') ?>" required>
                </label>
                <label class="field">Slug
                    <input type="text" name="slug" value="<?= cms_esc($editRow ? $val($editRow, 'slug') : '') ?>" required>
                </label>
                <label class="field">Price
                    <input type="number" name="price" min="0" step="0.01" value="<?= cms_esc($editRow ? $val($editRow, 'price') : '') ?>" required>
                </label>
                <label class="field">Short description
                    <textarea name="short_description" rows="2"><?= cms_esc($editRow ? $val($editRow, 'short_description') : '') ?></textarea>
                </label>
                <label class="field">Description
                    <textarea name="description" rows="4"><?= cms_esc($editRow ? $val($editRow, 'description') : '') ?></textarea>
                </label>
                <?php
                $currentThumb = $editRow ? trim($val($editRow, 'thumbnail')) : '';
                $thumbPreviewUrl = $pr_thumbnail_preview_url($currentThumb);
                ?>
                <div class="cms-path-upload" data-thumb-path="<?= cms_esc($currentThumb); ?>">
                    <p class="cms-path-upload__hint">Upload destination: <code>/uploads/products/</code></p>
                    <?php if ($editRow && $currentThumb !== '') : ?>
                        <p class="cms-path-upload__hint">Current thumbnail</p>
                    <?php endif; ?>
                    <div class="cms-path-upload__box">
                        <img
                            class="cms-path-upload__preview"
                            alt="Thumbnail preview"
                            <?= $thumbPreviewUrl !== '' ? ' src="' . cms_esc($thumbPreviewUrl) . '"' : ' hidden'; ?>
                        >
                        <input
                            type="file"
                            name="thumbnail_file"
                            class="cms-path-upload__file"
                            accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                        >
                    </div>
                    <label class="field">Thumbnail path
                        <input
                            type="text"
                            class="cms-path-upload__input"
                            value="<?= cms_esc($currentThumb) ?>"
                            readonly
                        >
                    </label>
                    <p class="cms-path-upload__hint">Recommended size: 800x800px. JPG, PNG, WEBP. Max 5MB.</p>
                </div>
                <fieldset class="panel panel--inset" style="border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin:4px 0">
                    <legend style="font-weight:600;font-size:13px;padding:0 6px;color:var(--text)">SEO</legend>
                    <div class="form-stack" style="margin-top:8px">
                        <label class="field">Meta Title
                            <input
                                type="text"
                                id="seo-meta-title"
                                name="meta_title"
                                maxlength="255"
                                value="<?= cms_esc($editRow ? $val($editRow, 'meta_title') : '') ?>"
                                placeholder="Maks. 60 karakter yang direkomendasikan"
                            >
                        </label>
                        <label class="field">Meta Description
                            <textarea
                                id="seo-meta-description"
                                name="meta_description"
                                rows="3"
                                placeholder="Target 120–155 karakter"
                            ><?= cms_esc($editRow ? $val($editRow, 'meta_description') : '') ?></textarea>
                        </label>
                        <button
                            type="button"
                            id="btn-generate-seo"
                            class="admin-btn admin-btn--secondary"
                        >Generate SEO</button>
                        <span id="js-seo-status" style="font-size:13px;display:inline-block;margin-top:6px"></span>
                    </div>
                </fieldset>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>">
                </label>
                <label class="field">Featured
                    <select name="is_featured">
                        <option value="0"<?= !$editRow || (int) ($editRow['is_featured'] ?? 0) === 0 ? ' selected' : '' ?>>No</option>
                        <option value="1"<?= $editRow && (int) ($editRow['is_featured'] ?? 0) === 1 ? ' selected' : '' ?>>Yes</option>
                    </select>
                </label>
                <label class="field">Status
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create product' ?></button>
            </form>
        </div>
    </div>
</section>
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
    var preview = wrap.querySelector('.cms-path-upload__preview');
    var file = wrap.querySelector('.cms-path-upload__file');
    if (!preview || !file) return;
    var storedPath = wrap.getAttribute('data-thumb-path') || '';
    var initialSrc = preview.getAttribute('src') || previewUrl(storedPath);
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
    file.addEventListener('change', function () {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = '';
      }
      if (!file.files || !file.files[0]) {
        showPreview(initialSrc);
        return;
      }
      objectUrl = URL.createObjectURL(file.files[0]);
      showPreview(objectUrl);
    });
    showPreview(initialSrc);
  });
})();

// ── Generate SEO ─────────────────────────────────────────────────────────────
(function () {
  var btn = document.getElementById('btn-generate-seo');
  if (!btn) return;

  btn.addEventListener('click', function () {
    var form        = btn.closest('form');
    var titleField  = document.getElementById('seo-meta-title');
    var descField   = document.getElementById('seo-meta-description');
    var statusEl    = document.getElementById('js-seo-status');
    if (!form || !titleField || !descField) return;
    if (statusEl) { statusEl.textContent = ''; }

    var data = new FormData();
    data.append('name',          (form.elements['name']          || {value:''}).value);
    data.append('description',   (form.elements['description']   || {value:''}).value);
    data.append('price',         (form.elements['price']         || {value:''}).value);
    // Resolve category name from the selected <option> text
    var catSelect = form.elements['category_id'];
    var catName   = catSelect && catSelect.selectedIndex >= 0
      ? catSelect.options[catSelect.selectedIndex].text.trim()
      : '';
    if (catName === '— Select —') catName = '';
    data.append('category_name', catName);
    data.append('csrf_token',    '<?= cms_csrf_token() ?>');

    btn.disabled    = true;
    btn.textContent = 'Generating…';

    var controller = new AbortController();
    var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

    fetch('../../api/seo-generate.php', { method: 'POST', body: data, signal: controller.signal })
      .then(function (r) {
        if (!r.ok) {
          return r.text().then(function (t) {
            throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
          });
        }
        return r.json();
      })
      .then(function (res) {
        if (res.success) {
          titleField.value = res.meta_title;
          descField.value  = res.meta_description;
          if (statusEl) { statusEl.style.color = 'green'; statusEl.textContent = 'Done — review and edit as needed.'; }
        } else {
          alert('SEO generation failed: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(function (err) {
        alert(err.name === 'AbortError'
          ? 'Timeout — request took too long. Please try again.'
          : 'Request error: ' + err.message);
      })
      .finally(function () {
        clearTimeout(abortTimer);
        btn.disabled    = false;
        btn.textContent = 'Generate SEO';
      });
  });
})();
// ─────────────────────────────────────────────────────────────────────────────

// ── Auto-slug from product name ───────────────────────────────────────────────
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
  document.querySelectorAll('form.form-stack').forEach(function (form) {
    var nameEl = form.querySelector('[name="name"]');
    var slugEl = form.querySelector('[name="slug"]');
    if (!nameEl || !slugEl) { return; }
    var locked = slugEl.value.trim() !== '';
    nameEl.addEventListener('input', function () {
      if (locked) { return; }
      slugEl.value = slugify(nameEl.value);
    });
    slugEl.addEventListener('input', function () {
      locked = true;
    });
  });
})();
// ─────────────────────────────────────────────────────────────────────────────
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
