<?php
declare(strict_types=1);

/**
 * AI endpoint helpers — rate limiting and usage logging.
 *
 * Rate limiter: session-based, no database, no schema.
 * Logger:       file-based (logs/ai.log), auto-creates directory, no DB.
 *
 * Call order in API endpoints:
 *   1. auth.php  (CSRF validated, session open)
 *   2. cms_ai_rate_limit()   ← session must still be writable here
 *   3. session_write_close()
 *   4. set_time_limit()
 *   5. AI call
 *   6. cms_ai_log()
 */

/**
 * Session-based AI request rate limiter.
 *
 * Must be called while the session is still open (before session_write_close).
 * Exits with HTTP 429 and a JSON error body if the limit is exceeded.
 *
 * @param int $max                    Maximum requests allowed within the window.
 * @param int $window                 Window size in seconds.
 * @param array<string, mixed>|null   Optional endpoint-specific JSON error shape.
 */
function cms_ai_rate_limit(int $max = 5, int $window = 60, ?array $errorPayload = null): void
{
    $countKey = 'ai_rl_count';
    $startKey = 'ai_rl_start';

    $now   = time();
    $start = (int) ($_SESSION[$startKey] ?? 0);
    $count = (int) ($_SESSION[$countKey] ?? 0);

    // Start a fresh window if the current one has expired.
    if ($now - $start > $window) {
        $_SESSION[$startKey] = $now;
        $_SESSION[$countKey] = 0;
        $count = 0;
    }

    if ($count >= $max) {
        $retryAfter = max(1, $window - ($now - $start));
        $message = 'Too many AI requests. Please wait ' . $retryAfter . ' second(s) and try again.';
        $payload = $errorPayload ?? [
            'success'          => false,
            'meta_title'       => '',
            'meta_description' => '',
            'error'            => '',
        ];
        $payload['error'] = $message;

        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION[$countKey] = $count + 1;
}

/**
 * Append one line to logs/ai.log (auto-creates the directory on first use).
 *
 * Returns true on successful write, false on failure.
 * Never throws — a logging failure must never break the AI endpoint response.
 *
 * Fields logged (pipe-separated):
 *   timestamp | endpoint | type | success|failure | model | http_status
 *   | latency_ms | prompt_char_length | error_summary (capped 200 chars)
 *
 * Never logged: API key, full prompt text, full response, session ID.
 *
 * @param string $endpoint     e.g. 'seo-generate', 'article-generate', 'faq-generate'
 * @param string $type         e.g. 'product', 'page', 'special_page', 'article', 'faq'
 * @param bool   $success      Whether the AI call succeeded.
 * @param string $model        Model identifier from config.
 * @param int    $httpStatus   Anthropic HTTP status code (0 if unavailable).
 * @param int    $latencyMs    Round-trip latency in milliseconds.
 * @param int    $promptLen    Character length of the prompt (not the content).
 * @param string $errorSummary Short error description, max 200 chars.
 */
function cms_ai_log(
    string $endpoint,
    string $type,
    bool   $success,
    string $model,
    int    $httpStatus,
    int    $latencyMs,
    int    $promptLen,
    string $errorSummary = ''
): bool {
    // Resolve project root from this file's known location:
    // __DIR__  = <root>/cms-admin/includes  (2 levels below project root)
    $projectRoot = CMS_PROJECT_ROOT;
    $logDir      = $projectRoot . '/logs';
    $logPath     = $logDir . '/ai.log';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $fields = [
        date('Y-m-d H:i:s'),
        $endpoint,
        $type,
        $success ? 'success' : 'failure',
        'model='       . $model,
        'http='        . $httpStatus,
        'latency='     . $latencyMs . 'ms',
        'prompt_len='  . $promptLen,
    ];

    if ($errorSummary !== '') {
        $fields[] = 'error=' . substr($errorSummary, 0, 200);
    }

    $written = file_put_contents(
        $logPath,
        implode(' | ', $fields) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    if ($written === false) {
        error_log('[cms_ai_log] Failed writing AI log: ' . $logPath);
        return false;
    }

    return true;
}

/**
 * ── AI Management: credential encryption ─────────────────────────────────
 *
 * API keys are stored encrypted (AES-256-CBC) rather than in plaintext.
 * This is at-rest obfuscation against casual DB dumps/backups, not a
 * substitute for proper secrets management — anyone with PHP code
 * execution on the server can still decrypt. Derives its key from
 * CMS_AI_ENC_SECRET (config/app.php) if defined, else a built-in default.
 * Admins should override CMS_AI_ENC_SECRET in a real deployment.
 */
function cms_ai_enc_key(): string
{
    $secret = defined('CMS_AI_ENC_SECRET') ? CMS_AI_ENC_SECRET : 'thecms-ai-default-secret-change-me';
    return hash('sha256', 'thecms-ai-credentials|' . $secret, true);
}

function cms_ai_encrypt(string $plaintext): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', cms_ai_enc_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Failed to encrypt AI credential.');
    }
    return base64_encode($iv . $cipher);
}

function cms_ai_decrypt(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', cms_ai_enc_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/**
 * ── AI Management: provider call helpers ─────────────────────────────────
 *
 * Minimal cURL wrappers for Anthropic Messages API and OpenAI Chat
 * Completions API. Returns a normalized shape:
 *   ['success' => bool, 'text' => string, 'http_status' => int,
 *    'latency_ms' => int, 'error' => string, 'raw' => array|null]
 */
function cms_ai_call_anthropic(
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    $start = (int) round(microtime(true) * 1000);

    $body = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'messages' => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];
    if ($systemPrompt !== '') {
        $body['system'] = $systemPrompt;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $latency = (int) round(microtime(true) * 1000) - $start;

    if ($response === false) {
        return ['success' => false, 'text' => '', 'http_status' => 0, 'latency_ms' => $latency, 'error' => $curlError ?: 'cURL request failed.', 'raw' => null];
    }

    $decoded = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['success' => false, 'text' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => (string) $errMsg, 'raw' => is_array($decoded) ? $decoded : null];
    }

    $text = '';
    if (is_array($decoded) && !empty($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
    }

    return ['success' => true, 'text' => $text, 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => '', 'raw' => is_array($decoded) ? $decoded : null];
}

function cms_ai_call_openai(
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    $start = (int) round(microtime(true) * 1000);

    $messages = [];
    if ($systemPrompt !== '') {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    $messages[] = ['role' => 'user', 'content' => $userPrompt];

    $body = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $latency = (int) round(microtime(true) * 1000) - $start;

    if ($response === false) {
        return ['success' => false, 'text' => '', 'http_status' => 0, 'latency_ms' => $latency, 'error' => $curlError ?: 'cURL request failed.', 'raw' => null];
    }

    $decoded = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['success' => false, 'text' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => (string) $errMsg, 'raw' => is_array($decoded) ? $decoded : null];
    }

    $text = (string) ($decoded['choices'][0]['message']['content'] ?? '');

    return ['success' => true, 'text' => $text, 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => '', 'raw' => is_array($decoded) ? $decoded : null];
}

/**
 * Dispatch to the right provider call based on a provider string.
 */
function cms_ai_call_provider(
    string $provider,
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    return $provider === 'openai'
        ? cms_ai_call_openai($apiKey, $model, $userPrompt, $systemPrompt, $maxTokens, $temperature)
        : cms_ai_call_anthropic($apiKey, $model, $userPrompt, $systemPrompt, $maxTokens, $temperature);
}
