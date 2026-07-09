<?php
declare(strict_types=1);

/**
 * SagaCrypto (WPM) — public front-end (Crypto / Market News portal).
 *
 * Self-contained: does not depend on cms-admin includes/session, so the
 * public site keeps working independently of the admin panel. Content
 * below is static/dummy per the brief — swap the arrays for real data
 * (e.g. from the CMS database) whenever that's ready.
 */

function wpm_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Small inline SVG icon set used across the page (stroke/fill = currentColor). */
function wpm_icon(string $name): string
{
    static $icons = [
        'news' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='14' height='16' rx='1.5'/><path d='M17 8h3v10a2 2 0 0 1-2 2H7'/><line x1='6.5' y1='8' x2='13.5' y2='8'/><line x1='6.5' y1='11.5' x2='13.5' y2='11.5'/><line x1='6.5' y1='15' x2='11' y2='15'/></svg>",
        'chart' => "<svg viewBox='0 0 24 24' fill='currentColor'><rect x='3' y='13' width='4' height='8' rx='1'/><rect x='10' y='7' width='4' height='14' rx='1'/><rect x='17' y='10' width='4' height='11' rx='1'/></svg>",
        'eye' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z'/><circle cx='12' cy='12' r='3'/></svg>",
        'book' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M4 5.5A2.5 2.5 0 0 1 6.5 3H12v18H6.5A2.5 2.5 0 0 1 4 18.5v-13Z'/><path d='M20 5.5A2.5 2.5 0 0 0 17.5 3H12v18h5.5a2.5 2.5 0 0 0 2.5-2.5v-13Z'/></svg>",
        'search' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.2' y2='16.2'/></svg>",
        'megaphone' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M3 10v4h3l8 5V5l-8 5H3Z'/><path d='M18 9c1.2 1 1.2 5 0 6'/><path d='M21 7c2 2.2 2 7.8 0 10'/></svg>",
        'rocket' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 2c3 1.5 5 5 5 9 0 2-1 4-1 4l-4 3-4-3s-1-2-1-4c0-4 2-7.5 5-9Z'/><circle cx='12' cy='10' r='1.6'/><path d='M8.5 15 6 21l4-2'/><path d='M15.5 15 18 21l-4-2'/></svg>",
        'network' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='5' cy='6' r='2.2'/><circle cx='19' cy='6' r='2.2'/><circle cx='12' cy='18' r='2.2'/><path d='M6.9 7.3 10.3 16.3'/><path d='M17.1 7.3 13.7 16.3'/><path d='M7.2 6h9.6'/></svg>",
        'dashboard' => "<svg viewBox='0 0 24 24' fill='currentColor'><rect x='3' y='3' width='8' height='8' rx='1.5'/><rect x='13' y='3' width='8' height='5' rx='1.5'/><rect x='13' y='10' width='8' height='11' rx='1.5'/><rect x='3' y='13' width='8' height='8' rx='1.5'/></svg>",
        'calendar' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='5' width='18' height='16' rx='2'/><line x1='3' y1='10' x2='21' y2='10'/><line x1='7' y1='2.5' x2='7' y2='6.5'/><line x1='17' y1='2.5' x2='17' y2='6.5'/></svg>",
        'users' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='9' cy='8' r='3.2'/><path d='M2.5 20c0-3.6 3-6 6.5-6s6.5 2.4 6.5 6'/><circle cx='17.5' cy='8.5' r='2.6'/><path d='M15.8 14.2c2.7 0.4 4.7 2.4 4.7 5.8'/></svg>",
        'mail' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='5' width='18' height='14' rx='2'/><path d='m4 6.5 8 6.5 8-6.5'/></svg>",
        'chat' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M4 20l1.2-3.6A8 8 0 1 1 8.8 19L4 20Z'/><line x1='8' y1='11' x2='16' y2='11'/><line x1='8' y1='14.2' x2='13.5' y2='14.2'/></svg>",
        'pin' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 21s7-6.4 7-12a7 7 0 0 0-14 0c0 5.6 7 12 7 12Z'/><circle cx='12' cy='9' r='2.4'/></svg>",
        'box' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 3 3 7.5 12 12l9-4.5L12 3Z'/><path d='M3 7.5v9L12 21l9-4.5v-9'/><path d='M12 12v9'/></svg>",
    ];

    return $icons[$name] ?? '';
}

/* ── Menu (labels kept exactly as required — do not rename) ───────────── */
$wpmMenu = [
    ['label' => 'Beranda', 'href' => '#beranda'],
    ['label' => 'Tentang Kami', 'href' => '#tentang'],
    ['label' => 'Produk', 'href' => '#produk'],
    ['label' => 'Galeri', 'href' => '#galeri'],
    ['label' => 'Tips & Artikel', 'href' => '#artikel'],
    ['label' => 'Kontak', 'href' => '#kontak'],
];

/* ── Dummy / static content (swap for real data later) ───────────────── */
$marketStats = [
    ['label' => 'Bitcoin (BTC)', 'value' => '$67,240', 'delta' => '+2.4%', 'trend' => 'up'],
    ['label' => 'Ethereum (ETH)', 'value' => '$3,180', 'delta' => '+1.1%', 'trend' => 'up'],
    ['label' => 'Market Cap Global', 'value' => '$2.41T', 'delta' => '+0.8%', 'trend' => 'up'],
    ['label' => 'Volume 24 Jam', 'value' => '$98.6B', 'delta' => '-3.2%', 'trend' => 'down'],
];

$latestNews = [
    ['tag' => 'Market', 'title' => 'Bitcoin Tembus Level Resistensi Utama, Sentimen Bullish Menguat'],
    ['tag' => 'Regulasi', 'title' => 'Regulator Global Perketat Aturan Stablecoin, Ini Dampaknya'],
    ['tag' => 'Web3', 'title' => 'Adopsi Web3 di Asia Tenggara Meningkat Pesat Tahun Ini'],
];

$aboutFeatures = [
    ['icon' => 'megaphone', 'title' => 'Berita Crypto', 'desc' => 'Update berita crypto tercepat dan terpercaya, dari pergerakan market hingga isu regulasi.'],
    ['icon' => 'book', 'title' => 'Edukasi Blockchain', 'desc' => 'Materi edukasi blockchain yang mudah dipahami, dari level pemula hingga lanjutan.'],
    ['icon' => 'chart', 'title' => 'Analisis Market', 'desc' => 'Analisis pergerakan market dan tren token berbasis data, disajikan secara ringkas.'],
    ['icon' => 'rocket', 'title' => 'Update Project Web3', 'desc' => 'Sorotan proyek Web3 terbaru, mulai dari DeFi, NFT, hingga infrastruktur blockchain.'],
];

/**
 * Tentang Kami — heading/paragraph backed by the real `pages` table
 * (same one managed from cms-admin/pages/pages.php, "Pages & Articles"),
 * looking up the page with slug `about`. Falls back to the original
 * static copy if that page doesn't exist yet, isn't published, or the
 * DB is unreachable. The 4 feature cards below stay static — there's no
 * matching admin-managed structure for those yet.
 */
$aboutTitle = 'SagaCrypto, Portal Informasi Crypto Terpercaya';
$aboutBody = 'SagaCrypto menghadirkan berita crypto, edukasi blockchain, analisis market, dan update project Web3 dalam satu tempat — disajikan ringkas, akurat, dan mudah dipahami untuk pembaca dari berbagai level.';
try {
    require_once __DIR__ . '/cms-admin/config/database.php';

    $aboutStmt = $pdo->prepare(
        "SELECT title, excerpt, content
         FROM pages
         WHERE slug = 'about' AND status = 'published'
         LIMIT 1"
    );
    $aboutStmt->execute();
    $aboutRow = $aboutStmt->fetch();

    if ($aboutRow) {
        $aboutTitleDb = trim((string) ($aboutRow['title'] ?? ''));
        $aboutExcerpt = trim((string) ($aboutRow['excerpt'] ?? ''));
        $aboutContent = trim((string) ($aboutRow['content'] ?? ''));
        // Prefer excerpt (short, meant for previews); fall back to a
        // plain-text trim of the full content if no excerpt is set.
        $aboutBodyDb = $aboutExcerpt !== ''
            ? $aboutExcerpt
            : trim(mb_substr(strip_tags($aboutContent), 0, 400));

        // Safety guard: this "about" row may still hold old pre-rebrand
        // company-profile copy (leftover "TheAwsoft"/"The Awsoft" text)
        // that was never edited in the admin panel. Never let that leak
        // onto the live crypto site — keep the SagaCrypto fallback instead.
        $aboutHasStaleBrand = static function (string $text): bool {
            return preg_match('/the\s*awsoft/i', $text) === 1;
        };

        if ($aboutTitleDb !== '' && !$aboutHasStaleBrand($aboutTitleDb)) {
            $aboutTitle = $aboutTitleDb;
        }
        if ($aboutBodyDb !== '' && !$aboutHasStaleBrand($aboutBodyDb)) {
            $aboutBody = $aboutBodyDb;
        }
    }
} catch (Throwable $e) {
    // Keep the static fallback above.
}

/**
 * Produk — now backed by the real `products` table (same one managed
 * from cms-admin/pages/products.php). Falls back to the original dummy
 * cards if the DB is unreachable or no active product rows exist yet,
 * so the section never renders empty while content is being filled in.
 */
$products = [];
try {
    require_once __DIR__ . '/cms-admin/config/database.php';

    $productRows = $pdo->query(
        'SELECT name, short_description, thumbnail
         FROM products
         WHERE is_active = 1
         ORDER BY sort_order ASC, created_at DESC
         LIMIT 6'
    )->fetchAll();

    foreach ($productRows as $row) {
        $shortDesc = trim((string) ($row['short_description'] ?? ''));
        $products[] = [
            'icon' => 'box',
            'title' => (string) $row['name'],
            'desc' => $shortDesc !== '' ? $shortDesc : 'Deskripsi produk belum diisi.',
            'thumbnail' => trim((string) ($row['thumbnail'] ?? '')),
        ];
    }
} catch (Throwable $e) {
    $products = [];
}

if ($products === []) {
    $products = [
        ['icon' => 'news', 'title' => 'Crypto News', 'desc' => 'Berita crypto harian dari dalam dan luar negeri, disajikan cepat dan akurat.', 'thumbnail' => ''],
        ['icon' => 'chart', 'title' => 'Market Insight', 'desc' => 'Ringkasan pergerakan market, indikator teknikal, dan sentimen harian.', 'thumbnail' => ''],
        ['icon' => 'eye', 'title' => 'Token Watchlist', 'desc' => 'Pantau token pilihan kamu lewat watchlist yang mudah diikuti.', 'thumbnail' => ''],
        ['icon' => 'book', 'title' => 'Edukasi Blockchain', 'desc' => 'Seri pembelajaran blockchain dari dasar hingga topik lanjutan.', 'thumbnail' => ''],
        ['icon' => 'search', 'title' => 'Web3 Research', 'desc' => 'Riset mendalam seputar ekosistem Web3, DeFi, dan infrastruktur blockchain.', 'thumbnail' => ''],
    ];
}

$gallery = [
    ['title' => 'Market Chart', 'icon' => 'chart'],
    ['title' => 'Blockchain Network', 'icon' => 'network'],
    ['title' => 'Trading Dashboard', 'icon' => 'dashboard'],
    ['title' => 'Crypto Event', 'icon' => 'calendar'],
    ['title' => 'Komunitas Crypto', 'icon' => 'users'],
    ['title' => 'Sesi Riset', 'icon' => 'search'],
];

$articles = [
    ['tag' => 'Edukasi', 'title' => 'Cara Membaca Market Crypto', 'excerpt' => 'Kenali indikator dasar dan cara membaca pergerakan harga sebelum mengambil keputusan.'],
    ['tag' => 'Dasar', 'title' => 'Apa Itu Blockchain?', 'excerpt' => 'Pahami konsep dasar teknologi blockchain dan kenapa ia jadi fondasi dunia crypto.'],
    ['tag' => 'Keamanan', 'title' => 'Tips Aman Menyimpan Aset Digital', 'excerpt' => 'Langkah-langkah praktis mengamankan wallet dan aset digital dari risiko peretasan.'],
    ['tag' => 'Market', 'title' => 'Update Tren Altcoin', 'excerpt' => 'Rangkuman pergerakan altcoin terkini dan sektor yang sedang jadi sorotan.'],
    ['tag' => 'Pemula', 'title' => 'Panduan Pemula Crypto', 'excerpt' => 'Langkah awal yang perlu diketahui sebelum mulai terjun ke dunia crypto.'],
];

/* ── Contact form feedback (set by contact-submit.php via redirect) ──── */
$wpmContactStatus = (string) ($_GET['contact'] ?? '');

$pageTitle = 'SagaCrypto — Portal Crypto & Market News Terkini';
$pageDescription = 'SagaCrypto adalah portal berita crypto: market update, analisis token, edukasi blockchain, dan tren Web3 terkini.';
$currentYear = date('Y');

$cssPath = __DIR__ . '/assets/css/site.css';
$jsPath  = __DIR__ . '/assets/js/site.js';
$cssVer  = @filemtime($cssPath) ?: 1;
$jsVer   = @filemtime($jsPath) ?: 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= wpm_esc($pageTitle) ?></title>
    <meta name="description" content="<?= wpm_esc($pageDescription) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/site.css?v=<?= (int) $cssVer ?>">
</head>
<body class="crypto-theme">
<div class="crypto-bg" aria-hidden="true"></div>

<header class="crypto-nav">
    <div class="crypto-nav__inner">
        <a href="#beranda" class="crypto-logo">
            <span class="crypto-logo__mark" aria-hidden="true">SC</span>
            <span>
                <span class="crypto-logo__text">SagaCrypto</span>
                <span class="crypto-logo__tag">Crypto &amp; Market News</span>
            </span>
        </a>

        <nav aria-label="Menu utama">
            <ul class="crypto-nav__menu">
                <?php foreach ($wpmMenu as $item) : ?>
                    <li><a href="<?= wpm_esc($item['href']) ?>"><?= wpm_esc($item['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="crypto-nav__actions">
            <a class="crypto-btn crypto-btn--primary" href="#kontak">Hubungi Kami</a>
            <button type="button" class="crypto-nav__toggle" id="crypto-nav-toggle" aria-label="Buka menu">
                <span></span>
            </button>
        </div>
    </div>
</header>

<main>

    <!-- ══════════ BERANDA ══════════ -->
    <section id="beranda" class="crypto-hero">
        <div class="crypto-container crypto-hero__inner">
            <div>
                <span class="crypto-hero__eyebrow"><span class="dot" aria-hidden="true"></span> Update market real-time (contoh)</span>
                <h1>Portal <span>Crypto</span> &amp; Market News Terkini</h1>
                <p class="lead">Berita crypto, update market, analisis token, edukasi blockchain, dan tren Web3 — dirangkum ringkas dan mudah dipahami setiap hari.</p>
                <div class="crypto-hero__actions">
                    <a class="crypto-btn crypto-btn--primary" href="#artikel">Baca Berita Terbaru</a>
                    <a class="crypto-btn crypto-btn--ghost" href="#market-update">Lihat Market Update</a>
                </div>
                <div class="crypto-hero__ticker">
                    <?php foreach ($marketStats as $stat) : ?>
                        <span class="ticker-chip">
                            <?= wpm_esc($stat['label']) ?>
                            <strong><?= wpm_esc($stat['value']) ?></strong>
                            <span class="<?= $stat['trend'] === 'up' ? 'up' : 'down' ?>"><?= wpm_esc($stat['delta']) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="crypto-hero__visual" aria-hidden="true">
                <div class="hero-glow hero-glow--a"></div>
                <div class="hero-glow hero-glow--b"></div>
                <div class="coin coin--btc">₿</div>
                <div class="coin coin--eth">Ξ</div>
                <div class="coin coin--sol">◎</div>
                <div class="glass-card hero-chart-card">
                    <div class="hero-chart-card__label">Market Cap</div>
                    <div class="hero-chart-card__value">$2.41T</div>
                    <?= wpm_icon('chart') ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Market update strip -->
    <section id="market-update" class="crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Market Update</span>
                <h2 class="section-title">Ringkasan Market Hari Ini <span>(Data Contoh)</span></h2>
                <p class="section-subtitle">Angka di bawah ini adalah data contoh/dummy untuk keperluan tampilan — akan digantikan data live saat integrasi sumber market sudah siap.</p>
            </div>
            <div class="crypto-grid crypto-grid--4">
                <?php foreach ($marketStats as $stat) : ?>
                    <div class="glass-card market-card">
                        <div class="market-card__label">
                            <span><?= wpm_esc($stat['label']) ?></span>
                        </div>
                        <div class="market-card__value"><?= wpm_esc($stat['value']) ?></div>
                        <div class="market-card__delta <?= $stat['trend'] === 'up' ? 'market-card__delta--up' : 'market-card__delta--down' ?>">
                            <?= $stat['trend'] === 'up' ? '▲' : '▼' ?> <?= wpm_esc($stat['delta']) ?> 24 jam
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="crypto-grid crypto-grid--3" style="margin-top:22px;">
                <?php foreach ($latestNews as $news) : ?>
                    <div class="glass-card crypto-card">
                        <span class="article-card__tag"><?= wpm_esc($news['tag']) ?></span>
                        <h3><?= wpm_esc($news['title']) ?></h3>
                        <p>Ringkasan berita akan tampil di sini begitu sumber berita terhubung.</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════ TENTANG KAMI ══════════ -->
    <section id="tentang" class="crypto-section">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Tentang Kami</span>
                <h2 class="section-title"><?= wpm_esc($aboutTitle) ?></h2>
                <p class="section-subtitle"><?= wpm_esc($aboutBody) ?></p>
            </div>
            <div class="crypto-grid crypto-grid--4">
                <?php foreach ($aboutFeatures as $feature) : ?>
                    <div class="glass-card crypto-card">
                        <div class="crypto-card__icon"><?= wpm_icon($feature['icon']) ?></div>
                        <h3><?= wpm_esc($feature['title']) ?></h3>
                        <p><?= wpm_esc($feature['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════ PRODUK ══════════ -->
    <section id="produk" class="crypto-section crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Produk &amp; Layanan</span>
                <h2 class="section-title">Semua yang Kamu Butuhkan untuk <span>Ikuti Crypto</span></h2>
                <p class="section-subtitle">Dari berita harian sampai riset mendalam — pilih layanan SagaCrypto yang paling sesuai dengan kebutuhanmu.</p>
            </div>
            <div class="crypto-grid crypto-grid--5">
                <?php foreach ($products as $product) : ?>
                    <div class="glass-card crypto-card">
                        <?php if (!empty($product['thumbnail'])) : ?>
                            <div class="crypto-card__icon crypto-card__icon--img">
                                <img src="<?= wpm_esc($product['thumbnail']) ?>" alt="" loading="lazy">
                            </div>
                        <?php else : ?>
                            <div class="crypto-card__icon"><?= wpm_icon($product['icon']) ?></div>
                        <?php endif; ?>
                        <h3><?= wpm_esc($product['title']) ?></h3>
                        <p><?= wpm_esc($product['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════ GALERI ══════════ -->
    <section id="galeri" class="crypto-section">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Galeri</span>
                <h2 class="section-title">Cuplikan <span>Market &amp; Komunitas</span></h2>
                <p class="section-subtitle">Placeholder galeri visual bertema crypto — ganti dengan foto/aset asli kapan pun sudah tersedia.</p>
            </div>
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($gallery as $i => $item) : ?>
                    <div class="gallery-tile gallery-tile--<?= (int) (($i % 6) + 1) ?>">
                        <div class="gallery-tile__bg" aria-hidden="true"></div>
                        <span class="gallery-tile__label"><?= wpm_esc($item['title']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════ TIPS & ARTIKEL ══════════ -->
    <section id="artikel" class="crypto-section">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Tips &amp; Artikel</span>
                <h2 class="section-title">Belajar <span>Crypto</span> Lebih Dalam</h2>
                <p class="section-subtitle">Kumpulan artikel edukasi crypto — konten lengkap akan tersedia begitu modul artikel diterbitkan.</p>
            </div>
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($articles as $article) : ?>
                    <div class="glass-card article-card">
                        <div class="article-card__media"><?= wpm_icon('news') ?></div>
                        <div class="article-card__body">
                            <span class="article-card__tag"><?= wpm_esc($article['tag']) ?></span>
                            <h3><?= wpm_esc($article['title']) ?></h3>
                            <p><?= wpm_esc($article['excerpt']) ?></p>
                            <span class="article-card__cta">Baca Selengkapnya →</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══════════ KONTAK ══════════ -->
    <section id="kontak" class="crypto-section crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Kontak</span>
                <h2 class="section-title">Kerja Sama &amp; <span>Gabung Komunitas</span></h2>
                <p class="section-subtitle">Tertarik kerja sama, kirim rilis berita, atau gabung komunitas pembaca SagaCrypto? Kirim pesan lewat form di samping.</p>
            </div>

            <div class="contact-grid">
                <div class="glass-card contact-info-card">
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('mail') ?></span>
                        <span>
                            <span class="contact-info-row__label">Email</span><br>
                            <span class="contact-info-row__value">Segera hadir</span>
                        </span>
                    </div>
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('chat') ?></span>
                        <span>
                            <span class="contact-info-row__label">WhatsApp</span><br>
                            <span class="contact-info-row__value">Segera hadir</span>
                        </span>
                    </div>
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('pin') ?></span>
                        <span>
                            <span class="contact-info-row__label">Komunitas</span><br>
                            <span class="contact-info-row__value">Segera hadir</span>
                        </span>
                    </div>
                </div>

                <div class="glass-card contact-form-card">
                    <?php if ($wpmContactStatus === 'success') : ?>
                        <div class="form-alert form-alert--success">Pesan kamu berhasil dikirim. Tim SagaCrypto akan menghubungi balik secepatnya.</div>
                    <?php elseif ($wpmContactStatus === 'error') : ?>
                        <div class="form-alert form-alert--error">Pesan gagal dikirim. Mohon periksa kembali form dan coba lagi.</div>
                    <?php endif; ?>

                    <form method="post" action="contact-submit.php" novalidate>
                        <div class="form-row form-row--2col">
                            <div class="form-row">
                                <label class="form-label" for="wpm-name">Nama Lengkap</label>
                                <input class="form-input" type="text" id="wpm-name" name="full_name" placeholder="Nama kamu" required maxlength="120">
                            </div>
                            <div class="form-row">
                                <label class="form-label" for="wpm-email">Email</label>
                                <input class="form-input" type="email" id="wpm-email" name="email" placeholder="nama@email.com" required maxlength="160">
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="wpm-subject">Subjek</label>
                            <input class="form-input" type="text" id="wpm-subject" name="subject" placeholder="Kerja sama, rilis berita, dll." maxlength="160">
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="wpm-message">Pesan</label>
                            <textarea class="form-textarea" id="wpm-message" name="message" placeholder="Tulis pesan kamu di sini..." required maxlength="4000"></textarea>
                        </div>
                        <!-- Honeypot anti-spam field — stays empty for real users -->
                        <div class="hp-field" aria-hidden="true">
                            <label for="wpm-website">Website</label>
                            <input type="text" id="wpm-website" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <button type="submit" class="crypto-btn crypto-btn--primary" style="width:100%;">Kirim Pesan</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

</main>

<footer class="crypto-footer">
    <div class="crypto-container">
        <div class="footer-grid">
            <div class="footer-brand">
                <span class="crypto-logo__text" style="font-size:24px;">SagaCrypto</span>
                <p>Portal berita crypto, market update, analisis token, edukasi blockchain, dan tren Web3 — disajikan ringkas dan mudah dipahami.</p>
            </div>
            <div>
                <p class="footer-heading">Menu</p>
                <ul class="footer-links">
                    <?php foreach ($wpmMenu as $item) : ?>
                        <li><a href="<?= wpm_esc($item['href']) ?>"><?= wpm_esc($item['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <p class="footer-heading">Layanan</p>
                <ul class="footer-links">
                    <?php foreach (array_slice($products, 0, 5) as $product) : ?>
                        <li><a href="#produk"><?= wpm_esc($product['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <p class="footer-disclaimer">Seluruh data market/harga pada halaman ini adalah <strong>contoh (dummy)</strong> untuk keperluan tampilan, bukan data pasar real-time, dan bukan merupakan saran keuangan/investasi.</p>

        <div class="footer-bottom">
            <span>&copy; <?= wpm_esc($currentYear) ?> SagaCrypto. Seluruh hak cipta dilindungi.</span>
            <span>Dibuat dengan fokus pada berita &amp; edukasi crypto.</span>
        </div>
    </div>
</footer>

<div class="crypto-nav__mobile" id="crypto-nav-mobile">
    <div class="crypto-nav__mobile-panel">
        <button type="button" class="crypto-nav__mobile-close" id="crypto-nav-mobile-close" aria-label="Tutup menu">&times;</button>
        <?php foreach ($wpmMenu as $item) : ?>
            <a href="<?= wpm_esc($item['href']) ?>"><?= wpm_esc($item['label']) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<script src="assets/js/site.js?v=<?= (int) $jsVer ?>" defer></script>
</body>
</html>
