<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Special Pages';
$currentNav = 'special-pages';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Special Pages', 'href' => ''],
];

$selfUrl = 'special-pages.php';

$sp_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    $target = $selfUrl . ($query !== null && $query !== '' ? '?' . $query : '');
    header('Location: ' . $target, true, 302);
    exit;
};

$sp_validate = static function (
    string $pageKey,
    string $title,
    string $slug,
    string $status
): ?string {
    if ($pageKey === '') {
        return 'Page key is required.';
    }
    if ($title === '') {
        return 'Title is required.';
    }
    if ($slug === '' && strtolower($pageKey) !== 'home') {
        return 'Slug is required (except for the home page).';
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        return 'Status must be draft or published.';
    }

    return null;
};

$sp_duplicate_error = static function (PDO $pdo, string $pageKey, string $slug, ?int $excludeId): ?string {
    $keySql = 'SELECT COUNT(*) FROM special_pages WHERE page_key = :page_key';
    $keyParams = ['page_key' => $pageKey];
    if ($excludeId !== null) {
        $keySql .= ' AND special_page_id != :id';
        $keyParams['id'] = $excludeId;
    }
    $keyStmt = $pdo->prepare($keySql);
    $keyStmt->execute($keyParams);
    if ((int) $keyStmt->fetchColumn() > 0) {
        return 'That page key is already in use.';
    }

    if ($slug !== '') {
        $slugSql = 'SELECT COUNT(*) FROM special_pages WHERE slug = :slug';
        $slugParams = ['slug' => $slug];
        if ($excludeId !== null) {
            $slugSql .= ' AND special_page_id != :id';
            $slugParams['id'] = $excludeId;
        }
        $slugStmt = $pdo->prepare($slugSql);
        $slugStmt->execute($slugParams);
        if ((int) $slugStmt->fetchColumn() > 0) {
            return 'That slug is already in use.';
        }
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['special_page_id'] ?? 0);
        if ($deleteId <= 0) {
            $sp_redirect('Invalid special page.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM special_pages WHERE special_page_id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $sp_redirect('Special page not found or already deleted.', 'error');
        }
        $sp_redirect('Special page deleted successfully.');
    }

    $pageKey = trim((string) ($_POST['page_key'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
    $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));

    $validationError = $sp_validate($pageKey, $title, $slug, $status);
    if ($validationError !== null) {
        $errorQuery = null;
        if ($action === 'update') {
            $failId = (int) ($_POST['special_page_id'] ?? 0);
            if ($failId > 0) {
                $errorQuery = 'edit=' . $failId;
            }
        }
        $sp_redirect($validationError, 'error', $errorQuery);
    }

    if ($action === 'create') {
        $dupError = $sp_duplicate_error($pdo, $pageKey, $slug, null);
        if ($dupError !== null) {
            $sp_redirect($dupError, 'error');
        }

        $insert = $pdo->prepare(
            'INSERT INTO special_pages (
                page_key, title, slug, content, meta_title, meta_description, status, created_at, updated_at
            ) VALUES (
                :page_key, :title, :slug, :content, :meta_title, :meta_description, :status, NOW(), NOW()
            )'
        );
        $insert->execute([
            'page_key' => $pageKey,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'status' => $status,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $sp_redirect('Special page created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['special_page_id'] ?? 0);
        if ($updateId <= 0) {
            $sp_redirect('Invalid special page.', 'error');
        }

        $dupError = $sp_duplicate_error($pdo, $pageKey, $slug, $updateId);
        if ($dupError !== null) {
            $sp_redirect($dupError, 'error', 'edit=' . $updateId);
        }

        $update = $pdo->prepare(
            'UPDATE special_pages
             SET page_key = :page_key,
                 title = :title,
                 slug = :slug,
                 content = :content,
                 meta_title = :meta_title,
                 meta_description = :meta_description,
                 status = :status,
                 updated_at = NOW()
             WHERE special_page_id = :id'
        );
        $update->execute([
            'page_key' => $pageKey,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'status' => $status,
            'id' => $updateId,
        ]);
        if ($update->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT special_page_id FROM special_pages WHERE special_page_id = :id LIMIT 1');
            $exists->execute(['id' => $updateId]);
            if (!$exists->fetch()) {
                $sp_redirect('Special page not found.', 'error');
            }
        }
        $sp_redirect('Special page updated successfully.', 'success', 'edit=' . $updateId);
    }

    $sp_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query(
    'SELECT special_page_id, page_key, title, slug, status, updated_at
     FROM special_pages
     ORDER BY updated_at DESC, special_page_id DESC'
);
$pages = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT special_page_id, page_key, title, slug, content, meta_title, meta_description, status
         FROM special_pages
         WHERE special_page_id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Special page not found.'];
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

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Special pages</h2>
            <p class="section-lead">Campaigns, promos, and one-off landing routes.</p>
        </div>
        <div class="toolbar__right">
            <?php if ($editRow) : ?>
                <a class="admin-btn admin-btn--primary" href="<?= cms_esc($selfUrl) ?>">New special page</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editRow) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Edit special page</h3>
            <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="special_page_id" value="<?= (int) $editRow['special_page_id'] ?>">
            <label class="field">Title
                <input type="text" name="title" value="<?= cms_esc($val($editRow, 'title')) ?>" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" value="<?= cms_esc($val($editRow, 'slug')) ?>" placeholder="Leave empty for home">
            </label>
            <label class="field">Page key
                <input type="text" name="page_key" value="<?= cms_esc($val($editRow, 'page_key')) ?>" required>
            </label>
            <?php $editStatus = strtolower($val($editRow, 'status')); ?>
            <label class="field">Status
                <select name="status" required>
                    <option value="draft"<?= $editStatus === 'draft' ? ' selected' : '' ?>>draft</option>
                    <option value="published"<?= $editStatus === 'published' ? ' selected' : '' ?>>published</option>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="special-page-content" rows="6"><?= cms_esc($val($editRow, 'content')) ?></textarea>
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title" value="<?= cms_esc($val($editRow, 'meta_title')) ?>">
            </label>
            <label class="field">Meta description
                <textarea name="meta_description" rows="3"><?= cms_esc($val($editRow, 'meta_description')) ?></textarea>
            </label>
            <div style="grid-column: 1 / -1;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO with Agent SEO</button>
                <span class="js-seo-status" style="font-size:.85em;color:var(--muted);"></span>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php else : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">New special page</h3>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Title
                <input type="text" name="title" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" placeholder="Leave empty for home">
            </label>
            <label class="field">Page key
                <input type="text" name="page_key" required>
            </label>
            <label class="field">Status
                <select name="status" required>
                    <option value="draft" selected>draft</option>
                    <option value="published">published</option>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="special-page-content" rows="6"></textarea>
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title">
            </label>
            <label class="field">Meta description
                <textarea name="meta_description" rows="3"></textarea>
            </label>
            <div style="grid-column: 1 / -1;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO with Agent SEO</button>
                <span class="js-seo-status" style="font-size:.85em;color:var(--muted);"></span>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Create special page</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All special pages</h3>
            <span class="panel__meta"><?= count($pages) ?> page(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Page Key</th>
                        <th>Title</th>
                        <th>Slug</th>
                        <th>Status</th>
                        <th>Updated At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pages === []) : ?>
                        <tr>
                            <td colspan="7" class="muted">No special pages yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($pages as $row) : ?>
                        <?php
                        $rowId = (int) $row['special_page_id'];
                        $published = strtolower((string) $row['status']) === 'published';
                        ?>
                        <tr>
                            <td><?= (int) $row['special_page_id'] ?></td>
                            <td><code><?= cms_esc($val($row, 'page_key')) ?></code></td>
                            <td><?= cms_esc($val($row, 'title')) ?></td>
                            <td><code><?= cms_esc($val($row, 'slug')) ?></code></td>
                            <td><span class="pill pill--<?= $published ? 'ok' : 'muted' ?>"><?= cms_esc($val($row, 'status')) ?></span></td>
                            <td><?= cms_esc($formatDt($row['updated_at'] ?? null)) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this special page?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="special_page_id" value="<?= $rowId ?>">
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
<script>
// ---- Auto-generate slug from title (special-pages.php only) ----
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

        // Start locked in edit mode (existing records must never have their
        // slug auto-overwritten — including 'home', whose slug is intentionally
        // empty) or when slug already has a value. Unlocked only on the empty
        // create form.
        var actionEl = form.querySelector('input[name="action"]');
        var isUpdate = actionEl && actionEl.value === 'update';
        var locked   = isUpdate || slugEl.value.trim() !== '';

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
<script>
// ---- Generate SEO with Agent SEO (special-pages.php) ----
(function () {
  document.querySelectorAll('.js-generate-seo').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form     = btn.closest('form');
      var statusEl = form.querySelector('.js-seo-status');

      var titleEl     = form.querySelector('[name="title"]');
      var slugEl      = form.querySelector('[name="slug"]');
      var pageKeyEl   = form.querySelector('[name="page_key"]');
      var contentEl   = form.querySelector('[name="content"]');
      var metaTitleEl = form.querySelector('[name="meta_title"]');
      var metaDescEl  = form.querySelector('[name="meta_description"]');

      var titleValue = titleEl ? titleEl.value.trim() : '';
      if (titleValue === '') {
        statusEl.style.color = '#c00';
        statusEl.textContent = 'Error: isi Title dulu sebelum generate SEO.';
        return;
      }

      // Get content from TinyMCE if active, otherwise fall back to textarea value
      var contentValue = '';
      if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        contentValue = tinymce.activeEditor.getContent({ format: 'text' });
      } else if (contentEl) {
        contentValue = contentEl.value;
      }

      var data = new FormData();
      data.append('type',     'special_page');
      data.append('title',    titleValue);
      data.append('slug',     slugEl    ? slugEl.value.trim()    : '');
      data.append('page_key', pageKeyEl ? pageKeyEl.value.trim() : '');
      data.append('content',  contentValue.trim());
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('../../api/seo-generate.php', {
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
<?php
require dirname(__DIR__) . '/includes/footer.php';
