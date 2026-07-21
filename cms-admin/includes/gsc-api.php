<?php
declare(strict_types=1);

/**
 * Google Search Console integration for Growth Agent.
 *
 * No cron in this codebase (see growth-agent-service.php's own note on
 * that) — cms_gsc_fetch_if_stale() is called lazily from
 * pages/growth-agent.php instead, same "self-maintaining on request"
 * spirit as cms_ensure_table(). fetch_lookback_days defaults to 14 (not
 * "just yesterday") specifically to backfill safely across however long
 * a gap between page visits turns out to be.
 *
 * No Google API Client Library / Composer — this hand-rolls the service
 * account JWT bearer flow (openssl_sign(), already a hard dependency via
 * cms_ai_encrypt()/cms_ai_decrypt() in ai-helpers.php) plus plain cURL
 * REST calls, mirroring the provider-agnostic pattern already used in
 * crypto-api.php (settings in a DB table, no SDK).
 *
 * Credential storage: the full service account JSON is encrypted at rest
 * via cms_ai_encrypt() (reused from ai-helpers.php, same AES-256-CBC
 * already protecting ai_credentials.api_key_enc) — never decrypted back
 * to the UI after saving, same rule as AI Credentials.
 */

require_once __DIR__ . '/schema-guard.php';

if (!function_exists('cms_gsc_ensure_schema')) {
    function cms_gsc_ensure_schema(PDO $pdo): void
    {
        $created = cms_ensure_table(
            $pdo,
            'gsc_settings',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             service_account_email VARCHAR(255) DEFAULT NULL,
             service_account_json_enc LONGTEXT DEFAULT NULL,
             site_url VARCHAR(255) DEFAULT NULL,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             fetch_lookback_days INT UNSIGNED NOT NULL DEFAULT 14,
             fetch_window_days INT UNSIGNED NOT NULL DEFAULT 90,
             opportunity_thresholds_json LONGTEXT DEFAULT NULL,
             memory_thresholds_json LONGTEXT DEFAULT NULL,
             last_memory_detection_at TIMESTAMP NULL DEFAULT NULL,
             last_fetch_status VARCHAR(20) DEFAULT NULL,
             last_fetch_message VARCHAR(255) DEFAULT NULL,
             last_fetch_rows INT UNSIGNED DEFAULT NULL,
             last_fetch_at TIMESTAMP NULL DEFAULT NULL,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        );
        if ($created) {
            $pdo->exec('INSERT INTO gsc_settings (is_active) VALUES (0)');
        }
        // Columns added after 015 shipped (016_gsc_opportunities.sql /
        // 017_growth_agent_memory.sql) — ensure they exist even on installs
        // that already ran 015 before those revisions.
        cms_ensure_column($pdo, 'gsc_settings', 'opportunity_thresholds_json', 'LONGTEXT DEFAULT NULL AFTER `fetch_window_days`');
        cms_ensure_column($pdo, 'gsc_settings', 'memory_thresholds_json', 'LONGTEXT DEFAULT NULL AFTER `opportunity_thresholds_json`');
        cms_ensure_column($pdo, 'gsc_settings', 'last_memory_detection_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `memory_thresholds_json`');

        cms_ensure_table(
            $pdo,
            'gsc_query_data',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             query VARCHAR(255) NOT NULL,
             page_url VARCHAR(500) NOT NULL,
             matched_page_id INT UNSIGNED DEFAULT NULL,
             clicks INT UNSIGNED NOT NULL DEFAULT 0,
             impressions INT UNSIGNED NOT NULL DEFAULT 0,
             ctr DECIMAL(7,4) DEFAULT NULL,
             position DECIMAL(6,2) DEFAULT NULL,
             data_date DATE NOT NULL,
             dedupe_hash CHAR(32) NOT NULL,
             fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gsc_dedupe_hash (dedupe_hash),
             KEY idx_gsc_page (matched_page_id),
             KEY idx_gsc_date (data_date),
             KEY idx_gsc_query (query(100))"
        );

        cms_ensure_table(
            $pdo,
            'gsc_opportunities',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             item_type ENUM('page','query') NOT NULL,
             matched_page_id INT UNSIGNED DEFAULT NULL,
             query_text VARCHAR(255) DEFAULT NULL,
             matched_categories VARCHAR(255) NOT NULL DEFAULT '',
             impact_score TINYINT UNSIGNED NOT NULL,
             effort_score TINYINT UNSIGNED NOT NULL,
             priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
             recommended_agent VARCHAR(50) NOT NULL,
             recommended_action ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea') NOT NULL,
             reason TEXT DEFAULT NULL,
             metrics_json TEXT DEFAULT NULL,
             status ENUM('open','actioned') NOT NULL DEFAULT 'open',
             linked_job_id INT UNSIGNED DEFAULT NULL,
             dedupe_key CHAR(32) NOT NULL,
             computed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gsc_opp_dedupe (dedupe_key),
             KEY idx_gsc_opp_status_priority (status, priority),
             KEY idx_gsc_opp_page (matched_page_id)"
        );

        // Pre-existing table (014_growth_agent.sql). Fresh installs (this
        // column not created yet at all) get the current 3-tier definition
        // directly. Installs upgrading from the 015-era 2-tier
        // normal/high enum are migrated separately by
        // cms_growth_agent_ensure_priority_enum() in growth-agent-service.php
        // (a straight cms_ensure_column() no-ops once the column already
        // exists, so it can't widen an existing enum by itself).
        cms_ensure_column($pdo, 'growth_agent_jobs', 'priority', "ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER `status`");

        // "Agent Memory" — winning_pattern/content_gap insights detected
        // from gsc_query_data aggregated across its full retention window.
        // See docs/GROWTH_AGENT_MEMORY_PLAN.md.
        cms_ensure_table(
            $pdo,
            'growth_agent_memory',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             insight_type ENUM('winning_pattern','content_gap') NOT NULL,
             title VARCHAR(255) NOT NULL,
             description TEXT DEFAULT NULL,
             supporting_data_json TEXT DEFAULT NULL,
             status ENUM('pending_review','active','archived') NOT NULL DEFAULT 'pending_review',
             archived_reason ENUM('rejected','stale_pending','stale_active') DEFAULT NULL,
             reviewed_by INT UNSIGNED DEFAULT NULL,
             reviewed_at TIMESTAMP NULL DEFAULT NULL,
             detected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             last_confirmed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             dedupe_key CHAR(32) NOT NULL,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gam_dedupe (dedupe_key),
             KEY idx_gam_status (status)"
        );
    }
}

if (!function_exists('cms_gsc_get_settings')) {
    function cms_gsc_get_settings(PDO $pdo): array
    {
        cms_gsc_ensure_schema($pdo);
        $row = $pdo->query('SELECT * FROM gsc_settings ORDER BY id ASC LIMIT 1')->fetch();
        return $row !== false ? $row : [];
    }
}

if (!function_exists('cms_gsc_log_error')) {
    function cms_gsc_log_error(PDO $pdo, string $message): void
    {
        try {
            cms_ensure_table(
                $pdo,
                'api_error_log',
                'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                 source VARCHAR(30) NOT NULL,
                 message TEXT NOT NULL,
                 created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                 KEY idx_api_error_source (source)'
            );
            $pdo->prepare('INSERT INTO api_error_log (source, message, created_at) VALUES (\'gsc\', :message, NOW())')
                ->execute(['message' => mb_substr($message, 0, 2000)]);
        } catch (Throwable $e) {
            // Logging must never break the caller.
        }
    }
}

// ── Service account JWT bearer flow ─────────────────────────────────────

if (!function_exists('cms_gsc_base64url_encode')) {
    function cms_gsc_base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('cms_gsc_generate_jwt')) {
    /**
     * Signs a JWT bearer assertion for the given service account, per
     * Google's OAuth2 service-account flow (RFC 7523). Read-only scope —
     * this integration never writes to Search Console.
     *
     * @param array{client_email:string, private_key:string, token_uri?:string} $serviceAccount
     * @throws RuntimeException if signing fails (bad/malformed private key).
     */
    function cms_gsc_generate_jwt(array $serviceAccount, string $scope = 'https://www.googleapis.com/auth/webmasters.readonly'): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $serviceAccount['client_email'],
            'scope' => $scope,
            'aud'   => $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $unsigned = cms_gsc_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . cms_gsc_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Invalid service account private key.');
        }

        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new RuntimeException('Failed to sign JWT — invalid private key.');
        }

        return $unsigned . '.' . cms_gsc_base64url_encode($signature);
    }
}

if (!function_exists('cms_gsc_http_request')) {
    /**
     * Minimal cURL wrapper shared by every GSC call in this file — form
     * POST (token exchange), JSON POST (searchAnalytics query), and GET
     * (sites.list) all funnel through here. Mirrors
     * cms_crypto_http_get()'s shape/error handling in crypto-api.php.
     *
     * @param 'GET'|'POST' $method
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    function cms_gsc_http_request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL extension is not available on this server.'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WPM-GrowthAgent-GSC/1.0',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if ($responseBody === false || $error !== null) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?? 'Unknown cURL error'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $responseBody, 'error' => 'HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string) $responseBody, 'error' => null];
    }
}

if (!function_exists('cms_gsc_exchange_jwt_for_token')) {
    /**
     * @param array{client_email:string,private_key:string,token_uri?:string} $serviceAccount
     * @return array{ok:bool,token:string,error:?string}
     */
    function cms_gsc_exchange_jwt_for_token(array $serviceAccount): array
    {
        try {
            $jwt = cms_gsc_generate_jwt($serviceAccount);
        } catch (Throwable $e) {
            return ['ok' => false, 'token' => '', 'error' => $e->getMessage()];
        }

        $tokenUri = $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $result = cms_gsc_http_request('POST', $tokenUri, $body, ['Content-Type: application/x-www-form-urlencoded']);
        if (!$result['ok']) {
            $decoded = json_decode($result['body'], true);
            $detail = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? null) : null;
            return ['ok' => false, 'token' => '', 'error' => $detail ?? ($result['error'] ?? 'Token exchange failed')];
        }

        $decoded = json_decode($result['body'], true);
        $accessToken = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($accessToken === '') {
            return ['ok' => false, 'token' => '', 'error' => 'Token response did not include an access_token.'];
        }

        return ['ok' => true, 'token' => $accessToken, 'error' => null];
    }
}

if (!function_exists('cms_gsc_decrypt_service_account')) {
    /**
     * @return array{ok:bool,data:array,error:?string}
     */
    function cms_gsc_decrypt_service_account(string $encrypted): array
    {
        require_once __DIR__ . '/ai-helpers.php';

        $json = cms_ai_decrypt($encrypted);
        if ($json === '') {
            return ['ok' => false, 'data' => [], 'error' => 'Could not decrypt stored service account credential.'];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['client_email'], $data['private_key'])) {
            return ['ok' => false, 'data' => [], 'error' => 'Stored service account JSON is malformed.'];
        }

        return ['ok' => true, 'data' => $data, 'error' => null];
    }
}

if (!function_exists('cms_gsc_get_access_token')) {
    /**
     * @return array{ok:bool,token:string,error:string}
     */
    function cms_gsc_get_access_token(PDO $pdo): array
    {
        $settings = cms_gsc_get_settings($pdo);
        $encrypted = (string) ($settings['service_account_json_enc'] ?? '');
        if ($encrypted === '') {
            return ['ok' => false, 'token' => '', 'error' => 'No service account connected yet — see GSC Settings.'];
        }

        $decrypted = cms_gsc_decrypt_service_account($encrypted);
        if (!$decrypted['ok']) {
            return ['ok' => false, 'token' => '', 'error' => (string) $decrypted['error']];
        }

        $exchanged = cms_gsc_exchange_jwt_for_token($decrypted['data']);
        return ['ok' => $exchanged['ok'], 'token' => $exchanged['token'], 'error' => (string) ($exchanged['error'] ?? '')];
    }
}

if (!function_exists('cms_gsc_test_service_account')) {
    /**
     * Used by the "Test Connection" step on pages/gsc-settings.php —
     * validates a freshly-pasted JSON (not yet saved) by attempting a
     * real token exchange, without touching gsc_settings.
     *
     * @return array{ok:bool,message:string,email:string}
     */
    function cms_gsc_test_service_account(array $serviceAccount): array
    {
        if (!isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return ['ok' => false, 'message' => 'JSON is missing client_email or private_key.', 'email' => ''];
        }

        $exchanged = cms_gsc_exchange_jwt_for_token($serviceAccount);
        if (!$exchanged['ok']) {
            return ['ok' => false, 'message' => (string) $exchanged['error'], 'email' => ''];
        }

        return ['ok' => true, 'message' => 'Service account authenticated successfully.', 'email' => (string) $serviceAccount['client_email']];
    }
}

if (!function_exists('cms_gsc_list_sites')) {
    /**
     * @return array{ok:bool,sites:list<string>,error:string}
     */
    function cms_gsc_list_sites(PDO $pdo): array
    {
        $tokenResult = cms_gsc_get_access_token($pdo);
        if (!$tokenResult['ok']) {
            return ['ok' => false, 'sites' => [], 'error' => $tokenResult['error']];
        }

        $result = cms_gsc_http_request(
            'GET',
            'https://www.googleapis.com/webmasters/v3/sites',
            null,
            ['Authorization: Bearer ' . $tokenResult['token']]
        );
        if (!$result['ok']) {
            return ['ok' => false, 'sites' => [], 'error' => $result['error'] ?? 'Failed to list properties.'];
        }

        $decoded = json_decode($result['body'], true);
        $entries = is_array($decoded['siteEntry'] ?? null) ? $decoded['siteEntry'] : [];
        $sites = array_values(array_filter(array_map(
            static fn (array $entry): string => (string) ($entry['siteUrl'] ?? ''),
            $entries
        )));

        return ['ok' => true, 'sites' => $sites, 'error' => ''];
    }
}

if (!function_exists('cms_gsc_site_url_label')) {
    /**
     * Google's Search Console has two distinct property types that can
     * BOTH exist for the same site and BOTH show up in sites.list() —
     * picking the wrong one is a common cause of "connected fine, but
     * always 0 rows" (the other property variant has the actual tracked
     * history, this one doesn't). Surfaced in the GSC Settings picker so
     * the operator can tell them apart instead of guessing.
     */
    function cms_gsc_site_url_label(string $siteUrl): string
    {
        return str_starts_with($siteUrl, 'sc-domain:')
            ? 'Domain property — mencakup semua varian (http/https, www/non-www, subdomain)'
            : 'URL-prefix property — cuma exact match URL ini persis';
    }
}

// ── Fetch pipeline ───────────────────────────────────────────────────────

if (!function_exists('cms_gsc_page_url_index')) {
    /**
     * Builds [normalizedUrl => page_id] for every published article, so
     * matching GSC's page_url against our own content is one in-memory
     * lookup per row instead of a query per row. Prefers canonical_url
     * when the admin set one (that's what search engines actually index
     * for that page — same rule sitemap-service.php already applies when
     * excluding non-canonical URLs from the sitemap); falls back to the
     * default clean-URL pattern otherwise.
     *
     * Reuses cms_sitemap_absolute_url()/cms_sitemap_path_for() from
     * sitemap-service.php rather than re-deriving the split-subdomain
     * absolute-URL logic a third time.
     *
     * @return array<string, int>
     */
    function cms_gsc_page_url_index(PDO $pdo): array
    {
        require_once __DIR__ . '/sitemap-service.php';

        $index = [];
        $stmt = $pdo->query("SELECT page_id, slug, canonical_url FROM pages WHERE status = 'published'");
        foreach ($stmt->fetchAll() as $row) {
            $canonical = trim((string) ($row['canonical_url'] ?? ''));
            $url = $canonical !== ''
                ? $canonical
                : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $row['slug']));
            $index[cms_gsc_normalize_url($url)] = (int) $row['page_id'];
        }

        return $index;
    }
}

if (!function_exists('cms_gsc_normalize_url')) {
    function cms_gsc_normalize_url(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://#', '', $url) ?? $url;
        return rtrim($url, '/');
    }
}

if (!function_exists('cms_gsc_fetch_and_cache')) {
    /**
     * Main entry point — pulls searchAnalytics data for the connected
     * property, upserts it into gsc_query_data (resolving matched_page_id
     * along the way), prunes rows older than fetch_window_days, and
     * updates gsc_settings.last_fetch_*. Never throws — a broken/
     * unconfigured connection must never break the Growth Agent page.
     *
     * @return array{ok:bool,rows_written:int,error:string}
     */
    function cms_gsc_fetch_and_cache(PDO $pdo, bool $forceRefresh = false): array
    {
        try {
            cms_gsc_ensure_schema($pdo);
            $settings = cms_gsc_get_settings($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'rows_written' => 0, 'error' => $e->getMessage()];
        }

        if ((int) ($settings['is_active'] ?? 0) !== 1 || empty($settings['site_url'])) {
            $error = 'GSC is not connected — see GSC Settings.';
            if ($forceRefresh) {
                cms_gsc_log_error($pdo, $error);
            }
            return ['ok' => false, 'rows_written' => 0, 'error' => $error];
        }

        $tokenResult = cms_gsc_get_access_token($pdo);
        if (!$tokenResult['ok']) {
            cms_gsc_log_error($pdo, 'Token exchange failed: ' . $tokenResult['error']);
            cms_gsc_record_fetch_result($pdo, false, $tokenResult['error'], 0);
            return ['ok' => false, 'rows_written' => 0, 'error' => $tokenResult['error']];
        }

        $lookbackDays = max(1, (int) ($settings['fetch_lookback_days'] ?? 14));
        $endDate = date('Y-m-d', strtotime('-2 days')); // GSC data lags ~2-3 days
        $startDate = date('Y-m-d', strtotime('-' . ($lookbackDays + 2) . ' days'));

        $queryUrl = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . rawurlencode((string) $settings['site_url']) . '/searchAnalytics/query';

        $requestBody = json_encode([
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'dimensions' => ['query', 'page', 'date'],
            'rowLimit'   => 5000, // known limitation: no pagination yet, fine for this site's current volume
        ]);

        $result = cms_gsc_http_request('POST', $queryUrl, $requestBody, [
            'Authorization: Bearer ' . $tokenResult['token'],
            'Content-Type: application/json',
        ]);

        if (!$result['ok']) {
            $decoded = json_decode($result['body'], true);
            $detail = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            $message = $detail ?? ($result['error'] ?? 'Search Console query failed');
            cms_gsc_log_error($pdo, $message);
            cms_gsc_record_fetch_result($pdo, false, $message, 0);
            return ['ok' => false, 'rows_written' => 0, 'error' => $message];
        }

        $decoded = json_decode($result['body'], true);
        $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];

        if ($rows === []) {
            // Request succeeded (HTTP 2xx) but Google returned no rows at
            // all — NOT necessarily a bug. Common causes: (1) a brand-new
            // property GSC hasn't finished accumulating queryable data
            // for yet (can take a few days after verification, even with
            // real traffic); (2) the wrong property variant was picked in
            // GSC Settings — e.g. a URL-prefix property
            // (https://sagacrypto.com/) selected while all of the site's
            // actual tracked history lives under the Domain property
            // (sc-domain:sagacrypto.com), or vice versa; a Domain and a
            // URL-prefix property for the same site are BOTH valid,
            // separate entries in sites.list() and only one of them may
            // actually have data. Logged as a diagnostic (not
            // cms_gsc_log_error's usual failure path) with the exact
            // request/response so this is debuggable without server log
            // access — see also cms_gsc_site_url_label() in GSC Settings,
            // which now flags property type in the picker for this exact
            // reason.
            cms_gsc_log_error($pdo, sprintf(
                "Fetch OK (HTTP %d) but 0 rows returned.\nsite_url: %s\nRequest URL: %s\nRequest body: %s\nRaw response: %s",
                $result['status'],
                (string) $settings['site_url'],
                $queryUrl,
                $requestBody,
                mb_substr($result['body'], 0, 1500)
            ));
        }

        $pageIndex = cms_gsc_page_url_index($pdo);

        $upsert = $pdo->prepare(
            'INSERT INTO gsc_query_data
                (query, page_url, matched_page_id, clicks, impressions, ctr, `position`, data_date, dedupe_hash, fetched_at)
             VALUES
                (:query, :page_url, :matched_page_id, :clicks, :impressions, :ctr, :position, :data_date, :dedupe_hash, NOW())
             ON DUPLICATE KEY UPDATE
                matched_page_id = VALUES(matched_page_id),
                clicks = VALUES(clicks),
                impressions = VALUES(impressions),
                ctr = VALUES(ctr),
                `position` = VALUES(`position`),
                fetched_at = NOW()'
        );

        $written = 0;
        foreach ($rows as $row) {
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $query = (string) ($keys[0] ?? '');
            $pageUrl = (string) ($keys[1] ?? '');
            $dataDate = (string) ($keys[2] ?? '');
            if ($query === '' || $pageUrl === '' || $dataDate === '') {
                continue;
            }

            $dedupeHash = md5($query . '|' . $pageUrl . '|' . $dataDate);
            $matchedPageId = $pageIndex[cms_gsc_normalize_url($pageUrl)] ?? null;

            $upsert->execute([
                'query'           => mb_substr($query, 0, 255),
                'page_url'        => mb_substr($pageUrl, 0, 500),
                'matched_page_id' => $matchedPageId,
                'clicks'          => (int) ($row['clicks'] ?? 0),
                'impressions'     => (int) ($row['impressions'] ?? 0),
                'ctr'             => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'        => isset($row['position']) ? (float) $row['position'] : null,
                'data_date'       => $dataDate,
                'dedupe_hash'     => $dedupeHash,
            ]);
            $written++;
        }

        $windowDays = max(1, (int) ($settings['fetch_window_days'] ?? 90));
        $pdo->prepare('DELETE FROM gsc_query_data WHERE data_date < (CURDATE() - INTERVAL :days DAY)')
            ->execute(['days' => $windowDays]);

        // Surface the 0-rows case directly in the GSC panel (last_fetch_message)
        // too, not just buried in Recent Errors — see the diagnostic log
        // written above for the full request/response detail.
        $fetchMessage = $written > 0
            ? ''
            : "0 rows untuk {$startDate}..{$endDate} (site: {$settings['site_url']}) — cek panel \"Recent Diagnostics\" di GSC Settings untuk detail request/response mentah.";
        cms_gsc_record_fetch_result($pdo, true, $fetchMessage, $written);

        // Recompute Prioritized Opportunities right after every successful
        // fetch (decision: automatic, per docs/GSC_OPPORTUNITIES_REVISION.md
        // § "Pertanyaan" #1) — pure SQL/scoring, no AI call, so this is
        // cheap even though it runs on every fetch. Never allowed to make
        // the fetch itself look like it failed.
        try {
            cms_gsc_compute_opportunities($pdo);
        } catch (Throwable $e) {
            cms_gsc_log_error($pdo, 'Opportunity recompute failed after fetch: ' . $e->getMessage());
        }

        return ['ok' => true, 'rows_written' => $written, 'error' => ''];
    }
}

if (!function_exists('cms_gsc_record_fetch_result')) {
    function cms_gsc_record_fetch_result(PDO $pdo, bool $ok, string $message, int $rows): void
    {
        try {
            $pdo->prepare(
                'UPDATE gsc_settings
                    SET last_fetch_status = :status, last_fetch_message = :message,
                        last_fetch_rows = :rows, last_fetch_at = NOW()
                  ORDER BY id ASC LIMIT 1'
            )->execute([
                'status'  => $ok ? 'success' : 'failed',
                'message' => mb_substr($message, 0, 255),
                'rows'    => $rows,
            ]);
        } catch (Throwable $e) {
            // Non-fatal — the fetch itself already succeeded or failed independently of this bookkeeping.
        }
    }
}

if (!function_exists('cms_gsc_fetch_if_stale')) {
    /**
     * Lazy trigger — no cron in this codebase (see file docblock). Called
     * from pages/growth-agent.php on every page load; only actually
     * fetches when GSC is connected AND the last fetch is older than
     * $maxAgeHours (or has never run). Never throws.
     */
    function cms_gsc_fetch_if_stale(PDO $pdo, int $maxAgeHours = 24): void
    {
        try {
            $settings = cms_gsc_get_settings($pdo);
            if ((int) ($settings['is_active'] ?? 0) !== 1 || empty($settings['site_url'])) {
                return;
            }

            $lastFetchAt = $settings['last_fetch_at'] ?? null;
            $isStale = $lastFetchAt === null
                || (time() - strtotime((string) $lastFetchAt)) >= ($maxAgeHours * 3600);

            if ($isStale) {
                cms_gsc_fetch_and_cache($pdo, false);
            }
        } catch (Throwable $e) {
            // A lazy background refresh must never break the page it's attached to.
        }
    }
}

// ── Prioritized Opportunities (revisi 18 Jul 2026) ──────────────────────
//
// Opportunities are scored purely from gsc_query_data — no AI call at
// compute time (see docs/GSC_OPPORTUNITIES_REVISION.md). AI is only
// invoked later, on demand, when the operator clicks "Generate" on one
// specific row (dispatched from pages/growth-agent.php into the
// cms_growth_agent_generate_*() functions in growth-agent-service.php).
//
// Every scoring threshold lives in ONE place — gsc_settings.
// opportunity_thresholds_json — instead of scattered PHP constants, so
// it can be tuned later without touching code (explicit requirement).

if (!function_exists('cms_gsc_default_opportunity_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_default_opportunity_thresholds(): array
    {
        return [
            // Sorted descending by "min" — first bucket whose min the
            // value clears wins. Applies to total impressions (page or
            // query, whichever is being scored).
            'volume_buckets' => [
                ['min' => 5000, 'score' => 10],
                ['min' => 2000, 'score' => 8],
                ['min' => 1000, 'score' => 6],
                ['min' => 500, 'score' => 4],
                ['min' => 200, 'score' => 2],
                ['min' => 0, 'score' => 1],
            ],
            // "Low CTR" signal — gap = (this position bucket's average
            // CTR site-wide) - (this item's CTR). Sorted descending by min.
            'ctr_gap_buckets' => [
                ['min' => 0.05, 'score' => 10],
                ['min' => 0.03, 'score' => 7],
                ['min' => 0.015, 'score' => 5],
                ['min' => 0.005, 'score' => 3],
                ['min' => 0.0, 'score' => 1],
            ],
            // "Page-one" signal — sorted ascending by max_position (first
            // bucket the value fits under wins).
            'page_one_buckets' => [
                ['max_position' => 13, 'score' => 10],
                ['max_position' => 16, 'score' => 7],
                ['max_position' => 20, 'score' => 4],
            ],
            'zero_click_score' => 9,
            'zero_click_ctr_threshold' => 0.01, // clicks/impressions <= this counts as "zero click"
            'page_one_min_position' => 11,
            'page_one_max_position' => 20,
            // Effort is a fixed lookup per category (type of work), not a
            // formula over GSC numbers — see docs/GSC_OPPORTUNITIES_REVISION.md § 2.2.
            'effort' => [
                'low_ctr' => 2,
                'zero_click_page' => 4,
                'page_one' => 6,
                'no_article' => 9,
            ],
            'priority' => [
                'high_impact_min' => 7,
                'high_effort_max' => 5,
                'high_impact_override' => 9,
                'low_impact_max' => 3,
                'low_impact_mid' => 5,
                'low_effort_min' => 8,
            ],
            // Floor — below this, an item isn't surfaced as an
            // opportunity at all (too little traffic to matter yet).
            'min_impressions_page' => 100,
            'min_impressions_query' => 200,
        ];
    }
}

if (!function_exists('cms_gsc_get_opportunity_thresholds')) {
    /**
     * Reads gsc_settings.opportunity_thresholds_json, filling in any
     * missing key from the defaults (so adding a new tunable threshold
     * later never breaks an already-saved, older JSON blob). Never
     * throws — falls back to pure defaults on any parse failure.
     *
     * @return array<string, mixed>
     */
    function cms_gsc_get_opportunity_thresholds(PDO $pdo): array
    {
        $defaults = cms_gsc_default_opportunity_thresholds();

        try {
            $settings = cms_gsc_get_settings($pdo);
            $raw = (string) ($settings['opportunity_thresholds_json'] ?? '');
            if ($raw === '') {
                return $defaults;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $defaults;
            }
            return array_replace_recursive($defaults, $decoded);
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

// ── Agent Memory thresholds (docs/GROWTH_AGENT_MEMORY_PLAN.md) ──────────
//
// Kept in a dedicated gsc_settings.memory_thresholds_json blob, separate
// from opportunity_thresholds_json — same "one tunable place" spirit as
// the opportunity thresholds above, but one place PER FEATURE rather
// than mixing two unrelated concepts into a single blob.

if (!function_exists('cms_gsc_default_memory_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_default_memory_thresholds(): array
    {
        return [
            // A query must show up in at least this many distinct
            // ISO weeks (across gsc_query_data's full retention window,
            // not one fetch) before it counts as a "consistent" pattern
            // rather than a one-off spike.
            'min_distinct_weeks' => 3,
            // Floor — below this total impressions (summed over the
            // whole window), a query is never surfaced as an insight at
            // all, regardless of how many weeks it appears in.
            'min_impressions' => 300,
            // "winning_pattern" if EITHER of these holds (average over
            // the whole window).
            'winning_ctr_threshold' => 0.03,
            'winning_position_threshold' => 10.0,
            // Retention (§ 5 in the plan doc).
            'pending_review_stale_days' => 30,
            'active_stale_days' => 90,
            // Lazy "if-stale" trigger interval — deliberately much
            // longer than the 24h GSC fetch interval, since "is this
            // pattern still consistent" changes slowly and re-running
            // the full-window GROUP BY every fetch would be wasted work
            // that produces nearly-identical drafts (review fatigue).
            'detection_interval_days' => 7,
        ];
    }
}

if (!function_exists('cms_gsc_get_memory_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_get_memory_thresholds(PDO $pdo): array
    {
        $defaults = cms_gsc_default_memory_thresholds();

        try {
            $settings = cms_gsc_get_settings($pdo);
            $raw = (string) ($settings['memory_thresholds_json'] ?? '');
            if ($raw === '') {
                return $defaults;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $defaults;
            }
            return array_replace_recursive($defaults, $decoded);
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('cms_gsc_bucket_score_desc')) {
    /**
     * @param list<array{min: float|int, score: int}> $buckets Sorted descending by 'min'.
     */
    function cms_gsc_bucket_score_desc(float $value, array $buckets): int
    {
        foreach ($buckets as $bucket) {
            if ($value >= (float) $bucket['min']) {
                return (int) $bucket['score'];
            }
        }
        return 1;
    }
}

if (!function_exists('cms_gsc_bucket_score_asc_max')) {
    /**
     * @param list<array{max_position: float|int, score: int}> $buckets Sorted ascending by 'max_position'.
     */
    function cms_gsc_bucket_score_asc_max(float $value, array $buckets): int
    {
        foreach ($buckets as $bucket) {
            if ($value <= (float) $bucket['max_position']) {
                return (int) $bucket['score'];
            }
        }
        return 1;
    }
}

if (!function_exists('cms_gsc_position_bucket_label')) {
    function cms_gsc_position_bucket_label(float $position): string
    {
        if ($position <= 3) {
            return '1-3';
        }
        if ($position <= 10) {
            return '4-10';
        }
        if ($position <= 20) {
            return '11-20';
        }
        return '21+';
    }
}

if (!function_exists('cms_gsc_site_ctr_by_position_bucket')) {
    /**
     * Average CTR per position bucket, computed fresh from the current
     * gsc_query_data window — used as the fair baseline for "Low CTR"
     * (comparing a position-15 query's CTR against a position-2 query's
     * CTR would always look artificially bad, so this compares within
     * the same bucket instead).
     *
     * @return array<string, float> bucket label => average CTR (0.0-1.0)
     */
    function cms_gsc_site_ctr_by_position_bucket(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT
                CASE
                    WHEN position <= 3 THEN '1-3'
                    WHEN position <= 10 THEN '4-10'
                    WHEN position <= 20 THEN '11-20'
                    ELSE '21+'
                END AS bucket,
                SUM(clicks) AS total_clicks,
                SUM(impressions) AS total_impressions
             FROM gsc_query_data
             WHERE position IS NOT NULL
             GROUP BY bucket"
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $impressions = (int) $row['total_impressions'];
            $result[(string) $row['bucket']] = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        }
        return $result;
    }
}

if (!function_exists('cms_gsc_derive_priority')) {
    function cms_gsc_derive_priority(int $impact, int $effort, array $thresholds): string
    {
        $p = $thresholds['priority'];
        if ($impact >= (int) $p['high_impact_override']) {
            return 'high';
        }
        if ($impact >= (int) $p['high_impact_min'] && $effort <= (int) $p['high_effort_max']) {
            return 'high';
        }
        if ($impact <= (int) $p['low_impact_max']) {
            return 'low';
        }
        if ($impact <= (int) $p['low_impact_mid'] && $effort >= (int) $p['low_effort_min']) {
            return 'low';
        }
        return 'medium';
    }
}

if (!function_exists('cms_gsc_build_opportunity_reason')) {
    /**
     * Parametrized narrative — NOT AI-generated (deliberately, so listing
     * opportunities costs zero tokens). "Primary category" picks which
     * template to use when an item matched more than one category — same
     * precedence as cms_gsc_primary_action() (cheapest fix first).
     *
     * @param array<string, mixed> $metrics
     */
    function cms_gsc_build_opportunity_reason(string $primaryCategory, array $metrics): string
    {
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);
        $ctrPct = round(((float) ($metrics['ctr'] ?? 0)) * 100, 2);
        $position = round((float) ($metrics['position'] ?? 0), 1);
        $bucketAvgCtrPct = round(((float) ($metrics['bucket_avg_ctr'] ?? 0)) * 100, 2);
        $positionBucketLabel = (string) ($metrics['position_bucket_label'] ?? '');
        $lookbackDays = (int) ($metrics['lookback_days'] ?? 14);
        $query = (string) ($metrics['query'] ?? '');
        $topQuery = (string) ($metrics['top_query'] ?? $query);

        return match ($primaryCategory) {
            'Low CTR' => "Artikel ini dapat {$impressions} impressions dalam {$lookbackDays} hari terakhir tapi CTR cuma {$ctrPct}% — di bawah rata-rata {$bucketAvgCtrPct}% untuk artikel di posisi {$positionBucketLabel}. Saran: tulis ulang title yang lebih spesifik & meta description dengan angka/CTA yang jelas.",
            'Zero-click' => "Query \"{$query}\" muncul {$impressions} kali dalam {$lookbackDays} hari terakhir tapi cuma dapat {$clicks} klik, di posisi rata-rata {$position}. Saran: cek relevansi title terhadap query ini, pertimbangkan tambah section yang eksplisit menjawabnya.",
            'Page-one' => "Berada di posisi rata-rata {$position} untuk query \"{$topQuery}\" ({$impressions} impressions dalam {$lookbackDays} hari) — dekat masuk halaman satu. Saran: perdalam bagian yang relevan dengan query ini, tambah subheading yang eksplisit menyebut kata kuncinya.",
            'No article' => "Query \"{$query}\" mendapat {$impressions} impressions dalam {$lookbackDays} hari terakhir tapi situs belum punya artikel yang membahasnya sama sekali. Saran: buat artikel baru menargetkan kata kunci ini.",
            default => "Impressions: {$impressions}, CTR: {$ctrPct}%, posisi rata-rata: {$position}.",
        };
    }
}

if (!function_exists('cms_gsc_primary_action')) {
    /**
     * When an item matches more than one category, this decides which
     * one drives recommended_action/recommended_agent/reason — cheapest
     * fix wins (same ordering as the effort lookup: meta fix < content
     * expansion < brand new article).
     *
     * @param list<string> $categories
     * @return array{category: string, action: string, agent: string}
     */
    function cms_gsc_primary_action(array $categories): array
    {
        if (in_array('Low CTR', $categories, true)) {
            return ['category' => 'Low CTR', 'action' => 'seo_recommendation', 'agent' => 'seo_agent'];
        }
        if (in_array('Page-one', $categories, true)) {
            return ['category' => 'Page-one', 'action' => 'gsc_content_optimization', 'agent' => 'growth_agent'];
        }
        if (in_array('No article', $categories, true)) {
            return ['category' => 'No article', 'action' => 'gsc_article_idea', 'agent' => 'growth_agent'];
        }
        // Zero-click alone (no Low CTR/Page-one/No article match) still
        // needs *some* action — treat as a meta-level fix, same as Low CTR.
        return ['category' => 'Zero-click', 'action' => 'seo_recommendation', 'agent' => 'seo_agent'];
    }
}

if (!function_exists('cms_gsc_compute_opportunities')) {
    /**
     * Rebuilds the Prioritized Opportunities list. Pure SQL + scoring, no
     * AI call — safe/cheap to run on every fetch (see
     * cms_gsc_fetch_and_cache()) plus a manual "Recompute Opportunities"
     * button. Never throws. Rows already status='actioned' are left
     * untouched (frozen history of what was actually generated) —
     * upserts only ever touch 'open' rows via dedupe_key.
     *
     * @return array{ok: bool, count: int, error: string}
     */
    function cms_gsc_compute_opportunities(PDO $pdo): array
    {
        try {
            cms_gsc_ensure_schema($pdo);
            $thresholds = cms_gsc_get_opportunity_thresholds($pdo);
            $settings = cms_gsc_get_settings($pdo);
            $lookbackDays = (int) ($settings['fetch_lookback_days'] ?? 14);
            $ctrByBucket = cms_gsc_site_ctr_by_position_bucket($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'count' => 0, 'error' => $e->getMessage()];
        }

        $zeroClickThreshold = (float) $thresholds['zero_click_ctr_threshold'];
        $pageOneMin = (float) $thresholds['page_one_min_position'];
        $pageOneMax = (float) $thresholds['page_one_max_position'];

        $upsert = $pdo->prepare(
            'INSERT INTO gsc_opportunities
                (item_type, matched_page_id, query_text, matched_categories, impact_score, effort_score,
                 priority, recommended_agent, recommended_action, reason, metrics_json, dedupe_key, computed_at)
             VALUES
                (:item_type, :matched_page_id, :query_text, :matched_categories, :impact_score, :effort_score,
                 :priority, :recommended_agent, :recommended_action, :reason, :metrics_json, :dedupe_key, NOW())
             ON DUPLICATE KEY UPDATE
                matched_categories = VALUES(matched_categories),
                impact_score = VALUES(impact_score),
                effort_score = VALUES(effort_score),
                priority = VALUES(priority),
                recommended_agent = VALUES(recommended_agent),
                recommended_action = VALUES(recommended_action),
                reason = VALUES(reason),
                metrics_json = VALUES(metrics_json),
                computed_at = NOW()'
            // Deliberately no WHERE/status guard here — MySQL simply won't
            // hit this branch for a dedupe_key whose row is 'actioned',
            // because that row already exists and its key doesn't change;
            // to be explicit about "never touch actioned rows", the
            // candidate SELECT queries below already exclude pages/queries
            // that have a linked actioned opportunity in the first place.
        );

        $count = 0;

        // ── Page items ───────────────────────────────────────────────
        try {
            $pageStmt = $pdo->prepare(
                "SELECT p.page_id, SUM(g.impressions) AS total_impressions, SUM(g.clicks) AS total_clicks,
                        AVG(g.position) AS avg_position,
                        GROUP_CONCAT(DISTINCT g.query ORDER BY g.impressions DESC SEPARATOR ', ') AS top_queries,
                        SUBSTRING_INDEX(GROUP_CONCAT(g.query ORDER BY g.impressions DESC SEPARATOR '|'), '|', 1) AS top_query
                   FROM gsc_query_data g
                   INNER JOIN pages p ON p.page_id = g.matched_page_id
                  WHERE g.matched_page_id IS NOT NULL AND p.status = 'published'
                    AND p.page_id NOT IN (
                        SELECT matched_page_id FROM gsc_opportunities
                         WHERE matched_page_id IS NOT NULL AND status = 'actioned'
                    )
                  GROUP BY p.page_id
                 HAVING total_impressions >= :min_impressions"
            );
            $pageStmt->execute(['min_impressions' => (int) $thresholds['min_impressions_page']]);
            $pageRows = $pageStmt->fetchAll();
        } catch (Throwable $e) {
            $pageRows = [];
        }

        foreach ($pageRows as $row) {
            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;
            $position = (float) $row['avg_position'];
            $bucketLabel = cms_gsc_position_bucket_label($position);
            $bucketAvgCtr = $ctrByBucket[$bucketLabel] ?? $ctr;

            $categories = [];
            $signalScores = [];

            $gap = $bucketAvgCtr - $ctr;
            if ($gap >= (float) end($thresholds['ctr_gap_buckets'])['min']) {
                $categories[] = 'Low CTR';
                $signalScores[] = cms_gsc_bucket_score_desc($gap, $thresholds['ctr_gap_buckets']);
            }
            if ($impressions > 0 && $ctr <= $zeroClickThreshold) {
                $categories[] = 'Zero-click';
                $signalScores[] = (int) $thresholds['zero_click_score'];
            }
            if ($position >= $pageOneMin && $position <= $pageOneMax) {
                $categories[] = 'Page-one';
                $signalScores[] = cms_gsc_bucket_score_asc_max($position, $thresholds['page_one_buckets']);
            }

            if ($categories === []) {
                continue; // not an opportunity — CTR/position both fine
            }

            $volumeScore = cms_gsc_bucket_score_desc((float) $impressions, $thresholds['volume_buckets']);
            $signalScore = max($signalScores);
            $impact = (int) round(($volumeScore + $signalScore) / 2);
            $impact = max(1, min(10, $impact));

            $effortMap = $thresholds['effort'];
            $effort = 1;
            if (in_array('Low CTR', $categories, true)) {
                $effort = max($effort, (int) $effortMap['low_ctr']);
            }
            if (in_array('Zero-click', $categories, true)) {
                $effort = max($effort, (int) $effortMap['zero_click_page']);
            }
            if (in_array('Page-one', $categories, true)) {
                $effort = max($effort, (int) $effortMap['page_one']);
            }

            $priority = cms_gsc_derive_priority($impact, $effort, $thresholds);
            $primary = cms_gsc_primary_action($categories);

            $metrics = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'position' => $position,
                'bucket_avg_ctr' => $bucketAvgCtr,
                'position_bucket_label' => $bucketLabel,
                'lookback_days' => $lookbackDays,
                'top_query' => (string) ($row['top_query'] ?? ''),
                'top_queries' => (string) ($row['top_queries'] ?? ''),
            ];

            $pageId = (int) $row['page_id'];
            $upsert->execute([
                'item_type' => 'page',
                'matched_page_id' => $pageId,
                'query_text' => null,
                'matched_categories' => implode(', ', $categories),
                'impact_score' => $impact,
                'effort_score' => $effort,
                'priority' => $priority,
                'recommended_agent' => $primary['agent'],
                'recommended_action' => $primary['action'],
                'reason' => cms_gsc_build_opportunity_reason($primary['category'], $metrics),
                'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'dedupe_key' => md5('page|' . $pageId),
            ]);
            $count++;
        }

        // ── Query items (no matching article at all) ────────────────
        try {
            $queryStmt = $pdo->prepare(
                "SELECT query, SUM(impressions) AS total_impressions, SUM(clicks) AS total_clicks,
                        AVG(position) AS avg_position
                   FROM gsc_query_data
                  GROUP BY query
                 HAVING SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0
                    AND total_impressions >= :min_impressions"
            );
            $queryStmt->execute(['min_impressions' => (int) $thresholds['min_impressions_query']]);
            $queryRows = $queryStmt->fetchAll();
        } catch (Throwable $e) {
            $queryRows = [];
        }

        // Queries already actioned (as a 'query' opportunity) are excluded
        // in PHP, same reasoning as v1's article-idea exclusion — no
        // page_id to key a SQL NOT IN off of.
        $actionedQueries = [];
        try {
            $stmt = $pdo->query("SELECT query_text FROM gsc_opportunities WHERE item_type = 'query' AND status = 'actioned'");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $q) {
                $actionedQueries[(string) $q] = true;
            }
        } catch (Throwable $e) {
            // Non-fatal — worst case a query gets re-listed after already being actioned once.
        }

        foreach ($queryRows as $row) {
            $queryText = (string) $row['query'];
            if (isset($actionedQueries[$queryText])) {
                continue;
            }

            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;
            $position = (float) $row['avg_position'];

            $categories = ['No article'];
            if ($ctr <= $zeroClickThreshold) {
                $categories[] = 'Zero-click';
            }
            if ($position >= $pageOneMin && $position <= $pageOneMax) {
                $categories[] = 'Page-one';
            }

            // "No article" impact is the volume signal alone (no CTR/position
            // gap concept applies — nothing of ours is ranking specifically
            // for this query), per docs/GSC_OPPORTUNITIES_REVISION.md § 2.1.
            $impact = cms_gsc_bucket_score_desc((float) $impressions, $thresholds['volume_buckets']);
            $effort = (int) $thresholds['effort']['no_article'];
            $priority = cms_gsc_derive_priority($impact, $effort, $thresholds);

            $metrics = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'position' => $position,
                'lookback_days' => $lookbackDays,
                'query' => $queryText,
            ];

            $upsert->execute([
                'item_type' => 'query',
                'matched_page_id' => null,
                'query_text' => mb_substr($queryText, 0, 255),
                'matched_categories' => implode(', ', $categories),
                'impact_score' => $impact,
                'effort_score' => $effort,
                'priority' => $priority,
                'recommended_agent' => 'growth_agent',
                'recommended_action' => 'gsc_article_idea',
                'reason' => cms_gsc_build_opportunity_reason('No article', $metrics),
                'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'dedupe_key' => md5('query|' . $queryText),
            ]);
            $count++;
        }

        return ['ok' => true, 'count' => $count, 'error' => ''];
    }
}
