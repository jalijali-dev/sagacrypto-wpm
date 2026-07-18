<?php
declare(strict_types=1);

/**
 * SagaCrypto — public homepage. Multi-page news portal (Fase 9 redesign):
 * this file only holds the homepage; article/category/crypto each have
 * their own dedicated page (artikel.php, kategori.php, crypto.php) that
 * share includes/site-bootstrap.php, includes/site-header.php and
 * includes/site-footer.php.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

/* ── Hero: latest featured article, falls back to the latest published one ── */
$heroStmt = $pdo->query(
    "SELECT p.*, c.name AS category_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     WHERE p.status = 'published'
     ORDER BY p.is_featured DESC, p.published_at DESC
     LIMIT 1"
);
$heroArticle = $heroStmt->fetch() ?: null;
$heroId = $heroArticle ? (int) $heroArticle['page_id'] : 0;

/* ── Latest news grid (always shown, independent of the Pamungkas builder) ── */
$latestStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     WHERE p.status = 'published' AND p.page_id != :heroId
     ORDER BY p.published_at DESC LIMIT 6"
);
$latestStmt->execute(['heroId' => $heroId]);
$latestArticles = $latestStmt->fetchAll();

/* ── Trending articles ── */
$trendingStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     WHERE p.status = 'published' AND p.is_trending = 1 AND p.page_id != :heroId
     ORDER BY p.published_at DESC LIMIT 4"
);
$trendingStmt->execute(['heroId' => $heroId]);
$trendingArticles = $trendingStmt->fetchAll();

/* ── Crypto widget (mini) ── */
$cryptoResult = cms_crypto_fetch_coins($pdo);
$cryptoMini = $cryptoResult['ok'] ? array_slice($cryptoResult['data'], 0, 6) : [];

/* ── Dynamic Featured/Pamungkas sections (admin-configurable, may be empty) ── */
$featuredSections = [];
try {
    $featuredSections = $pdo->query('SELECT * FROM featured_sections WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
} catch (Throwable $e) {
    $featuredSections = [];
}

/* ── Promo banners (cms-admin/pages/banners.php), placement="home" ── */
$homeBanners = wpm_banners_active($pdo, 'home');

/**
 * "Tentang Kami" — title/body + 4 feature cards, managed from
 * cms-admin/pages/about-settings.php (repurposed 13 Jul 2026 from the
 * old, never-rendered "Landing Page" builder). Reuses the
 * `landing_sections` table with page_key='about' and fixed section_keys
 * ('main', 'feature_1'..'feature_4'). Icons stay fixed by position —
 * only title/description are admin-editable. Falls back to these
 * defaults if a row doesn't exist yet (form never saved) or the table
 * isn't reachable — keep these defaults in sync with
 * about-settings.php's ABOUT_DEFAULTS.
 */
$aboutTitle = 'SagaCrypto, Portal Informasi Crypto Terpercaya';
$aboutBody = 'SagaCrypto menghadirkan berita crypto, edukasi blockchain, analisis market, dan tren Web3 dalam satu tempat — disajikan ringkas, akurat, dan mudah dipahami untuk pembaca dari berbagai level.';
$aboutFeatures = [
    ['icon' => 'megaphone', 'title' => 'Berita Crypto', 'desc' => 'Update berita crypto tercepat dan terpercaya, dari pergerakan market hingga isu regulasi.'],
    ['icon' => 'book', 'title' => 'Edukasi Blockchain', 'desc' => 'Materi edukasi blockchain yang mudah dipahami, dari level pemula hingga lanjutan.'],
    ['icon' => 'chart', 'title' => 'Analisis Market', 'desc' => 'Analisis pergerakan market dan tren token berbasis data, disajikan secara ringkas.'],
    ['icon' => 'flame', 'title' => 'Live Market Ticker', 'desc' => 'Harga BTC, ETH, BNB, dan koin pilihan lainnya, update real-time langsung di halaman utama.'],
];
try {
    $aboutStmt = $pdo->prepare("SELECT section_key, title, subtitle FROM landing_sections WHERE page_key = 'about' AND status = 'published'");
    $aboutStmt->execute();
    $featureKeys = ['feature_1', 'feature_2', 'feature_3', 'feature_4'];
    foreach ($aboutStmt->fetchAll() as $row) {
        $key = (string) ($row['section_key'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $subtitle = trim((string) ($row['subtitle'] ?? ''));
        if ($key === 'main') {
            if ($title !== '') {
                $aboutTitle = $title;
            }
            if ($subtitle !== '') {
                $aboutBody = $subtitle;
            }
        } else {
            $idx = array_search($key, $featureKeys, true);
            if ($idx !== false) {
                if ($title !== '') {
                    $aboutFeatures[$idx]['title'] = $title;
                }
                if ($subtitle !== '') {
                    $aboutFeatures[$idx]['desc'] = $subtitle;
                }
            }
        }
    }
} catch (Throwable $e) {
    // Keep static fallback.
}

$wpmContactStatus = (string) ($_GET['contact'] ?? '');

$pageTitle = 'SagaCrypto — Portal Crypto & Berita Terkini';
$pageDescription = 'SagaCrypto adalah portal berita crypto: market update, analisis token, edukasi blockchain, dan tren Web3 terkini.';
$activeNav = 'beranda';
$canonicalUrl = wpm_site_url('index.php');

require __DIR__ . '/includes/site-header.php';
?>

    <!-- Breaking news ticker now renders site-wide, merged with the Live
         Ticker in one row — see includes/site-header.php. -->

    <!-- ══════════ HERO — BERITA UTAMA ══════════ -->
    <section id="beranda" class="crypto-hero">
        <div class="crypto-container crypto-hero__inner">
            <div>
                <span class="crypto-hero__eyebrow"><span class="dot" aria-hidden="true"></span> <?= $heroArticle ? 'Berita Utama' : 'Selamat Datang' ?></span>
                <?php if ($heroArticle) : ?>
                    <h1><a href="<?= wpm_esc(wpm_url_artikel((string) $heroArticle['slug'])) ?>" style="color:inherit;"><?= wpm_esc((string) $heroArticle['title']) ?></a></h1>
                    <p class="lead"><?= wpm_esc(wpm_excerpt((string) ($heroArticle['excerpt'] ?: $heroArticle['content']), 180)) ?></p>
                    <div class="crypto-hero__actions">
                        <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_url_artikel((string) $heroArticle['slug'])) ?>">Baca Selengkapnya</a>
                        <a class="crypto-btn crypto-btn--ghost" href="<?= wpm_esc(wpm_url_kategori()) ?>">Lihat Semua Berita</a>
                    </div>
                <?php else : ?>
                    <h1>Portal <span>Crypto</span> &amp; Market News Terkini</h1>
                    <p class="lead">Berita crypto, update market, analisis token, edukasi blockchain, dan tren Web3 — dirangkum ringkas dan mudah dipahami setiap hari.</p>
                    <div class="crypto-hero__actions">
                        <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_url_kategori()) ?>">Baca Berita Terbaru</a>
                        <a class="crypto-btn crypto-btn--ghost" href="<?= wpm_esc(wpm_url_crypto()) ?>">Lihat Harga Crypto</a>
                    </div>
                <?php endif; ?>

                <?php if ($cryptoMini !== []) : ?>
                <div class="crypto-hero__ticker">
                    <?php foreach (array_slice($cryptoMini, 0, 4) as $coin) : ?>
                        <?php
                        $change = $coin['price_change_percentage_24h'] ?? null;
                        $trendClass = $change !== null && $change < 0 ? 'down' : 'up';
                        ?>
                        <span class="ticker-chip">
                            <?= wpm_esc(strtoupper((string) ($coin['symbol'] ?? ''))) ?>
                            <strong>$<?= wpm_esc(number_format((float) ($coin['current_price'] ?? 0), 2)) ?></strong>
                            <span class="<?= $trendClass ?>"><?= $change !== null ? wpm_esc(number_format((float) $change, 1) . '%') : '—' ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="crypto-hero__visual" aria-hidden="true">
                <div class="hero-glow hero-glow--a"></div>
                <div class="hero-glow hero-glow--b"></div>
                <div class="coin coin--btc">₿</div>
                <div class="coin coin--eth">Ξ</div>
                <div class="coin coin--sol">◎</div>
                <div class="glass-card hero-chart-card">
                    <div class="hero-chart-card__label">Market Cap Global</div>
                    <div class="hero-chart-card__value"><?= $cryptoMini !== [] ? '$' . wpm_esc(number_format((float) array_sum(array_column($cryptoMini, 'market_cap')))) : 'Live' ?></div>
                    <?= wpm_icon('chart') ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════ PROMO BANNERS (admin-configurable, cms-admin/pages/banners.php) ══════════ -->
    <?php if ($homeBanners !== []) : ?>
    <section class="crypto-section--tight banner-strip">
        <div class="crypto-container">
            <div class="banner-strip__grid">
                <?php foreach ($homeBanners as $banner) : ?>
                    <?= wpm_banner_markup($banner) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?= wpm_render_ad_slot($pdo, 'homepage-hero', 'homepage') ?>

    <!-- ══════════ FEATURED / PAMUNGKAS (admin-configurable) ══════════ -->
    <?php foreach ($featuredSections as $section) : ?>
        <?= wpm_render_featured_section($pdo, $section) ?>
    <?php endforeach; ?>

    <!-- ══════════ CRYPTO / MARKET DATA ══════════ -->
    <section id="market-update" class="crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Market</span>
                <h2 class="section-title">Harga <span>Crypto</span> Hari Ini</h2>
                <p class="section-subtitle"><?= $cryptoResult['ok'] ? 'Data live dari Crypto API.' : 'Crypto API belum aktif — hubungkan lewat dashboard admin untuk menampilkan data live.' ?></p>
            </div>
            <?php if ($cryptoMini !== []) : ?>
                <?= wpm_mini_crypto_table($cryptoMini) ?>
                <div style="text-align:center;margin-top:28px;">
                    <a class="crypto-btn crypto-btn--ghost" href="<?= wpm_esc(wpm_url_crypto()) ?>">Lihat Semua Harga <?= wpm_icon('arrow-right') ?></a>
                </div>
            <?php else : ?>
                <div class="empty-state"><?= wpm_icon('chart') ?><p>Data crypto belum tersedia saat ini.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <?= wpm_render_ad_slot($pdo, 'between-article-cards', 'homepage') ?>

    <!-- ══════════ LATEST NEWS ══════════ -->
    <section id="artikel" class="crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker">Terbaru</span>
                <h2 class="section-title">Berita <span>Terbaru</span></h2>
                <p class="section-subtitle">Kabar terkini seputar crypto, market, dan teknologi.</p>
            </div>
            <?php if ($latestArticles !== []) : ?>
                <div class="crypto-grid crypto-grid--3">
                    <?php foreach ($latestArticles as $article) : ?>
                        <?= wpm_article_card($article) ?>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="empty-state"><?= wpm_icon('news') ?><p>Belum ada artikel yang diterbitkan.</p></div>
            <?php endif; ?>
            <div style="text-align:center;margin-top:32px;">
                <a class="crypto-btn crypto-btn--ghost" href="<?= wpm_esc(wpm_url_kategori()) ?>">Lihat Semua Berita <?= wpm_icon('arrow-right') ?></a>
            </div>
        </div>
    </section>

    <!-- ══════════ TRENDING ══════════ -->
    <?php if ($trendingArticles !== []) : ?>
    <section class="crypto-section--tight">
        <div class="crypto-container">
            <div class="section-header">
                <span class="section-kicker"><?= wpm_icon('flame') ?> Trending</span>
                <h2 class="section-title">Sedang <span>Trending</span></h2>
            </div>
            <div class="crypto-grid crypto-grid--4">
                <?php foreach ($trendingArticles as $article) : ?>
                    <?= wpm_article_card($article) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

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

    <!-- ══════════ APP PROMOTION (placeholder — modul App Promotion belum aktif) ══════════ -->
    <section class="crypto-section--tight">
        <div class="crypto-container">
            <div class="glass-card crypto-card" style="padding:40px;text-align:center;">
                <div class="crypto-card__icon" style="margin:0 auto 16px;"><?= wpm_icon('rocket') ?></div>
                <h3 style="font-size:22px;">Aplikasi SagaCrypto — Segera Hadir</h3>
                <p style="max-width:480px;margin:0 auto 20px;">Pantau harga crypto langsung dari genggaman. Aplikasi Android &amp; iOS sedang dalam pengembangan.</p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <span class="crypto-btn crypto-btn--ghost app-store-badge" style="opacity:0.6;cursor:default;">
                        <span class="app-store-badge__icon"><?= wpm_icon('google-play') ?></span>
                        Google Play — Segera Hadir
                    </span>
                    <span class="crypto-btn crypto-btn--ghost app-store-badge" style="opacity:0.6;cursor:default;">
                        <span class="app-store-badge__icon"><?= wpm_icon('apple') ?></span>
                        App Store — Segera Hadir
                    </span>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════ NEWSLETTER ══════════ -->
    <section class="crypto-section--tight">
        <div class="crypto-container">
            <div class="glass-card crypto-card" style="padding:40px;text-align:center;">
                <h3 style="font-size:22px;">Jangan Ketinggalan Update</h3>
                <p style="max-width:480px;margin:0 auto 20px;">Daftarkan email kamu untuk menerima ringkasan berita crypto &amp; market terbaru.</p>
                <form method="post" action="contact-submit.php" style="display:flex;gap:10px;max-width:440px;margin:0 auto;flex-wrap:wrap;justify-content:center;">
                    <input type="hidden" name="full_name" value="Newsletter Subscriber">
                    <input type="hidden" name="subject" value="Newsletter">
                    <input class="form-input" style="flex:1;min-width:220px;" type="email" name="email" placeholder="nama@email.com" required>
                    <input type="hidden" name="message" value="Mendaftar newsletter SagaCrypto.">
                    <div class="hp-field" aria-hidden="true">
                        <label for="wpm-newsletter-website">Website</label>
                        <input type="text" id="wpm-newsletter-website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <button type="submit" class="crypto-btn crypto-btn--primary">Berlangganan</button>
                </form>
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

            <?php
            // Reuses the same $wpmSiteSettings fetched in includes/site-header.php
            // (required above, shares this file's top-level scope) — email,
            // WhatsApp, and Instagram/community link are all managed from
            // Site Settings in the admin panel. Falls back to "Segera hadir"
            // per-field whenever the admin hasn't filled that one in yet.
            $wpmContactEmail    = trim((string) ($wpmSiteSettings['email'] ?? ''));
            $wpmContactWa       = trim((string) ($wpmSiteSettings['whatsapp_number'] ?? ''));
            $wpmContactWaHref   = $wpmContactWa !== '' ? 'https://wa.me/' . preg_replace('/\D+/', '', $wpmContactWa) : '';
            $wpmContactCommunity = trim((string) ($wpmSiteSettings['instagram_url'] ?? ''));
            // Site Settings' "Instagram URL" field is sometimes filled with
            // just a handle (e.g. "saga.crypto") instead of a full link —
            // treat anything without a scheme as a handle and build the
            // instagram.com URL, so the link always works either way.
            $wpmContactCommunityHref = $wpmContactCommunity !== ''
                ? (preg_match('#^https?://#i', $wpmContactCommunity) === 1
                    ? $wpmContactCommunity
                    : 'https://instagram.com/' . ltrim($wpmContactCommunity, '@/'))
                : '';
            ?>
            <div class="contact-grid">
                <div class="glass-card contact-info-card">
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('mail') ?></span>
                        <span>
                            <span class="contact-info-row__label">Email</span><br>
                            <?php if ($wpmContactEmail !== '') : ?>
                                <a class="contact-info-row__value" href="mailto:<?= wpm_esc($wpmContactEmail) ?>"><?= wpm_esc($wpmContactEmail) ?></a>
                            <?php else : ?>
                                <span class="contact-info-row__value">Segera hadir</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('chat') ?></span>
                        <span>
                            <span class="contact-info-row__label">WhatsApp</span><br>
                            <?php if ($wpmContactWa !== '') : ?>
                                <a class="contact-info-row__value" href="<?= wpm_esc($wpmContactWaHref) ?>" target="_blank" rel="noopener"><?= wpm_esc($wpmContactWa) ?></a>
                            <?php else : ?>
                                <span class="contact-info-row__value">Segera hadir</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="contact-info-row">
                        <span class="contact-info-row__icon"><?= wpm_icon('pin') ?></span>
                        <span>
                            <span class="contact-info-row__label">Komunitas</span><br>
                            <?php if ($wpmContactCommunity !== '') : ?>
                                <a class="contact-info-row__value" href="<?= wpm_esc($wpmContactCommunityHref) ?>" target="_blank" rel="noopener"><?= wpm_esc($wpmContactCommunity) ?></a>
                            <?php else : ?>
                                <span class="contact-info-row__value">Segera hadir</span>
                            <?php endif; ?>
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
<?php require __DIR__ . '/includes/site-footer.php'; ?>
