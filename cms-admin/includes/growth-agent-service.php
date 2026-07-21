<?php
declare(strict_types=1);

/**
 * Growth Agent — Fase 2 instrumentation schema + logging helper.
 *
 * Four tables, self-created on first use via cms_ensure_table() (same lazy
 * pattern as sitemap-service.php's cms_sitemap_ensure_schema()), plus a
 * formal record in cms-admin/migrations/014_growth_agent.sql:
 *
 *   growth_agent_jobs        one row per generation attempt (manual click
 *                            today, scheduled Growth Agent runs later).
 *                            Statuses drive the stat cards on
 *                            pages/growth-agent.php: ready, running,
 *                            succeeded, failed, manual_action.
 *   growth_agent_feedback    human approve/edit/reject signal against a
 *                            job — this is what lets a past job be reused
 *                            as a few-shot example (see
 *                            services/GrowthAgentPromptBuilder.php).
 *   growth_agent_style_rules living style guide, manually curated for now
 *                            (source='auto_extracted' is reserved for a
 *                            later phase — nothing writes it yet).
 *   growth_agent_performance traffic/ranking signal per page. Schema only
 *                            — nothing ingests into it yet, since there's
 *                            no GA/Search Console integration in this repo.
 *                            Kept here so the column shape is settled
 *                            ahead of that follow-up work.
 *
 * No FK constraints, matching this codebase's existing convention
 * (article_tag_map etc. use plain indexed columns, not CONSTRAINT ...
 * FOREIGN KEY) — app-level integrity, not DB-enforced.
 */
function cms_growth_agent_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_jobs',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_type VARCHAR(50) NOT NULL COMMENT 'e.g. seo_meta, article_draft',
         agent_key VARCHAR(50) NOT NULL COMMENT 'matches ai_agent_settings.agent_key',
         page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id — null if not saved yet',
         status ENUM('ready','running','succeeded','failed','manual_action') NOT NULL DEFAULT 'running',
         input_brief TEXT DEFAULT NULL COMMENT 'JSON snapshot of what was sent to the agent',
         output_json TEXT DEFAULT NULL COMMENT 'JSON snapshot of the parsed result',
         model_used VARCHAR(100) DEFAULT NULL,
         tokens_in INT UNSIGNED DEFAULT NULL,
         tokens_out INT UNSIGNED DEFAULT NULL,
         latency_ms INT UNSIGNED DEFAULT NULL,
         error_message TEXT DEFAULT NULL,
         created_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id, null = system',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_gaj_status (status),
         KEY idx_gaj_page (page_id),
         KEY idx_gaj_agent_key (agent_key)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_feedback',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_id INT UNSIGNED NOT NULL,
         action ENUM('approved_as_is','approved_with_edits','rejected') NOT NULL,
         notes TEXT DEFAULT NULL,
         reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gaf_job (job_id)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_style_rules',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         rule_text TEXT NOT NULL,
         source ENUM('manual','auto_extracted') NOT NULL DEFAULT 'manual',
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         created_by INT UNSIGNED DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gasr_active (is_active)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_performance',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_id INT UNSIGNED NOT NULL,
         metric_date DATE NOT NULL,
         pageviews INT UNSIGNED NOT NULL DEFAULT 0,
         avg_ranking_position DECIMAL(6,2) DEFAULT NULL,
         clicks INT UNSIGNED NOT NULL DEFAULT 0,
         ctr DECIMAL(6,4) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gap_page_date (page_id, metric_date)"
    );

    cms_growth_agent_ensure_priority_enum($pdo);
}

/**
 * Widens growth_agent_jobs.priority from the 015-era 2-tier enum
 * ('normal','high') to the 3-tier ('low','medium','high') used by
 * Prioritized Opportunities (016_gsc_opportunities.sql) — see
 * docs/GSC_OPPORTUNITIES_REVISION.md. Only ever runs the ALTERs when the
 * column is still in the old shape (checked via information_schema, not
 * a try/catch-and-ignore) — cms_ensure_column() can ADD a missing column
 * but can't widen an existing enum, so this fills that gap. Safe to call
 * on every page load, same idempotent spirit as cms_ensure_table().
 */
function cms_growth_agent_ensure_priority_enum(PDO $pdo): void
{
    $check = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_jobs' AND COLUMN_NAME = 'priority'"
    );
    $check->execute();
    $columnType = (string) $check->fetchColumn();

    if ($columnType === '' || str_contains($columnType, "'medium'")) {
        return; // column doesn't exist yet (cms_ensure_column() below handles that) or already widened
    }

    // 3-step MySQL enum rename: widen (keep 'normal' valid) -> migrate
    // existing data -> narrow to the final 3-tier shape. A direct MODIFY
    // straight to the new enum would silently blank out any row still
    // holding 'normal', since that value wouldn't exist in the new list.
    $pdo->exec("ALTER TABLE `growth_agent_jobs` MODIFY COLUMN `priority` ENUM('normal','low','medium','high') NOT NULL DEFAULT 'normal'");
    $pdo->exec("UPDATE `growth_agent_jobs` SET `priority` = 'medium' WHERE `priority` = 'normal'");
    $pdo->exec("ALTER TABLE `growth_agent_jobs` MODIFY COLUMN `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium'");
}

/**
 * Insert one growth_agent_jobs row. Never throws — a logging failure must
 * never break the actual generate response, matching cms_ai_log()'s own
 * philosophy in ai-helpers.php. Returns the new job id, or 0 on failure.
 *
 * @param array<string, mixed>      $inputBrief  JSON-encoded verbatim.
 * @param array<string, mixed>|null $outputData  JSON-encoded verbatim, null if the job failed before producing output.
 */
function cms_growth_agent_log_job(
    PDO $pdo,
    string $jobType,
    string $agentKey,
    ?int $pageId,
    string $status,
    array $inputBrief,
    ?array $outputData,
    ?string $modelUsed,
    ?int $tokensIn,
    ?int $tokensOut,
    ?int $latencyMs,
    string $errorMessage = '',
    string $priority = 'medium'
): int {
    try {
        cms_growth_agent_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO growth_agent_jobs
                (job_type, agent_key, page_id, status, priority, input_brief, output_json, model_used, tokens_in, tokens_out, latency_ms, error_message, created_by, created_at, updated_at)
             VALUES
                (:job_type, :agent_key, :page_id, :status, :priority, :input_brief, :output_json, :model_used, :tokens_in, :tokens_out, :latency_ms, :error_message, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            'job_type'      => $jobType,
            'agent_key'     => $agentKey,
            'page_id'       => $pageId,
            'status'        => $status,
            'priority'      => in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium',
            'input_brief'   => json_encode($inputBrief, JSON_UNESCAPED_UNICODE),
            'output_json'   => $outputData !== null ? json_encode($outputData, JSON_UNESCAPED_UNICODE) : null,
            'model_used'    => $modelUsed,
            'tokens_in'     => $tokensIn,
            'tokens_out'    => $tokensOut,
            'latency_ms'    => $latencyMs,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
            'created_by'    => (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_job] Failed logging job: ' . $e->getMessage());
        return 0;
    }
}

/**
 * "Apply SEO Recommendation" — the scan half of the review/apply flow.
 *
 * Triggered by the manual "Scan for SEO improvements" button on
 * pages/growth-agent.php (not automatic/scheduled — see the flow diagram
 * the operator approved: Scan -> Resolve Target -> SEO child action ->
 * Review & Apply). For up to $limit published articles that have never
 * been scanned (or already scanned+actioned) before, asks the seo_agent
 * to review the CURRENT meta_title/meta_description and suggest an
 * improvement, then logs one growth_agent_jobs row per article with
 * status='manual_action' — job_type='seo_recommendation' reuses the exact
 * same jobs table as seo_meta/article_draft/faq, it just has a distinct
 * review UI (pages/seo-recommendation-review.php) instead of the generic
 * Approve/Reject buttons, because "approve" here must actually write the
 * new values into the pages table, not just mark a job succeeded.
 *
 * Never throws — a scan failure must not break the Growth Agent page.
 *
 * @return array{scanned:int, created:int, errors:int}
 */
function cms_growth_agent_scan_seo_recommendations(PDO $pdo, int $limit = 5): array
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $limit = max(1, min(20, $limit));

        $stmt = $pdo->prepare(
            "SELECT page_id, title, slug, excerpt, content, meta_title, meta_description
               FROM pages
              WHERE status = 'published'
                AND page_id NOT IN (
                    SELECT page_id FROM growth_agent_jobs
                     WHERE job_type = 'seo_recommendation' AND page_id IS NOT NULL
                       AND status IN ('manual_action', 'succeeded')
                )
              ORDER BY updated_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['scanned' => 0, 'created' => 0, 'errors' => 0];
    }

    return cms_growth_agent_run_seo_recommendation_scan($pdo, $pages);
}

/**
 * Shared engine behind cms_growth_agent_scan_seo_recommendations() (date-
 * based candidate selection) and the on-demand "Generate" dispatch from a
 * Prioritized Opportunity row (docs/GSC_OPPORTUNITIES_REVISION.md § 5,
 * called from pages/growth-agent.php with a single-page array) — both
 * just select candidate pages differently, then hand them to this
 * function to actually call the AI, parse the result, and log one
 * job_type='seo_recommendation' row per page. Extracted so the two
 * candidate-selection strategies never drift out of sync on the
 * generate/parse/log logic itself. Reused as-is (unchanged) by the
 * Prioritized Opportunities revision — it already accepted an arbitrary
 * page list + per-page priority map before that revision existed.
 *
 * @param list<array<string, mixed>> $pages
 * @param array<int, string> $priorityMap page_id => 'low'|'medium'|'high', defaults to 'medium' when a page isn't in the map
 * @return array{scanned:int, created:int, errors:int}
 */
function cms_growth_agent_run_seo_recommendation_scan(PDO $pdo, array $pages, array $priorityMap = []): array
{
    // job_ids: every job actually logged (success or failure), in order —
    // added for the Prioritized Opportunities dispatch (called with a
    // single-page array), which needs the new job's id to link
    // gsc_opportunities.linked_job_id. The original bulk/date-based
    // caller ignores this key, so it's purely additive.
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0, 'job_ids' => []];

    if ($pages === []) {
        return $stats;
    }

    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return $stats;
    }

    $defaultSystemPrompt =
        'You are "Agent SEO" reviewing the EXISTING meta_title and meta_description of a published ' .
        'SagaCrypto article. Given the article title, slug, excerpt, content, and its current ' .
        'meta_title/meta_description, suggest an improved meta_title (max 60 characters) and ' .
        'meta_description (max 155 characters) that is more compelling and better optimized for search, ' .
        'in the same language as the content (default Bahasa Indonesia). If the current metadata is ' .
        'already strong, a small refinement is fine — do not change things just to change them. ' .
        'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
        'this shape: {"recommended_meta_title": "...", "recommended_meta_description": "..."}';

    $agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return $stats;
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'seo_recommendation'));
    } catch (Throwable $e) {
        // Ignore — scan proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    foreach ($pages as $page) {
        $stats['scanned']++;
        $pageId = (int) $page['page_id'];
        $priority = $priorityMap[$pageId] ?? 'medium';

        $currentMetaTitle = (string) ($page['meta_title'] ?? '');
        $currentMetaDescription = (string) ($page['meta_description'] ?? '');

        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Current meta_title: {$currentMetaTitle}\nCurrent meta_description: {$currentMetaDescription}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

        $inputBrief = [
            'title' => (string) $page['title'],
            'slug' => (string) $page['slug'],
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
        ];
        if (isset($page['total_impressions'])) {
            $inputBrief['gsc_impressions'] = (int) $page['total_impressions'];
            $inputBrief['gsc_clicks'] = (int) ($page['total_clicks'] ?? 0);
        }

        try {
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $userPrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
        } catch (Throwable $e) {
            $stats['errors']++;
            continue;
        }

        $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        $retried = false;

        if ($result['success'] && (!is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description']))) {
            $retried = true;
            $correctivePrompt = $userPrompt .
                "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
                'no markdown, no code fences, no commentary, in exactly this shape: ' .
                '{"recommended_meta_title": "...", "recommended_meta_description": "..."}';
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $correctivePrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
            $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        }

        $usage = is_array($result['raw'] ?? null) ? ($result['raw']['usage'] ?? []) : [];
        $tokensIn  = $agent['provider'] === 'openai' ? (int) ($usage['prompt_tokens'] ?? 0) : (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = $agent['provider'] === 'openai' ? (int) ($usage['completion_tokens'] ?? 0) : (int) ($usage['output_tokens'] ?? 0);

        if (!$result['success'] || !is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description'])) {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                ($result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']))
                    . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $recommendedMetaTitle = mb_substr(trim((string) $parsed['recommended_meta_title']), 0, 255);
        $recommendedMetaDescription = mb_substr(trim((string) $parsed['recommended_meta_description']), 0, 255);

        if ($recommendedMetaTitle === '' || $recommendedMetaDescription === '') {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                'AI returned an empty recommendation' . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $output = [
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
            'recommended_meta_title' => $recommendedMetaTitle,
            'recommended_meta_description' => $recommendedMetaDescription,
        ];

        $stats['job_ids'][] = cms_growth_agent_log_job(
            $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'manual_action', $inputBrief, $output,
            $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
            '', $priority
        );
        $stats['created']++;
    }

    return $stats;
}

/**
 * Tipe 2 (striking distance / "Page-one" category) — existing article,
 * content optimization candidate. Generates ONE job for ONE page, called
 * on-demand when the operator clicks "Generate" on a Prioritized
 * Opportunities row (docs/GSC_OPPORTUNITIES_REVISION.md § 5) — candidate
 * selection/scoring already happened in cms_gsc_compute_opportunities()
 * (gsc-api.php), this function only does the AI call + log. Does NOT
 * write anywhere itself — produces suggested content additions the human
 * copy/pastes in manually, so it uses the generic Approve/Reject flow on
 * pages/growth-agent.php (status goes straight to succeeded/failed, no
 * 'manual_action' apply step, same as article_draft/faq generation).
 *
 * @param array{page_id:int|string, title:string, slug:string, excerpt:string, content:string, avg_position:float|int, impressions:int, top_queries:string} $page
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_content_optimization(PDO $pdo, array $page, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $pageId = (int) $page['page_id'];
    $impressions = (int) ($page['impressions'] ?? 0);
    $avgPosition = (float) ($page['avg_position'] ?? 0);
    $topQueries = (string) ($page['top_queries'] ?? '');

    $defaultSystemPrompt =
        'You are the Growth Agent content strategist for SagaCrypto, a crypto & market news website. ' .
        'You are given an existing PUBLISHED article that already ranks close to page one for certain ' .
        'search queries but has not broken into the top 10 yet ("striking distance"). Given the article ' .
        'title, slug, excerpt, content, its average ranking position, and the queries it ranks for, ' .
        'suggest concrete content improvements — additional sections or subheadings to add, points to ' .
        'expand, related sub-topics to cover — that would plausibly help it rank higher for those specific ' .
        'queries. Do not suggest changing the meta title/description (a separate tool handles that). ' .
        'Respond in the same language as the article content (default Bahasa Indonesia). Respond with ONLY ' .
        'a raw JSON object, no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"suggested_sections": ["...", "..."], "summary": "..."}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_content_optimization'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
        "Average ranking position: " . round($avgPosition, 1) . "\n" .
        "Total impressions (recent window): {$impressions}\n" .
        "Ranks for these queries: {$topQueries}\n" .
        "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

    $inputBrief = [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'avg_position' => round($avgPosition, 1),
        'gsc_impressions' => $impressions,
        'top_queries' => $topQueries,
    ];

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['suggested_sections'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Tipe 3 ("No article" category) — new article idea candidate. Generates
 * ONE job for ONE query, called on-demand when the operator clicks
 * "Generate" on a Prioritized Opportunities row
 * (docs/GSC_OPPORTUNITIES_REVISION.md § 5) — candidate selection/scoring
 * (including the "already suggested this query before" exclusion) already
 * happened in cms_gsc_compute_opportunities() (gsc-api.php), this
 * function only does the AI call + log. Also flows through the generic
 * Approve/Reject queue on pages/growth-agent.php, same as Tipe 2.
 *
 * @param array{query:string, impressions:int, avg_position:float|int} $queryData
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_article_idea(PDO $pdo, array $queryData, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $query = (string) $queryData['query'];
    $impressions = (int) ($queryData['impressions'] ?? 0);
    $avgPosition = (float) ($queryData['avg_position'] ?? 0);

    $defaultSystemPrompt =
        'You are the Growth Agent content strategist for SagaCrypto, a crypto & market news website. ' .
        'You are given a search query that gets meaningful search impressions but has NO existing article ' .
        'on the site addressing it. Propose a new article idea: a compelling title, and a short outline ' .
        '(3-6 bullet points) covering what the article should include. Keep it realistic for a news/guide ' .
        'site — not a generic listicle. Respond in the same language as the query (default Bahasa ' .
        'Indonesia). Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in ' .
        'exactly this shape: {"title": "...", "outline": ["...", "..."]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_article_idea'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $userPrompt = "Search query: {$query}\n" .
        "Total impressions (recent window): {$impressions}\n" .
        "Average position: " . round($avgPosition, 1);

    $inputBrief = [
        'query' => $query,
        'gsc_impressions' => $impressions,
        'avg_position' => round($avgPosition, 1),
    ];

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['title'], $parsed['outline'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_article_idea', 'growth_agent', null, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_article_idea', 'growth_agent', null, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Delete old, already-resolved growth_agent_jobs rows so the table doesn't
 * grow forever (there's no cron in this codebase — see sitemap-service.php's
 * own note that everything here runs synchronously on request, not on a
 * schedule — so this is invoked both lazily on every growth-agent.php page
 * load, matching the cms_ensure_table() "self-maintaining on request"
 * pattern, and via an explicit "Bersihkan job lama" button for on-demand use).
 *
 * Deliberately conservative about what it deletes:
 *   - 'ready' / 'running' / 'manual_action' jobs are NEVER touched, no
 *     matter how old — 'manual_action' still needs a human decision
 *     (e.g. an un-reviewed SEO recommendation), and 'ready'/'running'
 *     are still in flight.
 *   - 'failed' jobs older than the retention window are deleted — a
 *     failed generation has no future use once it's old.
 *   - 'succeeded' jobs older than the window are deleted UNLESS a human
 *     explicitly approved it as-is (growth_agent_feedback.action =
 *     'approved_as_is') — those are the Fase 3 few-shot example pool
 *     (see GrowthAgentPromptBuilder::approvedExamples()) and must survive
 *     cleanup indefinitely, or future generations quietly lose their
 *     reference examples.
 *
 * Never throws. Returns the number of jobs deleted (0 on any failure).
 */
function cms_growth_agent_cleanup_old_jobs(PDO $pdo, int $retentionDays = 90): int
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $days = max(7, min(365, $retentionDays));

        $idStmt = $pdo->query(
            "SELECT id FROM growth_agent_jobs
              WHERE created_at < (NOW() - INTERVAL {$days} DAY)
                AND (
                    status = 'failed'
                    OR (
                        status = 'succeeded'
                        AND id NOT IN (SELECT job_id FROM growth_agent_feedback WHERE action = 'approved_as_is')
                    )
                )"
        );
        $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM growth_agent_feedback WHERE job_id IN ($placeholders)")->execute($ids);
        $pdo->prepare("DELETE FROM growth_agent_jobs WHERE id IN ($placeholders)")->execute($ids);

        return count($ids);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_cleanup_old_jobs] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Feeds the notification bell in includes/navbar.php (shown on every admin
 * page). "Needs attention" means: a generation that failed (retryable), or
 * a manual_action job awaiting a human decision (currently only
 * seo_recommendation jobs use that status — see
 * cms_growth_agent_scan_seo_recommendations()). 'ready'/'running' jobs are
 * excluded on purpose — they're not problems, just in-flight/queued work.
 *
 * Never throws — a notification lookup failing must never break every
 * single admin page. Returns ['count' => int, 'items' => array] with
 * count reflecting the TOTAL number needing attention (not capped by
 * $limit — $limit only bounds how many are listed in the dropdown).
 *
 * @return array{count:int, items:array<int, array<string, mixed>>}
 */
function cms_growth_agent_notifications(PDO $pdo, int $limit = 8): array
{
    $result = ['count' => 0, 'items' => []];

    try {
        cms_growth_agent_ensure_schema($pdo);

        $countRow = $pdo->query(
            "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status IN ('failed', 'manual_action')"
        )->fetch();
        $result['count'] = (int) ($countRow['cnt'] ?? 0);

        if ($result['count'] === 0) {
            return $result;
        }

        $stmt = $pdo->prepare(
            "SELECT j.id, j.job_type, j.status, j.created_at, p.title AS page_title
               FROM growth_agent_jobs j
               LEFT JOIN pages p ON p.page_id = j.page_id
              WHERE j.status IN ('failed', 'manual_action')
              ORDER BY j.created_at DESC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute();
        $result['items'] = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_notifications] Failed: ' . $e->getMessage());
    }

    return $result;
}

// ── Agent Memory (docs/GROWTH_AGENT_MEMORY_PLAN.md) ──────────────────────
//
// Winning patterns / content gaps detected from gsc_query_data aggregated
// across its FULL retention window (fetch_window_days, default 90 days —
// not a single fetch snapshot, unlike gsc_opportunities). Every new draft
// starts 'pending_review'; only 'active' entries are folded into
// GrowthAgentPromptBuilder context, and only for job_type=gsc_article_idea
// (see GrowthAgentPromptBuilder::activeMemoryEntries()).

/**
 * Lazy trigger — same "self-maintaining on request" spirit as
 * cms_gsc_fetch_if_stale(), but a much longer default interval
 * (memory_thresholds_json.detection_interval_days, default 7 days): "is
 * this pattern still consistent" changes slowly, and re-running the
 * full-window GROUP BY every page load would be wasted work producing
 * near-identical drafts (review fatigue). A no-op if GSC isn't connected
 * yet — there's nothing to detect patterns in. Never throws.
 */
function cms_growth_agent_detect_memory_if_stale(PDO $pdo, ?int $maxAgeDaysOverride = null): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);
        if ((int) ($settings['is_active'] ?? 0) !== 1 || empty($settings['site_url'])) {
            return;
        }

        $thresholds = cms_gsc_get_memory_thresholds($pdo);
        $maxAgeDays = $maxAgeDaysOverride ?? (int) $thresholds['detection_interval_days'];

        $lastRun = $settings['last_memory_detection_at'] ?? null;
        $isStale = $lastRun === null || (time() - strtotime((string) $lastRun)) >= (max(1, $maxAgeDays) * 86400);

        if ($isStale) {
            cms_growth_agent_detect_memory_patterns($pdo);
        }
    } catch (Throwable $e) {
        // A lazy background detection pass must never break the page it's attached to.
    }
}

/**
 * Runs both detection queries (winning_pattern, content_gap) against the
 * full gsc_query_data window, upserts results into growth_agent_memory,
 * then runs the retention sweep in the same pass (docs/GROWTH_AGENT_MEMORY_PLAN.md
 * § 5) and stamps gsc_settings.last_memory_detection_at. Called both by
 * the lazy trigger above and the manual "Analisis Pola" button. Never
 * throws.
 *
 * @return array{winning_pattern:int, content_gap:int, archived:int}
 */
function cms_growth_agent_detect_memory_patterns(PDO $pdo): array
{
    $stats = ['winning_pattern' => 0, 'content_gap' => 0, 'archived' => 0];

    try {
        require_once __DIR__ . '/gsc-api.php';
        cms_gsc_ensure_schema($pdo);
        $thresholds = cms_gsc_get_memory_thresholds($pdo);
    } catch (Throwable $e) {
        return $stats;
    }

    $minWeeks = max(1, (int) $thresholds['min_distinct_weeks']);
    $minImpressions = max(0, (int) $thresholds['min_impressions']);
    $winningCtr = (float) $thresholds['winning_ctr_threshold'];
    $winningPosition = (float) $thresholds['winning_position_threshold'];

    try {
        $stmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date)) AS distinct_weeks,
                    AVG(ctr) AS avg_ctr,
                    AVG(position) AS avg_position,
                    SUM(impressions) AS total_impressions
               FROM gsc_query_data
              GROUP BY query
             HAVING distinct_weeks >= :min_weeks
                AND total_impressions >= :min_impressions
                AND (avg_ctr >= :winning_ctr OR avg_position <= :winning_position)"
        );
        $stmt->execute([
            'min_weeks' => $minWeeks,
            'min_impressions' => $minImpressions,
            'winning_ctr' => $winningCtr,
            'winning_position' => $winningPosition,
        ]);
        $winners = $stmt->fetchAll();
    } catch (Throwable $e) {
        $winners = [];
    }

    foreach ($winners as $row) {
        if (cms_growth_agent_memory_upsert($pdo, 'winning_pattern', $row)) {
            $stats['winning_pattern']++;
        }
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date)) AS distinct_weeks,
                    SUM(impressions) AS total_impressions,
                    AVG(position) AS avg_position
               FROM gsc_query_data
              GROUP BY query
             HAVING distinct_weeks >= :min_weeks
                AND total_impressions >= :min_impressions
                AND SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0"
        );
        $stmt->execute([
            'min_weeks' => $minWeeks,
            'min_impressions' => $minImpressions,
        ]);
        $gaps = $stmt->fetchAll();
    } catch (Throwable $e) {
        $gaps = [];
    }

    foreach ($gaps as $row) {
        if (cms_growth_agent_memory_upsert($pdo, 'content_gap', $row)) {
            $stats['content_gap']++;
        }
    }

    $stats['archived'] = cms_growth_agent_memory_retention_sweep($pdo, $thresholds);

    try {
        $pdo->exec('UPDATE gsc_settings SET last_memory_detection_at = NOW() ORDER BY id ASC LIMIT 1');
    } catch (Throwable $e) {
        // Non-fatal — detection itself already ran independently of this bookkeeping.
    }

    return $stats;
}

/**
 * Upserts one detected pattern by dedupe_key (MD5 of insight_type|query).
 * Three cases, per docs/GROWTH_AGENT_MEMORY_PLAN.md § 2 & § 5:
 *   - No existing row              → INSERT fresh, status='pending_review'.
 *   - Existing, archived_reason='rejected' → suppressed permanently, no-op.
 *   - Existing, archived_reason IN ('stale_pending','stale_active')
 *                                   → revived as a fresh 'pending_review'
 *                                     draft (auto-archived-by-age is not
 *                                     a permanent rejection).
 *   - Existing, status IN ('pending_review','active') → numbers refreshed
 *     in place, last_confirmed_at bumped, status untouched (an approved
 *     entry doesn't get bounced back to review just because the pattern
 *     still holds; an unreviewed draft doesn't get duplicated either).
 *
 * @param array{query:string, distinct_weeks:int|string, total_impressions:int|string, avg_position:float|string, avg_ctr?:float|string} $row
 * @return bool true only when this resulted in a brand-new (or revived) pending_review draft
 */
function cms_growth_agent_memory_upsert(PDO $pdo, string $insightType, array $row): bool
{
    $query = (string) $row['query'];
    $dedupeKey = md5($insightType . '|' . $query);

    $metrics = [
        'query' => $query,
        'distinct_weeks' => (int) $row['distinct_weeks'],
        'total_impressions' => (int) $row['total_impressions'],
        'avg_position' => round((float) ($row['avg_position'] ?? 0), 1),
    ];
    if ($insightType === 'winning_pattern') {
        $metrics['avg_ctr'] = round((float) ($row['avg_ctr'] ?? 0), 4);
    }
    [$title, $description] = cms_growth_agent_memory_build_copy($insightType, $metrics);
    $dataJson = json_encode($metrics, JSON_UNESCAPED_UNICODE);

    $existingStmt = $pdo->prepare('SELECT id, status, archived_reason FROM growth_agent_memory WHERE dedupe_key = :key LIMIT 1');
    $existingStmt->execute(['key' => $dedupeKey]);
    $existing = $existingStmt->fetch();

    if (!$existing) {
        $pdo->prepare(
            "INSERT INTO growth_agent_memory
                (insight_type, title, description, supporting_data_json, status, dedupe_key, detected_at, last_confirmed_at, created_at)
             VALUES
                (:insight_type, :title, :description, :data, 'pending_review', :dedupe_key, NOW(), NOW(), NOW())"
        )->execute([
            'insight_type' => $insightType,
            'title' => $title,
            'description' => $description,
            'data' => $dataJson,
            'dedupe_key' => $dedupeKey,
        ]);
        return true;
    }

    if ($existing['status'] === 'archived') {
        if ($existing['archived_reason'] === 'rejected') {
            return false; // explicit reject — permanently suppressed
        }
        // stale_pending / stale_active — revive as a fresh draft.
        $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'pending_review', archived_reason = NULL,
                    title = :title, description = :description, supporting_data_json = :data,
                    reviewed_by = NULL, reviewed_at = NULL,
                    detected_at = NOW(), last_confirmed_at = NOW()
              WHERE id = :id"
        )->execute([
            'title' => $title,
            'description' => $description,
            'data' => $dataJson,
            'id' => $existing['id'],
        ]);
        return true;
    }

    // pending_review or active — refresh numbers only, status untouched.
    $pdo->prepare(
        'UPDATE growth_agent_memory
            SET description = :description, supporting_data_json = :data, last_confirmed_at = NOW()
          WHERE id = :id'
    )->execute([
        'description' => $description,
        'data' => $dataJson,
        'id' => $existing['id'],
    ]);
    return false;
}

/**
 * Parametrized narrative — NOT AI-generated (same reasoning as
 * cms_gsc_build_opportunity_reason() in gsc-api.php: drafts must be free
 * and instant, no token cost just to list a pattern).
 *
 * @param array<string, mixed> $metrics
 * @return array{0:string, 1:string} [title, description]
 */
function cms_growth_agent_memory_build_copy(string $insightType, array $metrics): array
{
    $query = (string) $metrics['query'];
    $weeks = (int) $metrics['distinct_weeks'];
    $impressions = (int) $metrics['total_impressions'];
    $position = (float) $metrics['avg_position'];

    if ($insightType === 'winning_pattern') {
        $ctrPct = round(((float) ($metrics['avg_ctr'] ?? 0)) * 100, 2);
        $title = "Query \"{$query}\" konsisten performa bagus";
        $description = "Query \"{$query}\" muncul di {$weeks} minggu berbeda dalam data yang terkumpul, dengan total " .
            "{$impressions} impressions, rata-rata CTR {$ctrPct}%, dan posisi rata-rata {$position}. Pola ini " .
            "konsisten dari waktu ke waktu, bukan lonjakan satu kali.";
        return [$title, $description];
    }

    $title = "Query \"{$query}\" berulang muncul tanpa artikel yang cocok";
    $description = "Query \"{$query}\" muncul di {$weeks} minggu berbeda dalam data yang terkumpul, dengan total " .
        "{$impressions} impressions (posisi rata-rata {$position}), tapi situs belum punya artikel yang " .
        "membahasnya sama sekali di semua periode itu.";
    return [$title, $description];
}

/**
 * Archives entries that have gone stale (docs/GROWTH_AGENT_MEMORY_PLAN.md
 * § 5) — 'pending_review' left unreviewed past pending_review_stale_days
 * (default 30, counted from detected_at), and 'active' entries whose
 * pattern hasn't been reconfirmed by a detection pass in active_stale_days
 * (default 90, counted from last_confirmed_at — NOT detected_at, so a
 * pattern that keeps holding true never goes stale just because it's
 * old). Never deletes — archived rows are kept as a record and simply
 * stop being folded into AI context (§ 4). Never throws.
 */
function cms_growth_agent_memory_retention_sweep(PDO $pdo, ?array $thresholds = null): int
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $thresholds = $thresholds ?? cms_gsc_get_memory_thresholds($pdo);
        $pendingDays = max(1, (int) $thresholds['pending_review_stale_days']);
        $activeDays = max(1, (int) $thresholds['active_stale_days']);

        $archived = 0;

        $stmt1 = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'archived', archived_reason = 'stale_pending'
              WHERE status = 'pending_review' AND detected_at < (NOW() - INTERVAL :days DAY)"
        );
        $stmt1->execute(['days' => $pendingDays]);
        $archived += $stmt1->rowCount();

        $stmt2 = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'archived', archived_reason = 'stale_active'
              WHERE status = 'active' AND last_confirmed_at < (NOW() - INTERVAL :days DAY)"
        );
        $stmt2->execute(['days' => $activeDays]);
        $archived += $stmt2->rowCount();

        return $archived;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Review actions — dispatched from pages/growth-agent.php. Each scoped to
 * the expected current status (WHERE status = '...') so a stale/double
 * form submission is naturally a no-op (rowCount 0) instead of
 * corrupting state.
 */
function cms_growth_agent_memory_approve(PDO $pdo, int $id, ?int $reviewerId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE growth_agent_memory
            SET status = 'active', archived_reason = NULL, reviewed_by = :reviewer, reviewed_at = NOW()
          WHERE id = :id AND status = 'pending_review'"
    );
    $stmt->execute(['reviewer' => $reviewerId, 'id' => $id]);
    return $stmt->rowCount() > 0;
}

function cms_growth_agent_memory_reject(PDO $pdo, int $id, ?int $reviewerId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE growth_agent_memory
            SET status = 'archived', archived_reason = 'rejected', reviewed_by = :reviewer, reviewed_at = NOW()
          WHERE id = :id AND status = 'pending_review'"
    );
    $stmt->execute(['reviewer' => $reviewerId, 'id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Manual early-retirement of an 'active' entry (admin decides to turn it
 * off before the automatic staleness sweep would). Reuses the
 * 'stale_active' reason bucket — both mean "no longer active, but not
 * because it was rejected as wrong"; a dedicated "manually retired"
 * reason isn't needed for MVP.
 */
function cms_growth_agent_memory_archive(PDO $pdo, int $id, ?int $reviewerId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE growth_agent_memory
            SET status = 'archived', archived_reason = 'stale_active', reviewed_by = :reviewer, reviewed_at = NOW()
          WHERE id = :id AND status = 'active'"
    );
    $stmt->execute(['reviewer' => $reviewerId, 'id' => $id]);
    return $stmt->rowCount() > 0;
}
