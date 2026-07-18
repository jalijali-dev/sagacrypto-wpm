<?php
declare(strict_types=1);

/**
 * SagaCrypto — full Crypto market page. Pulls live coin data through
 * cms_crypto_fetch_coins() (same helper the admin dashboard uses), with
 * the same fallback contract: cached data on API failure, clean empty
 * state if there's no cache at all, never a fatal error.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$forceRefresh = isset($_GET['refresh']);
$result = cms_crypto_fetch_coins($pdo, $forceRefresh);
$settings = cms_crypto_get_settings($pdo);

$statusType = 'off';
$statusText = 'Crypto API belum aktif.';
if ($result['source'] === 'live') {
    $statusType = 'ok';
    $statusText = 'Data live — diperbarui ' . wpm_format_date($result['fetched_at'], 'd M Y, H:i');
} elseif ($result['source'] === 'cache') {
    $statusType = 'ok';
    $statusText = 'Data cache (baru) — diperbarui ' . wpm_format_date($result['fetched_at'], 'd M Y, H:i');
} elseif ($result['source'] === 'cache-stale') {
    $statusType = 'warn';
    $statusText = 'Koneksi API sedang bermasalah — menampilkan data terakhir yang tersimpan (' . wpm_format_date($result['fetched_at'], 'd M Y, H:i') . ').';
}

$pageTitle = 'Harga Crypto Hari Ini — SagaCrypto';
$pageDescription = 'Pantau harga Bitcoin, Ethereum, dan koin crypto lainnya secara real-time di SagaCrypto.';
$activeNav = 'crypto';
$canonicalUrl = wpm_site_url(wpm_url_crypto());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Crypto</nav>
        <span class="section-kicker">Market</span>
        <h1>Harga Crypto Hari Ini</h1>
        <p>Data harga, perubahan 24 jam, market cap, dan volume — diperbarui otomatis.</p>
    </div>
</section>

<section class="crypto-section--tight" <?php if ($result['ok'] && (int) ($settings['refresh_interval'] ?? 0) > 0) : ?>data-auto-refresh="<?= (int) $settings['refresh_interval'] ?>"<?php endif; ?>>
    <div class="crypto-container">
        <div class="status-banner status-banner--<?= $statusType ?>"><?= wpm_icon('chart') ?> <?= wpm_esc($statusText) ?></div>

        <?php if ($result['data'] !== []) :
            $chartCoins = array_slice($result['data'], 0, 10);
            $chartMaxCap = 0.0;
            foreach ($chartCoins as $chartCoin) {
                $chartMaxCap = max($chartMaxCap, (float) ($chartCoin['market_cap'] ?? 0));
            }
        ?>
            <div class="market-chart">
                <div class="market-chart__head">
                    <h2>Top 10 Market Cap</h2>
                    <span class="market-chart__hint">Kapitalisasi pasar (USD) — data live dari <?= wpm_esc((string) ($settings['provider'] ?? 'penyedia API')) ?></span>
                </div>
                <div class="market-chart__bars">
                    <?php foreach ($chartCoins as $chartCoin) :
                        $chartCap    = (float) ($chartCoin['market_cap'] ?? 0);
                        $chartPct    = $chartMaxCap > 0 ? max(4, (int) round($chartCap / $chartMaxCap * 100)) : 4;
                        $chartChange = $chartCoin['price_change_percentage_24h'] ?? null;
                        $chartUp     = $chartChange === null || (float) $chartChange >= 0;
                        $chartSymbol = strtoupper((string) ($chartCoin['symbol'] ?? '?'));
                        $chartTitle  = ($chartCoin['name'] ?? $chartSymbol) . ': $' . number_format($chartCap);
                    ?>
                        <div class="market-chart__col" title="<?= wpm_esc($chartTitle) ?>">
                            <span class="market-chart__value">$<?= wpm_esc(wpm_format_compact_number($chartCap)) ?></span>
                            <div class="market-chart__track">
                                <div class="market-chart__bar <?= $chartUp ? 'is-up' : 'is-down' ?>" style="height: <?= $chartPct ?>%"></div>
                            </div>
                            <span class="market-chart__label"><?= wpm_esc($chartSymbol) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?= wpm_render_ad_slot($pdo, 'above-article', 'crypto') ?>

        <?php if ($result['data'] !== []) : ?>
            <div class="crypto-table-wrap">
                <table class="crypto-table">
                    <thead>
                        <tr><th>#</th><th>Coin</th><th>Harga</th><th>24h</th><th>Market Cap</th><th>Volume</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['data'] as $i => $coin) : ?>
                            <?php
                            if (!is_array($coin)) {
                                continue;
                            }
                            $change = $coin['price_change_percentage_24h'] ?? null;
                            $changeClass = $change === null ? '' : ($change >= 0 ? 'crypto-table__change--up' : 'crypto-table__change--down');
                            ?>
                            <tr>
                                <td><?= (int) ($coin['market_cap_rank'] ?? ($i + 1)) ?></td>
                                <td>
                                    <div class="crypto-table__coin">
                                        <?php if (!empty($coin['image'])) : ?><img src="<?= wpm_esc((string) $coin['image']) ?>" alt=""><?php endif; ?>
                                        <span><?= wpm_esc((string) ($coin['name'] ?? '—')) ?> <span class="crypto-table__symbol"><?= wpm_esc(strtoupper((string) ($coin['symbol'] ?? ''))) ?></span></span>
                                    </div>
                                </td>
                                <td>$<?= wpm_esc(number_format((float) ($coin['current_price'] ?? 0), 2)) ?></td>
                                <td class="<?= $changeClass ?>"><?= $change !== null ? wpm_esc(number_format((float) $change, 2) . '%') : '—' ?></td>
                                <td>$<?= wpm_esc(number_format((float) ($coin['market_cap'] ?? 0))) ?></td>
                                <td>$<?= wpm_esc(number_format((float) ($coin['total_volume'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted" style="font-size:12.5px;color:var(--text-faint);margin-top:16px;">Data disediakan oleh <?= wpm_esc((string) ($settings['provider'] ?? 'penyedia API')) ?>. Bukan merupakan saran keuangan/investasi.</p>
        <?php else : ?>
            <div class="empty-state"><?= wpm_icon('chart') ?><p>Data crypto belum tersedia saat ini. Silakan cek kembali nanti.</p></div>
        <?php endif; ?>

        <?= wpm_render_ad_slot($pdo, 'below-article', 'crypto') ?>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
