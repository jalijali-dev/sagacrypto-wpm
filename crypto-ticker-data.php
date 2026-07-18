<?php
declare(strict_types=1);

/**
 * JSON endpoint powering the site-wide Live Ticker bar (assets/js/live-ticker.js).
 *
 * Originally the ticker connected client-side to Binance's public WebSocket
 * stream. That was switched to server-side polling of our existing CoinGecik
 * Crypto API cache after confirming binance.com (and its stream subdomain)
 * is blocked at the ISP level in Indonesia by Kominfo/Bappebti — a nationwide
 * block that no port/reconnect fix could work around, since it's the domain
 * itself that's unreachable for visitors browsing from Indonesian networks.
 *
 * This endpoint reuses cms_crypto_fetch_coins() — the SAME cached data that
 * already powers the homepage/crypto.php market tables — so it makes no
 * extra external API calls of its own. Freshness is bounded by the Crypto
 * API's own cache_duration setting (default 300s), which is an accepted
 * trade-off for "live-ish" data over needing a wall we can't get through.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$tickerSettings = cms_crypto_live_ticker_settings($pdo);
if (!$tickerSettings['enabled']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Live ticker is disabled.']);
    exit;
}

$requested = array_values(array_filter(array_map(
    static fn (string $s): string => strtoupper(trim($s)),
    explode(',', (string) ($_GET['symbols'] ?? ''))
)));
if ($requested === []) {
    $requested = $tickerSettings['symbols'];
}

/**
 * Live Ticker symbols are stored Binance-pair style (e.g. BTCUSDT) for
 * backward compatibility with the original build. Strip the quote-currency
 * suffix to get the base symbol CoinGecko uses (e.g. BTC).
 */
function wpm_ticker_base_symbol(string $pair): string
{
    $pair = strtoupper($pair);
    foreach (['USDT', 'BUSD', 'USDC', 'TUSD', 'USD', 'IDR'] as $quote) {
        if (str_ends_with($pair, $quote) && strlen($pair) > strlen($quote)) {
            return substr($pair, 0, -strlen($quote));
        }
    }
    return $pair;
}

$result = cms_crypto_fetch_coins($pdo);

$bySymbol = [];
foreach ($result['data'] as $coin) {
    $sym = strtoupper((string) ($coin['symbol'] ?? ''));
    if ($sym !== '') {
        $bySymbol[$sym] = $coin;
    }
}

$coins = [];
foreach ($requested as $pair) {
    $base = wpm_ticker_base_symbol($pair);
    $coin = $bySymbol[$base] ?? null;
    $coins[$pair] = $coin ? [
        'price'      => (float) ($coin['current_price'] ?? 0),
        'change_pct' => isset($coin['price_change_percentage_24h']) ? (float) $coin['price_change_percentage_24h'] : null,
    ] : null;
}

echo json_encode([
    'ok'         => $result['ok'],
    'coins'      => $coins,
    'fetched_at' => $result['fetched_at'],
]);
