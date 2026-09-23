<?php
declare(strict_types=1);

/**
 * SagaCrypto — article listing page. Handles three modes via query string:
 *   kategori.php                → all published articles, paginated
 *   kategori.php?slug=bitcoin   → articles in one category
 *   kategori.php?tag=defi       → articles with one tag
 * This is also what the "Berita" nav item and article tag chips link to.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$categorySlug = trim((string) ($_GET['slug'] ?? ''));
$tagSlug = trim((string) ($_GET['tag'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;

$category = null;
$tag = null;
$where = "p.status = 'published'";
$params = [];

if ($categorySlug !== '') {
    $catStmt = $pdo->prepare('SELECT * FROM article_categories WHERE slug = :slug LIMIT 1');
    $catStmt->execute(['slug' => $categorySlug]);
    $category = $catStmt->fetch() ?: null;
    if ($category === null) {
        http_response_code(404);
    } else {
        $where .= ' AND p.category_id = :catId';
        $params['catId'] = (int) $category['id'];
    }
} elseif ($tagSlug !== '') {
    $tagStmt = $pdo->prepare('SELECT * FROM article_tags WHERE slug = :slug LIMIT 1');
    $tagStmt->execute(['slug' => $tagSlug]);
    $tag = $tagStmt->fetch() ?: null;
    if ($tag === null) {
        http_response_code(404);
    } else {
        $where .= ' AND p.page_id IN (SELECT page_id FROM article_tag_map WHERE tag_id = :tagId)';
        $params['tagId'] = (int) $tag['id'];
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pages p WHERE $where");
$countStmt->execute($params);
$totalArticles = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalArticles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY p.published_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$articles = $listStmt->fetchAll();

$allCategories = [];
try {
    // Only list categories that actually have at least 1 published article —
    // showing empty "(0)" categories here just clutters the filter row with
    // pills nobody can click into anything (see SITEMAP.md Update Log,
    // 23 Sep 2026, after the "Crypto Intelligence" taxonomy migration `018`
    // added 8 new categories that mostly have 0 articles so far).
    $allCategories = $pdo->query(
        "SELECT c.*, COUNT(p.page_id) AS article_count
         FROM article_categories c
         LEFT JOIN pages p ON p.category_id = c.id AND p.status = 'published'
         GROUP BY c.id
         HAVING article_count > 0
         ORDER BY c.name ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $allCategories = [];
}

$paginateUrl = static function (int $p) use ($categorySlug, $tagSlug): string {
    if ($tagSlug !== '') {
        return wpm_url_tag($tagSlug) . '?page=' . $p;
    }
    return wpm_url_kategori($categorySlug !== '' ? $categorySlug : null) . '?page=' . $p;
};

if ($category !== null) {
    $pageTitle = 'Berita ' . $category['name'] . ' — SagaCrypto';
    $pageDescription = 'Kumpulan berita dan artikel kategori ' . $category['name'] . ' di SagaCrypto.';
    $heroTitle = $category['name'];
    $heroSubtitle = 'Kumpulan berita & artikel kategori ini.';
} elseif ($tag !== null) {
    $pageTitle = 'Tag: ' . $tag['name'] . ' — SagaCrypto';
    $pageDescription = 'Kumpulan artikel dengan tag ' . $tag['name'] . ' di SagaCrypto.';
    $heroTitle = '#' . $tag['name'];
    $heroSubtitle = 'Artikel dengan tag ini.';
} else {
    $pageTitle = 'Semua Berita — SagaCrypto';
    $pageDescription = 'Kumpulan seluruh berita crypto, market, blockchain, dan Web3 dari SagaCrypto.';
    $heroTitle = 'Semua Berita';
    $heroSubtitle = 'Kumpulan seluruh artikel yang sudah diterbitkan.';
}
$activeNav = 'berita';
$canonicalUrl = wpm_site_url(
    $tagSlug !== '' ? wpm_url_tag($tagSlug) : wpm_url_kategori($categorySlug !== '' ? $categorySlug : null)
);

/* ── "Muat Lebih Banyak" (load more) AJAX fragment — replaces numbered
   pagination for this listing (see SITEMAP.md Update Log, 23 Sep 2026).
   Returns just the next page's cards as JSON so JS can append them
   in place instead of a full page reload. No site-header/footer here. ── */
if (($_GET['ajax'] ?? '') === '1') {
    ob_start();
    if ($articles !== []) {
        echo '<div class="crypto-grid crypto-grid--3">';
        foreach ($articles as $i => $article) {
            echo wpm_article_card($article);
            if ($i === 4) {
                echo '</div>' . wpm_render_ad_slot($pdo, 'between-article-cards', 'category', $category['id'] ?? null) . '<div class="crypto-grid crypto-grid--3">';
            }
        }
        echo '</div>';
    }
    $wpmLoadMoreFragment = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode([
        'html'     => $wpmLoadMoreFragment,
        'hasMore'  => $page < $totalPages,
        'nextPage' => $page + 1,
    ]);
    exit;
}

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="index.php">Beranda</a> <span>/</span> <a href="<?= wpm_esc(wpm_url_kategori()) ?>">Berita</a>
            <?php if ($category !== null) : ?><span>/</span> <?= wpm_esc($category['name']) ?><?php endif; ?>
        </nav>
        <span class="section-kicker">Berita</span>
        <h1><?= wpm_esc($heroTitle) ?></h1>
        <p><?= wpm_esc(strip_tags($heroSubtitle)) ?> <?= wpm_esc((string) $totalArticles) ?> artikel.</p>
    </div>
</section>

<?php if ($allCategories !== []) : ?>
<div class="crypto-container" style="margin-bottom:8px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="crypto-btn crypto-btn--ghost" style="padding:8px 16px;font-size:13px;<?= $category === null && $tag === null ? 'background:var(--grad-brand);color:#fff;' : '' ?>" href="<?= wpm_esc(wpm_url_kategori()) ?>">Semua</a>
        <?php foreach ($allCategories as $cat) : ?>
            <a class="crypto-btn crypto-btn--ghost" style="padding:8px 16px;font-size:13px;<?= $category !== null && $category['id'] == $cat['id'] ? 'background:var(--grad-brand);color:#fff;' : '' ?>" href="<?= wpm_esc(wpm_url_kategori((string) $cat['slug'])) ?>"><?= wpm_esc((string) $cat['name']) ?> (<?= (int) $cat['article_count'] ?>)</a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <?= wpm_render_ad_slot($pdo, 'above-article', 'category', $category['id'] ?? null) ?>

        <?php if ($articles !== []) : ?>
            <div id="wpm-berita-list">
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($articles as $i => $article) : ?>
                    <?= wpm_article_card($article) ?>
                    <?php if ($i === 4) : ?>
                        </div><?= wpm_render_ad_slot($pdo, 'between-article-cards', 'category', $category['id'] ?? null) ?><div class="crypto-grid crypto-grid--3">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            </div>

            <?php if ($page < $totalPages) : ?>
            <div class="wpm-load-more-wrap">
                <button
                    type="button"
                    id="wpm-load-more-btn"
                    class="crypto-btn crypto-btn--ghost"
                    data-base-url="<?= wpm_esc($paginateUrl(1)) ?>"
                    data-next-page="<?= (int) ($page + 1) ?>"
                >Muat Lebih Banyak</button>
            </div>
            <?php endif; ?>
        <?php else : ?>
            <div class="empty-state"><?= wpm_icon('news') ?><p>Belum ada artikel untuk ditampilkan.</p></div>
        <?php endif; ?>
    </div>
</section>

</main>
<script>
(function () {
  var btn = document.getElementById('wpm-load-more-btn');
  var list = document.getElementById('wpm-berita-list');
  if (!btn || !list) return;

  var originalLabel = btn.textContent;

  btn.addEventListener('click', function () {
    var nextPage = parseInt(btn.dataset.nextPage, 10) || 1;
    var baseUrl = btn.dataset.baseUrl || '';
    var url = baseUrl.replace(/([?&])page=\d+/, '$1page=' + nextPage);
    url += (url.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';

    btn.disabled = true;
    btn.textContent = 'Memuat...';

    fetch(url)
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.html) {
          list.insertAdjacentHTML('beforeend', data.html);
        }
        if (data && data.hasMore) {
          btn.dataset.nextPage = data.nextPage;
          btn.disabled = false;
          btn.textContent = originalLabel;
        } else {
          btn.remove();
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = originalLabel;
      });
  });
})();
</script>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
