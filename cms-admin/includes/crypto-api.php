<?php
declare(strict_types=1);

/**
 * Crypto API integration helpers.
 *
 * Provider-agnostic: base URL, endpoint, API key and header name are all
 * stored in the `crypto_api_settings` table and edited from
 * cms-admin/pages/crypto-api.php — nothing is hardcoded here. Defaults point
 * at CoinGecko's public markets endpoint (no key required) since the exact
 * provider was not yet decided at build time; swapping providers only
 * requires editing the settings row from the admin dashboard.
 *
 * Resilience: every fetch is cached in `crypto_cache` for cache_duration
 * seconds. On a failed live fetch we fall back to the last good cache row
 * (however old) rather than showing nothing, and every failure is written
 * to `api_error_log` for the admin to review. The public site must never
 * crash or block on this — callers should always get an array back.
 */

require_once __DIR__ . '/schema-guard.php';

if (!function_exists('cms_crypto_ensure_schema')) {
    function cms_crypto_ensure_schema(PDO $pdo): void
    {
        $created = cms_ensure_table(
            $pdo,
            'crypto_api_settings',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             provider VARCHAR(100) NOT NULL DEFAULT \'CoinGecko\',
             base_url VARCHAR(255) NOT NULL DEFAULT \'https://api.coingecko.com/api/v3\',
             endpoint VARCHAR(255) NOT NULL DEFAULT \'/coins/markets\',
             api_key VARCHAR(255) DEFAULT NULL,
             api_key_header VARCHAR(100) DEFAULT NULL,
             default_currency VARCHAR(10) NOT NULL DEFAULT \'usd\',
             coins_limit INT UNSIGNED NOT NULL DEFAULT 10,
             refresh_interval INT UNSIGNED NOT NULL DEFAULT 60,
             cache_duration INT UNSIGNED NOT NULL DEFAULT 300,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             last_test_status VARCHAR(20) DEFAULT NULL,
             last_test_message VARCHAR(255) DEFAULT NULL,
             last_test_at TIMESTAMP NULL DEFAULT NULL,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        // Live Ticker — a site-wide price bar (assets/js/live-ticker.js)
        // that polls crypto-ticker-data.php on an interval, which reuses
        // this same CoinGecko cache (no extra external calls). Originally
        // connected directly to Binance's public WebSocket, but that
        // domain is blocked at the ISP level in Indonesia, so it was
        // switched to server-side polling instead. Off by default until an
        // admin turns it on.
        cms_ensure_column($pdo, 'crypto_api_settings', 'live_ticker_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
        cms_ensure_column($pdo, 'crypto_api_settings', 'live_ticker_symbols', 'VARCHAR(255) NOT NULL DEFAULT \'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT\' AFTER `live_ticker_enabled`');

        if ($created) {
            $pdo->exec('INSERT INTO crypto_api_settings (provider) VALUES (\'CoinGecko\')');
        }

        cms_ensure_table(
            $pdo,
            'crypto_cache',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             payload LONGTEXT NOT NULL,
             fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
        );

        cms_ensure_table(
            $pdo,
            'crypto_coin_settings',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             symbol VARCHAR(20) NOT NULL,
             display_name VARCHAR(100) DEFAULT NULL,
             sort_order INT NOT NULL DEFAULT 0,
             is_visible TINYINT(1) NOT NULL DEFAULT 1,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_crypto_coin_symbol (symbol)'
        );

        cms_ensure_table(
            $pdo,
            'api_error_log',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             source VARCHAR(30) NOT NULL,
             message TEXT NOT NULL,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             KEY idx_api_error_source (source)'
        );
    }
}

if (!function_exists('cms_crypto_get_settings')) {
    function cms_crypto_get_settings(PDO $pdo): array
    {
        cms_crypto_ensure_schema($pdo);
        $row = $pdo->query('SELECT * FROM crypto_api_settings ORDER BY id ASC LIMIT 1')->fetch();
        return $row !== false ? $row : [];
    }
}

if (!function_exists('cms_crypto_live_ticker_settings')) {
    /**
     * @return array{enabled:bool,symbols:string[]}
     */
    function cms_crypto_live_ticker_settings(PDO $pdo): array
    {
        $settings = cms_crypto_get_settings($pdo);
        $enabled = (int) ($settings['live_ticker_enabled'] ?? 0) === 1;
        $raw = (string) ($settings['live_ticker_symbols'] ?? '');
        $symbols = array_values(array_filter(array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            explode(',', $raw)
        )));
        return ['enabled' => $enabled && $symbols !== [], 'symbols' => $symbols];
    }
}

if (!function_exists('cms_crypto_log_error')) {
    function cms_crypto_log_error(PDO $pdo, string $message): void
    {
        try {
            cms_crypto_ensure_schema($pdo);
            $pdo->prepare('INSERT INTO api_error_log (source, message, created_at) VALUES (\'crypto\', :message, NOW())')
                ->execute(['message' => mb_substr($message, 0, 2000)]);
        } catch (Throwable $e) {
            // Logging must never break the caller.
        }
    }
}

if (!function_exists('cms_crypto_build_url')) {
    function cms_crypto_build_url(array $settings): string
    {
        $base = rtrim((string) ($settings['base_url'] ?? ''), '/');
        $endpoint = '/' . ltrim((string) ($settings['endpoint'] ?? ''), '/');
        $params = [
            'vs_currency' => (string) ($settings['default_currency'] ?? 'usd'),
            'order'       => 'market_cap_desc',
            'per_page'    => (int) ($settings['coins_limit'] ?? 10),
            'page'        => 1,
            'sparkline'   => 'false',
        ];
        return $base . $endpoint . '?' . http_build_query($params);
    }
}

if (!function_exists('cms_crypto_http_get')) {
    /**
     * @return array{ok:bool,status:int,body:string,error:string|null}
     */
    function cms_crypto_http_get(string $url, array $settings): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL extension is not available on this server.'];
        }

        $headers = ['Accept: application/json'];
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $headerName = trim((string) ($settings['api_key_header'] ?? ''));
        if ($apiKey !== '' && $headerName !== '') {
            $headers[] = $headerName . ': ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WPM-CryptoWidget/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if ($body === false || $error !== null) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?? 'Unknown cURL error'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $body, 'error' => 'HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string) $body, 'error' => null];
    }
}

if (!function_exists('cms_crypto_fetch_coins')) {
    /**
     * Main entry point for both the admin dashboard and (later) the public
     * front-end. Always returns an array — never throws — so a broken or
     * unconfigured API can never take the site down.
     *
     * @return array{ok:bool,source:string,data:array,error:?string,fetched_at:?string}
     */
    function cms_crypto_fetch_coins(PDO $pdo, bool $forceRefresh = false): array
    {
        try {
            cms_crypto_ensure_schema($pdo);
            $settings = cms_crypto_get_settings($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'source' => 'error', 'data' => [], 'error' => $e->getMessage(), 'fetched_at' => null];
        }

        if ((int) ($settings['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'source' => 'inactive', 'data' => [], 'error' => 'Crypto API is not active.', 'fetched_at' => null];
        }

        $cacheDuration = max(0, (int) ($settings['cache_duration'] ?? 300));
        $cacheRow = null;
        try {
            $cacheRow = $pdo->query('SELECT * FROM crypto_cache ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        } catch (Throwable $e) {
            $cacheRow = null;
        }

        if (!$forceRefresh && $cacheRow !== null) {
            $age = time() - strtotime((string) $cacheRow['fetched_at']);
            if ($age >= 0 && $age < $cacheDuration) {
                $decoded = json_decode((string) $cacheRow['payload'], true);
                if (is_array($decoded)) {
                    return ['ok' => true, 'source' => 'cache', 'data' => $decoded, 'error' => null, 'fetched_at' => (string) $cacheRow['fetched_at']];
                }
            }
        }

        $url = cms_crypto_build_url($settings);
        $result = cms_crypto_http_get($url, $settings);

        if ($result['ok']) {
            $decoded = json_decode($result['body'], true);
            if (is_array($decoded)) {
                try {
                    $pdo->prepare('INSERT INTO crypto_cache (payload, fetched_at) VALUES (:payload, NOW())')
                        ->execute(['payload' => json_encode($decoded)]);
                    // Keep only the most recent 20 cache rows.
                    $pdo->exec('DELETE FROM crypto_cache WHERE id NOT IN (SELECT id FROM (SELECT id FROM crypto_cache ORDER BY id DESC LIMIT 20) t)');
                } catch (Throwable $e) {
                    // Cache write failure is non-fatal — we still have fresh data to return.
                }
                return ['ok' => true, 'source' => 'live', 'data' => $decoded, 'error' => null, 'fetched_at' => date('Y-m-d H:i:s')];
            }
            cms_crypto_log_error($pdo, 'Crypto API returned an unparseable response from ' . $url);
        } else {
            cms_crypto_log_error($pdo, 'Crypto API request failed (' . ($result['error'] ?? 'unknown') . ') for ' . $url);
        }

        // Fall back to whatever cache we have, however stale, before giving up.
        if ($cacheRow !== null) {
            $decoded = json_decode((string) $cacheRow['payload'], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'source' => 'cache-stale', 'data' => $decoded, 'error' => $result['error'] ?? null, 'fetched_at' => (string) $cacheRow['fetched_at']];
            }
        }

        return ['ok' => false, 'source' => 'empty', 'data' => [], 'error' => $result['error'] ?? 'Unknown error', 'fetched_at' => null];
    }
}

if (!function_exists('cms_crypto_test_connection')) {
    /**
     * Used by the "Test Connection" button — performs a live request using
     * either the persisted settings or an override array (unsaved form
     * values), bypassing the cache entirely. Never touches crypto_cache.
     *
     * @return array{ok:bool,message:string,sample:array}
     */
    function cms_crypto_test_connection(PDO $pdo, array $overrideSettings = []): array
    {
        try {
            cms_crypto_ensure_schema($pdo);
            $settings = array_merge(cms_crypto_get_settings($pdo), $overrideSettings);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'sample' => []];
        }

        $url = cms_crypto_build_url($settings);
        $result = cms_crypto_http_get($url, $settings);

        if (!$result['ok']) {
            cms_crypto_log_error($pdo, 'Test connection failed (' . ($result['error'] ?? 'unknown') . ') for ' . $url);
            return ['ok' => false, 'message' => $result['error'] ?? ('HTTP ' . $result['status']), 'sample' => []];
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded) || $decoded === []) {
            return ['ok' => false, 'message' => 'Connected, but the response was empty or not valid JSON.', 'sample' => []];
        }

        return ['ok' => true, 'message' => 'Connected successfully. Received ' . count($decoded) . ' item(s).', 'sample' => array_slice($decoded, 0, 3)];
    }
}
