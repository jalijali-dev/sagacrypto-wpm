<?php
declare(strict_types=1);

/**
 * Cloudflare Turnstile helpers — anti-spam for the public "Kontak" form
 * (index.php). See SITEMAP.md Update Log (23 Sep 2026) for context.
 *
 * Turnstile is free, invisible in the "managed" widget mode (most real
 * visitors see nothing at all, no puzzle), and needs 2 keys from
 * dash.cloudflare.com -> Turnstile: a public "site key" (embedded in the
 * page) and a secret key (used server-side only, never exposed).
 *
 * Both keys live in `site_settings` (see migration 020). Empty/unset
 * keys = feature OFF: the widget is not rendered and contact-submit.php
 * falls back to the pre-existing honeypot-only check, so this never
 * blocks legitimate submissions just because Cloudflare hasn't been set
 * up yet.
 */

require_once __DIR__ . '/schema-guard.php';

if (!function_exists('cms_turnstile_ensure_schema')) {
    function cms_turnstile_ensure_schema(PDO $pdo): void
    {
        // `site_settings` itself is created by 000_base_schema.sql -- only
        // bolt on the 2 Turnstile columns here (same idempotent pattern as
        // cms_market_sentiment_ensure_schema() in market-sentiment.php).
        cms_ensure_column($pdo, 'site_settings', 'turnstile_site_key', 'VARCHAR(255) DEFAULT NULL AFTER `google_analytics_id`');
        cms_ensure_column($pdo, 'site_settings', 'turnstile_secret_key', 'VARCHAR(255) DEFAULT NULL AFTER `turnstile_site_key`');
    }
}

if (!function_exists('cms_turnstile_settings')) {
    /**
     * @return array{enabled:bool,site_key:string,secret_key:string}
     */
    function cms_turnstile_settings(PDO $pdo): array
    {
        try {
            cms_turnstile_ensure_schema($pdo);
            $row = $pdo->query('SELECT turnstile_site_key, turnstile_secret_key FROM site_settings LIMIT 1')->fetch();
        } catch (Throwable $e) {
            $row = false;
        }

        $siteKey = trim((string) ($row['turnstile_site_key'] ?? ''));
        $secretKey = trim((string) ($row['turnstile_secret_key'] ?? ''));

        return [
            'enabled'    => $siteKey !== '' && $secretKey !== '',
            'site_key'   => $siteKey,
            'secret_key' => $secretKey,
        ];
    }
}

if (!function_exists('cms_turnstile_verify')) {
    /**
     * Verifies a widget response token against Cloudflare's siteverify
     * endpoint. Fails OPEN on any network/parsing error (returns true) so
     * a Cloudflare outage never blocks the whole contact form -- the
     * honeypot field stays as a second, independent layer regardless.
     */
    function cms_turnstile_verify(string $token, string $secretKey, ?string $remoteIp = null): bool
    {
        if ($token === '' || $secretKey === '') {
            return false;
        }

        if (!function_exists('curl_init')) {
            // No cURL available -- can't verify, don't block real users
            // over a server misconfiguration the admin needs to fix anyway.
            return true;
        }

        $postFields = ['secret' => $secretKey, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $postFields['remoteip'] = $remoteIp;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WPM-ContactForm/1.0',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return true; // fail open — see docblock
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }
}
