<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/crypto-api.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'Crypto Dashboard';
$currentNav = 'crypto-dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Integrations', 'href' => ''],
    ['label' => 'Crypto Dashboard', 'href' => ''],
];

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$forceRefresh = isset($_GET['refresh']);
$result = cms_crypto_fetch_coins($pdo, $forceRefresh);

$statusLabel = [
    'live'         => 'Live data from API',
    'cache'        => 'Cached data (fresh)',
    'cache-stale'  => 'API failed — showing last known cached data',
    'inactive'     => 'Crypto API is not active — turn it on in Crypto API settings',
    'empty'        => 'No data available',
    'error'        => 'Configuration error',
][$result['source']] ?? $result['source'];

$statusType = 'muted';
if ($result['source'] === 'live' || $result['source'] === 'cache') {
    $statusType = 'ok';
} elseif ($result['source'] === 'cache-stale') {
    $statusType = 'accent';
} elseif (in_array($result['source'], ['empty', 'error'], true)) {
    $statusType = 'danger';
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Crypto Dashboard</h2>
            <p class="section-lead">Live preview of the coin data your Crypto API settings will serve to the frontend.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="<?= cms_esc(cms_nav_href('crypto-api.php')) ?>">API Settings</a>
            <a class="admin-btn admin-btn--primary" href="?refresh=1">Refresh Now</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Status</h3>
            <span class="pill pill--<?= $statusType ?>"><?= cms_esc($statusLabel) ?></span>
        </div>
        <?php if ($result['fetched_at']) : ?>
            <p class="muted" style="font-size:12px;">Last updated: <code><?= cms_esc((string) $result['fetched_at']) ?></code></p>
        <?php endif; ?>
        <?php if (!empty($result['error'])) : ?>
            <p class="muted" style="font-size:12px;color:#ff6b6b;">Last error: <?= cms_esc((string) $result['error']) ?></p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Coins</h3>
            <span class="panel__meta"><?= count($result['data']) ?> coin(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Coin</th>
                        <th>Symbol</th>
                        <th>Price</th>
                        <th>24h change</th>
                        <th>Market cap</th>
                        <th>Volume</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['data'] === []) : ?>
                        <tr><td colspan="7" class="muted">No coin data to show yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['data'] as $i => $coin) : ?>
                        <?php
                        if (!is_array($coin)) {
                            continue;
                        }
                        $change = $coin['price_change_percentage_24h'] ?? null;
                        $changeClass = $change === null ? 'muted' : ($change >= 0 ? 'ok' : 'danger');
                        ?>
                        <tr>
                            <td><?= (int) ($coin['market_cap_rank'] ?? ($i + 1)) ?></td>
                            <td>
                                <?php if (!empty($coin['image'])) : ?>
                                    <img src="<?= cms_esc((string) $coin['image']) ?>" alt="" style="width:20px;height:20px;border-radius:50%;vertical-align:middle;margin-right:6px;">
                                <?php endif; ?>
                                <?= cms_esc((string) ($coin['name'] ?? '—')) ?>
                            </td>
                            <td><code><?= cms_esc(strtoupper((string) ($coin['symbol'] ?? ''))) ?></code></td>
                            <td><?= cms_esc(number_format((float) ($coin['current_price'] ?? 0), 2)) ?></td>
                            <td><span class="pill pill--<?= $changeClass ?>"><?= $change !== null ? cms_esc(number_format((float) $change, 2) . '%') : '—' ?></span></td>
                            <td><?= cms_esc(number_format((float) ($coin['market_cap'] ?? 0))) ?></td>
                            <td><?= cms_esc(number_format((float) ($coin['total_volume'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
