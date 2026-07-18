<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/crypto-api.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$cs_schemaError = null;
try {
    cms_crypto_ensure_schema($pdo);
} catch (Throwable $e) {
    $cs_schemaError = $e->getMessage();
}

$pageTitle = 'Coin Settings';
$currentNav = 'coin-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Integrations', 'href' => ''],
    ['label' => 'Coin Settings', 'href' => ''],
];

$selfUrl = 'coin-settings.php';

$cs_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM crypto_coin_settings WHERE id = :id')->execute(['id' => $id]);
        $cs_redirect('Coin override removed.');
    }

    if ($action === 'create' || $action === 'update') {
        $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isVisible = !empty($_POST['is_visible']) ? 1 : 0;

        if ($symbol === '') {
            $cs_redirect('Symbol is required (e.g. BTC, ETH).', 'error');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO crypto_coin_settings (symbol, display_name, sort_order, is_visible, created_at)
                 VALUES (:symbol, :display_name, :sort_order, :is_visible, NOW())
                 ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), sort_order = VALUES(sort_order), is_visible = VALUES(is_visible)'
            );
            $stmt->execute([
                'symbol' => $symbol,
                'display_name' => $displayName !== '' ? $displayName : null,
                'sort_order' => $sortOrder,
                'is_visible' => $isVisible,
            ]);
            $cs_redirect('Coin override saved.');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare(
            'UPDATE crypto_coin_settings
             SET symbol = :symbol, display_name = :display_name, sort_order = :sort_order, is_visible = :is_visible
             WHERE id = :id'
        );
        $stmt->execute([
            'symbol' => $symbol,
            'display_name' => $displayName !== '' ? $displayName : null,
            'sort_order' => $sortOrder,
            'is_visible' => $isVisible,
            'id' => $id,
        ]);
        $cs_redirect('Coin override updated.');
    }

    $cs_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($cs_schemaError !== null) {
    $alerts[] = ['type' => 'error', 'message' => 'Coin settings setup could not run automatically: ' . $cs_schemaError];
}

$coins = $pdo->query('SELECT * FROM crypto_coin_settings ORDER BY sort_order ASC, symbol ASC')->fetchAll();

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Coin Settings</h2>
            <p class="section-lead">Optional overrides for coins returned by the Crypto API — rename a coin for display, pin its sort order, or hide it from the frontend without touching the API settings.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3 class="panel__title">Add / Update Coin Override</h3></div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Symbol
                <input type="text" name="symbol" required placeholder="BTC" maxlength="20">
            </label>
            <label class="field">Display name
                <input type="text" name="display_name" placeholder="Bitcoin">
            </label>
            <label class="field">Sort order
                <input type="number" name="sort_order" value="0">
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_visible" value="1" checked>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Visible on frontend</span>
                </span>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save Override</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Overrides</h3>
            <span class="panel__meta"><?= count($coins) ?> coin(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Symbol</th><th>Display name</th><th>Sort order</th><th>Visible</th><th></th></tr></thead>
                <tbody>
                    <?php if ($coins === []) : ?>
                        <tr><td colspan="5" class="muted">No overrides yet — all API coins will show as-is.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($coins as $coin) : ?>
                        <tr>
                            <td><code><?= cms_esc((string) $coin['symbol']) ?></code></td>
                            <td><?= cms_esc((string) ($coin['display_name'] ?? '—')) ?></td>
                            <td><?= (int) $coin['sort_order'] ?></td>
                            <td><span class="pill pill--<?= (int) $coin['is_visible'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $coin['is_visible'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Remove this override?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $coin['id'] ?>">
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
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
