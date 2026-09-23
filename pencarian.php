<?php
declare(strict_types=1);

/**
 * SagaCrypto — public search results page. /pencarian.php?q=bitcoin
 * Searches published articles only (title, excerpt, content).
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$query = trim((string) ($_GET['q'] ?? ''));

if (($_GET['suggest'] ?? '') === '1') {
    header('Content-Type: application/json');
    $suggestions = [];
    if (mb_strlen($query) >= 2) {
        $like = '%' . $query . '%';
        $stmt = $pdo->prepare(
            "SELECT title, slug FROM pages
             WHERE status = 'published' AND title LIKE :q
             ORDER BY published_at DESC LIMIT 6"
        );
        $stmt->execute(['q' => $like]);
        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = ['title' => (string) $row['title'], 'url' => wpm_url_artikel((string) $row['slug'])];
        }
    }
    echo json_encode(['suggestions' => $suggestions]);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$articles = [];
$totalArticles = 0;
$totalPages = 1;

if (mb_strlen($query) >= 2) {
    $like = '%' . $query . '%';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pages
         WHERE status = 'published' AND (title LIKE :q1 OR excerpt LIKE :q2 OR content LIKE :q3)"
    );
    $countStmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
    $totalArticles = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalArticles / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.status = 'published' AND (p.title LIKE :q1 OR p.excerpt LIKE :q2 OR p.content LIKE :q3)
         ORDER BY p.published_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $listStmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
    $articles = $listStmt->fetchAll();
}

$pageTitle = $query !== '' ? 'Hasil Pencarian: ' . $query . ' — SagaCrypto' : 'Pencarian — SagaCrypto';
$pageDescription = 'Cari berita crypto, market, dan artikel di SagaCrypto.';
$activeNav = '';
$canonicalUrl = wpm_site_url(wpm_url_pencarian());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Pencarian</nav>
        <span class="section-kicker">Cari</span>
        <h1>Cari Artikel</h1>
        <form class="search-form" method="get" action="<?= wpm_esc(wpm_url_pencarian()) ?>" autocomplete="off">
            <div class="search-form__field">
                <input type="search" name="q" id="wpm-search-input" value="<?= wpm_esc($query) ?>" placeholder="Cari berita crypto, market, atau topik lainnya..." minlength="2" required>
                <div class="search-suggest" id="wpm-search-suggest"></div>
            </div>
            <button type="submit" aria-label="Cari"><?= wpm_icon('search') ?></button>
        </form>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <?php if ($query === '') : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Masukkan kata kunci untuk mulai mencari.</p></div>
        <?php elseif (mb_strlen($query) < 2) : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Kata kunci minimal 2 karakter.</p></div>
        <?php elseif ($articles === []) : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Tidak ada hasil untuk "<?= wpm_esc($query) ?>".</p></div>
        <?php else : ?>
            <p style="color:var(--text-muted);margin-bottom:24px;"><?= (int) $totalArticles ?> hasil untuk "<?= wpm_esc($query) ?>"</p>
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($articles as $article) : ?>
                    <?= wpm_article_card($article) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($totalPages > 1) : ?>
            <nav class="pagination" aria-label="Pagination">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= max(1, $page - 1) ?>">&larr;</a>
                <?php for ($p = 1; $p <= $totalPages; $p++) : ?>
                    <a class="<?= $p === $page ? 'is-current' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= min($totalPages, $page + 1) ?>">&rarr;</a>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var input = document.getElementById('wpm-search-input');
    var box = document.getElementById('wpm-search-suggest');
    if (!input || !box) { return; }
    var suggestUrl = <?= json_encode(wpm_url_pencarian()) ?>;
    var debounceTimer = null;
    var currentXhr = null;

    function hideBox() { box.classList.remove('is-open'); box.innerHTML = ''; }

    function renderSuggestions(items) {
        if (!items.length) { hideBox(); return; }
        box.innerHTML = items.map(function (item) {
            return '<a href="' + item.url.replace(/"/g, '&quot;') + '">' + item.title.replace(/</g, '&lt;') + '</a>';
        }).join('');
        box.classList.add('is-open');
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 2) { hideBox(); return; }
        debounceTimer = setTimeout(function () {
            if (currentXhr) { currentXhr.abort(); }
            currentXhr = new XMLHttpRequest();
            currentXhr.open('GET', suggestUrl + '?suggest=1&q=' + encodeURIComponent(q));
            currentXhr.onload = function () {
                if (currentXhr.status !== 200) { return; }
                try {
                    var data = JSON.parse(currentXhr.responseText);
                    renderSuggestions(data.suggestions || []);
                } catch (e) { hideBox(); }
            };
            currentXhr.send();
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target) && e.target !== input) { hideBox(); }
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { hideBox(); }
    });
}());
</script>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
