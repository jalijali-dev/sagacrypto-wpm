<?php
declare(strict_types=1);

/**
 * Market Sentiment (BTC Dominance + Fear and Greed Index) helpers.
 *
 * Fase A of the "Crypto Intelligence" repositioning — see
 * docs/MARKET_SENTIMENT_PLAN.md. Two hardcoded, free, no-key public
 * sources (CoinGecko `/global`, Alternative.me `/fng/`) — unlike
 * crypto-api.php this is not provider-agnostic, there's nothing for the
 * admin to configure besides an on/off toggle.
 *
 * Resilience: same pattern as cms_crypto_fetch_coins() — cache-first,
 * fall back to stale cache on a failed live fetch, log every failure to
 * api_error_log (source 'market_sentiment'), never throw. Callers always
 * get an array back, and both fetchers no-op (ok=false, no error logged)
 * when market_sentiment_enabled = 0.
 */

require_once __DIR__ . '/schema-guard.php';
require_once __DIR__ . '/crypto-api.php';

if (!function_exists('cms_market_sentiment_ensure_schema')) {
    function cms_market_sentiment_ensure_schema(PDO $pdo): void
    {
        cms_ensure_table(
            $pdo,
            'market_sentiment_cache',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             metric VARCHAR(30) NOT NULL,
             payload LONGTEXT NOT NULL,
             fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             KEY idx_market_sentiment_metric (metric)'
        );

        // crypto_api_settings itself is created by cms_crypto_ensure_schema()
        // (crypto-api.php) — reuse it rather than duplicating the CREATE
        // TABLE here, then bolt on the two Market Sentiment columns.
        cms_crypto_ensure_schema($pdo);
        cms_ensure_column($pdo, 'crypto_api_settings', 'market_sentiment_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `live_ticker_symbols`');
        cms_ensure_column($pdo, 'crypto_api_settings', 'market_sentiment_cache_duration', 'INT UNSIGNED NOT NULL DEFAULT 900 AFTER `market_sentiment_enabled`');
    }
}

if (!function_exists('cms_market_sentiment_settings')) {
    /**
     * @return array{enabled:bool,cache_duration:int}
     */
    function cms_market_sentiment_settings(PDO $pdo): array
    {
        cms_market_sentiment_ensure_schema($pdo);
        $settings = cms_crypto_get_settings($pdo);
        return [
            'enabled'        => (int) ($settings['market_sentiment_enabled'] ?? 0) === 1,
            'cache_duration' => max(0, (int) ($settings['market_sentiment_cache_duration'] ?? 900)),
        ];
    }
}

if (!function_exists('cms_market_sentiment_log_error')) {
    function cms_market_sentiment_log_error(PDO $pdo, string $message): void
    {
        try {
            cms_market_sentiment_ensure_schema($pdo);
            $pdo->prepare('INSERT INTO api_error_log (source, message, created_at) VALUES (\'market_sentiment\', :message, NOW())')
                ->execute(['message' => mb_substr($message, 0, 2000)]);
        } catch (Throwable $e) {
            // Logging must never break the caller.
        }
    }
}

if (!function_exists('cms_market_sentiment_get_cache_row')) {
    function cms_market_sentiment_get_cache_row(PDO $pdo, string $metric): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM market_sentiment_cache WHERE metric = :metric ORDER BY id DESC LIMIT 1');
            $stmt->execute(['metric' => $metric]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('cms_market_sentiment_write_cache')) {
    function cms_market_sentiment_write_cache(PDO $pdo, string $metric, array $payload): void
    {
        try {
            $pdo->prepare('INSERT INTO market_sentiment_cache (metric, payload, fetched_at) VALUES (:metric, :payload, NOW())')
                ->execute(['metric' => $metric, 'payload' => json_encode($payload)]);
            // Keep only the most recent 20 cache rows per metric.
            $stmt = $pdo->prepare(
                'DELETE FROM market_sentiment_cache WHERE metric = :metric AND id NOT IN (
                    SELECT id FROM (SELECT id FROM market_sentiment_cache WHERE metric = :metric2 ORDER BY id DESC LIMIT 20) t
                )'
            );
            $stmt->execute(['metric' => $metric, 'metric2' => $metric]);
        } catch (Throwable $e) {
            // Cache write failure is non-fatal — we still have fresh data to return.
        }
    }
}

if (!function_exists('cms_fetch_btc_dominance')) {
    /**
     * CoinGecko /global — market_cap_percentage.btc (+ .eth, cheap to keep
     * since it's the same response).
     *
     * @return array{ok:bool,btc:?float,eth:?float,source:string,error:?string}
     */
    function cms_fetch_btc_dominance(PDO $pdo, bool $forceRefresh = false): array
    {
        $metric = 'btc_dominance';
        try {
            $sentimentSettings = cms_market_sentiment_settings($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'btc' => null, 'eth' => null, 'source' => 'error', 'error' => $e->getMessage()];
        }

        if (!$sentimentSettings['enabled']) {
            return ['ok' => false, 'btc' => null, 'eth' => null, 'source' => 'inactive', 'error' => null];
        }

        $cacheDuration = $sentimentSettings['cache_duration'];
        $cacheRow = cms_market_sentiment_get_cache_row($pdo, $metric);

        if (!$forceRefresh && $cacheRow !== null) {
            $age = time() - strtotime((string) $cacheRow['fetched_at']);
            if ($age >= 0 && $age < $cacheDuration) {
                $decoded = json_decode((string) $cacheRow['payload'], true);
                if (is_array($decoded)) {
                    return ['ok' => true, 'btc' => $decoded['btc'] ?? null, 'eth' => $decoded['eth'] ?? null, 'source' => 'cache', 'error' => null];
                }
            }
        }

        $cryptoSettings = cms_crypto_get_settings($pdo);
        $base = rtrim((string) ($cryptoSettings['base_url'] ?? 'https://api.coingecko.com/api/v3'), '/');
        $url = $base . '/global';
        $result = cms_crypto_http_get($url, $cryptoSettings);

        if ($result['ok']) {
            $decoded = json_decode($result['body'], true);
            $percentages = $decoded['data']['market_cap_percentage'] ?? null;
            if (is_array($percentages) && isset($percentages['btc'])) {
                $payload = ['btc' => (float) $percentages['btc'], 'eth' => isset($percentages['eth']) ? (float) $percentages['eth'] : null];
                cms_market_sentiment_write_cache($pdo, $metric, $payload);
                return ['ok' => true, 'btc' => $payload['btc'], 'eth' => $payload['eth'], 'source' => 'live', 'error' => null];
            }
            cms_market_sentiment_log_error($pdo, 'BTC Dominance response was missing data.market_cap_percentage.btc from ' . $url);
        } else {
            cms_market_sentiment_log_error($pdo, 'BTC Dominance request failed (' . ($result['error'] ?? 'unknown') . ') for ' . $url);
        }

        if ($cacheRow !== null) {
            $decoded = json_decode((string) $cacheRow['payload'], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'btc' => $decoded['btc'] ?? null, 'eth' => $decoded['eth'] ?? null, 'source' => 'cache-stale', 'error' => $result['error'] ?? null];
            }
        }

        return ['ok' => false, 'btc' => null, 'eth' => null, 'source' => 'empty', 'error' => $result['error'] ?? 'Unknown error'];
    }
}

if (!function_exists('cms_fetch_fear_greed')) {
    /**
     * Alternative.me /fng/?limit=1 — value (0-100) + classification.
     *
     * @return array{ok:bool,value:?int,classification:?string,source:string,error:?string}
     */
    function cms_fetch_fear_greed(PDO $pdo, bool $forceRefresh = false): array
    {
        $metric = 'fear_greed';
        try {
            $sentimentSettings = cms_market_sentiment_settings($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'value' => null, 'classification' => null, 'source' => 'error', 'error' => $e->getMessage()];
        }

        if (!$sentimentSettings['enabled']) {
            return ['ok' => false, 'value' => null, 'classification' => null, 'source' => 'inactive', 'error' => null];
        }

        $cacheDuration = $sentimentSettings['cache_duration'];
        $cacheRow = cms_market_sentiment_get_cache_row($pdo, $metric);

        if (!$forceRefresh && $cacheRow !== null) {
            $age = time() - strtotime((string) $cacheRow['fetched_at']);
            if ($age >= 0 && $age < $cacheDuration) {
                $decoded = json_decode((string) $cacheRow['payload'], true);
                if (is_array($decoded)) {
                    return ['ok' => true, 'value' => $decoded['value'] ?? null, 'classification' => $decoded['classification'] ?? null, 'source' => 'cache', 'error' => null];
                }
            }
        }

        $url = 'https://api.alternative.me/fng/?limit=1';
        $result = cms_crypto_http_get($url, []);

        if ($result['ok']) {
            $decoded = json_decode($result['body'], true);
            $entry = $decoded['data'][0] ?? null;
            if (is_array($entry) && isset($entry['value'])) {
                $payload = ['value' => (int) $entry['value'], 'classification' => (string) ($entry['value_classification'] ?? '')];
                cms_market_sentiment_write_cache($pdo, $metric, $payload);
                return ['ok' => true, 'value' => $payload['value'], 'classification' => $payload['classification'], 'source' => 'live', 'error' => null];
            }
            cms_market_sentiment_log_error($pdo, 'Fear and Greed response was missing data[0].value from ' . $url);
        } else {
            cms_market_sentiment_log_error($pdo, 'Fear and Greed request failed (' . ($result['error'] ?? 'unknown') . ') for ' . $url);
        }

        if ($cacheRow !== null) {
            $decoded = json_decode((string) $cacheRow['payload'], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'value' => $decoded['value'] ?? null, 'classification' => $decoded['classification'] ?? null, 'source' => 'cache-stale', 'error' => $result['error'] ?? null];
            }
        }

        return ['ok' => false, 'value' => null, 'classification' => null, 'source' => 'empty', 'error' => $result['error'] ?? 'Unknown error'];
    }
}
