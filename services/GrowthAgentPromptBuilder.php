<?php
declare(strict_types=1);

/**
 * GrowthAgentPromptBuilder — Fase 3 "context-aware generate".
 *
 * Claude has no fine-tuning API, so "the agent learns the pattern I want"
 * is implemented as retrieval + prompt assembly instead of retraining:
 * every call pulls the currently-active style rules
 * (growth_agent_style_rules) and a handful of past jobs a human explicitly
 * approved without edits (growth_agent_jobs + growth_agent_feedback), and
 * folds them into the system prompt as a style guide + few-shot examples.
 *
 * Both tables start empty, so buildContext() returns '' until an admin
 * adds a style rule or approves a job on pages/growth-agent.php — zero
 * behavior change until then, same fallback philosophy as
 * cms_ai_resolve_agent()'s PromptLoader layering in ai-helpers.php.
 */
class GrowthAgentPromptBuilder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build the style-guide + few-shot block for one agent. Never throws —
     * callers should still wrap this in try/catch, since a schema-ensure
     * failure (e.g. no CREATE TABLE privilege) must never block generation.
     *
     * Agent Memory (growth_agent_memory — see docs/GROWTH_AGENT_MEMORY_PLAN.md)
     * is folded in as a third block, but ONLY when $jobType is
     * 'gsc_article_idea' — new-article ideation is the one place
     * historical winning-pattern/content-gap insights are relevant;
     * revising an existing article (gsc_content_optimization) or
     * rewriting its meta tags (seo_recommendation) doesn't need them.
     * Deliberately gated here, inside the one shared entry point, rather
     * than by asking each caller to remember to pass a flag — every
     * caller already invokes buildContext($agentKey, $jobType, ...) with
     * the correct $jobType for its own job, so this required zero changes
     * at any call site.
     */
    public function buildContext(string $agentKey, string $jobType, int $maxRules = 5, int $maxExamples = 3, int $maxMemoryEntries = 8): string
    {
        $parts = [];

        $rules = $this->activeStyleRules($maxRules);
        if ($rules !== []) {
            $parts[] = "House style guide — follow these rules:\n- " . implode("\n- ", $rules);
        }

        $examples = $this->approvedExamples($agentKey, $jobType, $maxExamples);
        if ($examples !== []) {
            $exampleBlocks = [];
            foreach ($examples as $i => $example) {
                $exampleBlocks[] = sprintf(
                    "Example %d — input:\n%s\nApproved output (a human editor accepted this as-is — match this style and quality):\n%s",
                    $i + 1,
                    $example['input_brief'],
                    $example['output_json']
                );
            }
            $parts[] = "Reference examples from past approved work:\n\n" . implode("\n\n", $exampleBlocks);
        }

        if ($jobType === 'gsc_article_idea') {
            $memoryLines = $this->activeMemoryEntries($maxMemoryEntries);
            if ($memoryLines !== []) {
                $parts[] = "Known patterns from historical search data (context to inform the idea, not literal " .
                    "instructions to follow word-for-word):\n- " . implode("\n- ", $memoryLines);
            }
        }

        return implode("\n\n", $parts);
    }

    /** @return string[] */
    private function activeStyleRules(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rule_text FROM growth_agent_style_rules
              WHERE is_active = 1
              ORDER BY created_at DESC
              LIMIT ' . max(0, $limit)
        );
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    }

    /**
     * Most recent jobs for this agent + job_type that a human approved
     * without edits. Filtering by job_type too (not just agent_key)
     * matters because seo_meta/article_draft/faq jobs share the same
     * underlying seo_agent but produce completely different output
     * shapes — mixing them would hand the model a few-shot example with
     * the wrong schema. "Best" here is recency among approved-as-is
     * jobs, not a quality score — the architecture doc flags moving to
     * embedding-based retrieval as a later refinement once the corpus is
     * large.
     *
     * @return array<int, array{input_brief: string, output_json: string}>
     */
    private function approvedExamples(string $agentKey, string $jobType, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.input_brief, j.output_json
               FROM growth_agent_jobs j
               INNER JOIN growth_agent_feedback f ON f.job_id = j.id
              WHERE j.agent_key = :agent_key
                AND j.job_type = :job_type
                AND j.status = :status
                AND f.action = :action
                AND j.output_json IS NOT NULL
              ORDER BY f.created_at DESC
              LIMIT ' . max(0, $limit)
        );
        $stmt->execute([
            'agent_key' => $agentKey,
            'job_type'  => $jobType,
            'status'    => 'succeeded',
            'action'    => 'approved_as_is',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'input_brief' => (string) $row['input_brief'],
            'output_json' => (string) $row['output_json'],
        ], $rows);
    }

    /**
     * Admin-approved winning_pattern/content_gap insights
     * (growth_agent_memory.status = 'active' only — pending_review and
     * archived entries never reach the model). No relevance filtering
     * against the specific query being generated for — same "everything
     * active gets included" approach as activeStyleRules() above; scoped
     * to embedding-based retrieval as a future refinement once the
     * corpus is large, matching the note already on approvedExamples().
     *
     * @return string[] one formatted line per entry, e.g.
     *   '[winning_pattern] Query "..." konsisten performa bagus (avg CTR 4.2%, 6 minggu, 3400 impressions)'
     */
    private function activeMemoryEntries(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT insight_type, title, supporting_data_json
               FROM growth_agent_memory
              WHERE status = 'active'
              ORDER BY last_confirmed_at DESC
              LIMIT " . max(0, $limit)
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lines = [];
        foreach ($rows as $row) {
            $metrics = json_decode((string) ($row['supporting_data_json'] ?? ''), true);
            $metrics = is_array($metrics) ? $metrics : [];

            $bits = [];
            if (isset($metrics['total_impressions'])) {
                $bits[] = $metrics['total_impressions'] . ' impressions';
            }
            if (isset($metrics['distinct_weeks'])) {
                $bits[] = $metrics['distinct_weeks'] . ' minggu';
            }
            if (isset($metrics['avg_ctr'])) {
                $bits[] = 'avg CTR ' . round(((float) $metrics['avg_ctr']) * 100, 2) . '%';
            }
            if (isset($metrics['avg_position'])) {
                $bits[] = 'posisi ' . $metrics['avg_position'];
            }

            $summary = $bits !== [] ? ' (' . implode(', ', $bits) . ')' : '';
            $lines[] = '[' . (string) $row['insight_type'] . '] ' . (string) $row['title'] . $summary;
        }

        return $lines;
    }
}
