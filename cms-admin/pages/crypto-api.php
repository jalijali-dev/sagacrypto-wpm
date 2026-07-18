<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/crypto-api.php';

// Holds raw Crypto API keys — superadmin-only. See cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin']);

$ca_schemaError = null;
try {
    cms_crypto_ensure_schema($pdo);
} catch (Throwable $e) {
    $ca_schemaError = $e->getMessage();
}

$pageTitle = 'Crypto API';
$currentNav = 'crypto-api';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Integrations', 'href' => ''],
    ['label' => 'Crypto API', 'href' => ''],
];

$selfUrl = 'crypto-api.php';

$ca_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $provider        = trim((string) ($_POST['provider'] ?? ''));
    $baseUrl         = trim((string) ($_POST['base_url'] ?? ''));
    $endpoint        = trim((string) ($_POST['endpoint'] ?? ''));
    $apiKeyHeader    = trim((string) ($_POST['api_key_header'] ?? ''));
    $defaultCurrency = trim((string) ($_POST['default_currency'] ?? 'usd')) ?: 'usd';
    $coinsLimit      = max(1, min(250, (int) ($_POST['coins_limit'] ?? 10)));
    $refreshInterval = max(10, (int) ($_POST['refresh_interval'] ?? 60));
    $cacheDuration   = max(30, (int) ($_POST['cache_duration'] ?? 300));
    $isActive        = !empty($_POST['is_active']) ? 1 : 0;
    $apiKeyInput     = (string) ($_POST['api_key'] ?? '');
    $liveTickerEnabled = !empty($_POST['live_ticker_enabled']) ? 1 : 0;
    $liveTickerSymbols = strtoupper(trim((string) ($_POST['live_ticker_symbols'] ?? '')));
    // Normalise to a clean, deduplicated CSV of A-Z0-9 symbols.
    $liveTickerSymbols = implode(',', array_values(array_unique(array_filter(
        array_map('trim', explode(',', $liveTickerSymbols)),
        static fn (string $s): bool => $s !== '' && preg_match('/^[A-Z0-9]{2,20}$/', $s) === 1
    ))));

    if ($provider === '' || $baseUrl === '' || $endpoint === '') {
        $ca_redirect('Provider, base URL, and endpoint are required.', 'error');
    }

    $params = [
        'provider'          => $provider,
        'base_url'          => $baseUrl,
        'endpoint'          => $endpoint,
        'api_key_header'    => $apiKeyHeader !== '' ? $apiKeyHeader : null,
        'default_currency'  => $defaultCurrency,
        'coins_limit'       => $coinsLimit,
        'refresh_interval'  => $refreshInterval,
        'cache_duration'    => $cacheDuration,
        'is_active'         => $isActive,
        'live_ticker_enabled' => $liveTickerEnabled,
        'live_ticker_symbols' => $liveTickerSymbols !== '' ? $liveTickerSymbols : 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT',
    ];

    $sql = 'UPDATE crypto_api_settings
            SET provider = :provider, base_url = :base_url, endpoint = :endpoint,
                api_key_header = :api_key_header, default_currency = :default_currency,
                coins_limit = :coins_limit, refresh_interval = :refresh_interval,
                cache_duration = :cache_duration, is_active = :is_active,
                live_ticker_enabled = :live_ticker_enabled, live_ticker_symbols = :live_ticker_symbols';

    // Only overwrite the stored API key if the admin actually typed a new
    // one — the field is left blank on reload so the key is never echoed
    // back into the page source.
    if (trim($apiKeyInput) !== '') {
        $sql .= ', api_key = :api_key';
        $params['api_key'] = trim($apiKeyInput);
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';

    $pdo->prepare($sql)->execute($params);
    $ca_redirect('Crypto API settings saved.');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($ca_schemaError !== null) {
    $alerts[] = ['type' => 'error', 'message' => 'Crypto API setup could not run automatically: ' . $ca_schemaError];
}

$settings = cms_crypto_get_settings($pdo);
$hasKey = !empty($settings['api_key']);

$recentErrors = [];
try {
    $stmt = $pdo->prepare('SELECT message, created_at FROM api_error_log WHERE source = \'crypto\' ORDER BY id DESC LIMIT 10');
    $stmt->execute();
    $recentErrors = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentErrors = [];
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
            <h2 class="section-title">Crypto API Integration</h2>
            <p class="section-lead">Connect a crypto market-data provider. The API key is stored server-side only and is never exposed in the page source or frontend files.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="<?= cms_esc(cms_nav_href('crypto-dashboard.php')) ?>">View Dashboard</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Connection Settings</h3>
            <span class="pill pill--<?= (int) ($settings['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                <?= (int) ($settings['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
            </span>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" id="crypto-api-form">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">

            <label class="field">Provider name
                <input type="text" name="provider" required value="<?= cms_esc((string) ($settings['provider'] ?? '')) ?>" placeholder="e.g. CoinGecko, CoinMarketCap">
            </label>
            <label class="field">Base URL
                <input type="text" name="base_url" required value="<?= cms_esc((string) ($settings['base_url'] ?? '')) ?>" placeholder="https://api.example.com/v3">
            </label>
            <label class="field">Endpoint
                <input type="text" name="endpoint" required value="<?= cms_esc((string) ($settings['endpoint'] ?? '')) ?>" placeholder="/coins/markets">
            </label>
            <label class="field">API key <small style="opacity:.7;"><?= $hasKey ? '(saved — leave blank to keep it)' : '(not set)' ?></small>
                <input type="password" name="api_key" autocomplete="off" placeholder="<?= $hasKey ? '••••••••••••' : 'Paste API key here' ?>">
            </label>
            <label class="field">API key header name <small style="opacity:.7;">(e.g. x-cg-demo-api-key)</small>
                <input type="text" name="api_key_header" value="<?= cms_esc((string) ($settings['api_key_header'] ?? '')) ?>" placeholder="Leave blank if no key required">
            </label>
            <label class="field">Default currency
                <input type="text" name="default_currency" value="<?= cms_esc((string) ($settings['default_currency'] ?? 'usd')) ?>" placeholder="usd">
            </label>
            <label class="field">Number of coins shown
                <input type="number" name="coins_limit" min="1" max="250" value="<?= (int) ($settings['coins_limit'] ?? 10) ?>">
            </label>
            <label class="field">Refresh interval (seconds, frontend)
                <input type="number" name="refresh_interval" min="10" value="<?= (int) ($settings['refresh_interval'] ?? 60) ?>">
            </label>
            <label class="field">Cache duration (seconds, server)
                <input type="number" name="cache_duration" min="30" value="<?= (int) ($settings['cache_duration'] ?? 300) ?>">
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_active" value="1"<?= (int) ($settings['is_active'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Crypto API active</span>
                </span>
            </label>

            <div class="settings-card" style="grid-column: 1 / -1;">
                <h4 class="settings-card__title">Live Ticker</h4>
                <p class="settings-card__desc">
                    Tampilkan informasi harga aset kripto secara otomatis di seluruh halaman website. Data diperbarui setiap 30 detik melalui cache server untuk menjaga performa, stabilitas, dan kecepatan akses. Gunakan format pasangan USDT, misalnya BTCUSDT, ETHUSDT, atau BNBUSDT.
                </p>

                <label class="field field--checkbox" style="margin-top: 14px;">
                    <input type="checkbox" name="live_ticker_enabled" value="1"<?= (int) ($settings['live_ticker_enabled'] ?? 0) === 1 ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Aktifkan live ticker di frontend</span>
                        <span class="field--checkbox__desc">Menampilkan ticker harga kripto secara otomatis pada halaman website.</span>
                    </span>
                </label>

                <label class="field" style="margin-top: 14px;">Simbol aset
                    <input type="text" name="live_ticker_symbols" value="<?= cms_esc((string) ($settings['live_ticker_symbols'] ?? 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT')) ?>" placeholder="BTCUSDT, ETHUSDT, BNBUSDT, SOLUSDT, XRPUSDT">
                    <small class="field__hint">Pisahkan setiap simbol dengan koma. Gunakan format pasangan USDT.</small>
                </label>
            </div>

            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save Settings</button>
                <button type="button" class="admin-btn admin-btn--secondary" id="ca-test-btn">Test Connection</button>
            </div>
            <div id="ca-test-result" class="muted" style="grid-column: 1 / -1; font-size: 13px;"></div>
        </form>
        <?php if (!empty($settings['last_test_at'])) : ?>
            <p class="muted" style="font-size:12px;margin-top:10px;">
                Last test: <strong><?= cms_esc((string) $settings['last_test_status']) ?></strong>
                — <?= cms_esc((string) $settings['last_test_message']) ?>
                (<?= cms_esc((string) $settings['last_test_at']) ?>)
            </p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Recent Errors</h3>
            <span class="panel__meta"><?= count($recentErrors) ?> logged</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Message</th><th>When</th></tr></thead>
                <tbody>
                    <?php if ($recentErrors === []) : ?>
                        <tr><td colspan="2" class="muted">No errors logged — good sign.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentErrors as $err) : ?>
                        <tr>
                            <td><?= cms_esc((string) $err['message']) ?></td>
                            <td><code><?= cms_esc((string) $err['created_at']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
(function () {
    var btn = document.getElementById('ca-test-btn');
    var out = document.getElementById('ca-test-result');
    var form = document.getElementById('crypto-api-form');
    if (!btn) { return; }

    btn.addEventListener('click', function () {
        out.textContent = 'Testing connection…';
        var fd = new FormData(form);
        fetch('<?= cms_esc(cms_action_href('crypto-api-test.php')) ?>', {
            method: 'POST',
            body: fd,
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                out.textContent = (json.ok ? '✔ ' : '✘ ') + json.message;
                out.style.color = json.ok ? '#3ddc84' : '#ff6b6b';
            })
            .catch(function () {
                out.textContent = '✘ Request failed — check the browser console.';
                out.style.color = '#ff6b6b';
            });
    });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
