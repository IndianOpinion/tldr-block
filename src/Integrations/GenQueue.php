<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\PostState;

/**
 * Soft integration with gen-queue (PRD case 25), contract as published in
 * gen-queue 0.1.0:
 *
 * - soft enqueue: `apply_filters('gen_queue_enqueue_job', null, $type,
 *   $payload, $args)` returns a job id, null when gen-queue is absent, or a
 *   WP_Error when the enqueue failed;
 * - the handler is registered on `gen_queue_register_types` with
 *   `gen_queue_register_type($type, $handler, $args)` and receives the
 *   gen-queue Job and JobContext;
 * - `gen_queue_cancel($id)` cancels a queued job.
 *
 * gen-queue's idempotency returns an existing queued job unchanged, so a
 * debounced reschedule cannot move its run time. Each schedule therefore
 * gets its own key (`post:<id>:<run_after>`) and token; the earlier job is
 * cancelled, and a stale job that still runs finds a token that no longer
 * matches and does nothing.
 */
final class GenQueue
{
    public const ENQUEUE_FILTER = 'gen_queue_enqueue_job';
    public const REGISTER_ACTION = 'gen_queue_register_types';
    public const JOB_TYPE = 'tldr-block.generate';
    public const GROUP = 'tldr-block';

    public static function present(): bool
    {
        return has_filter(self::ENQUEUE_FILTER) !== false;
    }

    /**
     * @return int|null the job id, or null when gen-queue did not take it
     */
    public static function enqueue(int $postId, int $runAfter): ?int
    {
        if (!self::present()) {
            return null;
        }
        self::cancel($postId);
        $token = $runAfter . '-' . substr(md5((string) microtime(true) . ':' . $postId), 0, 8);
        try {
            $id = apply_filters(self::ENQUEUE_FILTER, null, self::JOB_TYPE, ['post_id' => $postId, 'token' => $token], [
                'idempotency_key' => 'post:' . $postId . ':' . $token,
                'run_after' => $runAfter,
                'group' => self::GROUP,
                'max_attempts' => 1,
                'source' => 'tldr-block',
            ]);
        } catch (\Throwable $e) {
            $id = null;
            Logger::log('warning', 'gen-queue enqueue failed, using WP-Cron', ['post_id' => $postId, 'error' => $e->getMessage()], 'gen_queue');
        }
        if (is_wp_error($id)) {
            Logger::log('warning', 'gen-queue enqueue failed, using WP-Cron', ['post_id' => $postId, 'error' => $id->get_error_message()], 'gen_queue');
            return null;
        }
        if (!is_int($id)) {
            return null;
        }
        PostState::patchJob($postId, ['gq_id' => $id, 'gq_token' => $token]);

        return $id;
    }

    /** Cancel the post's gen-queue job, if one was enqueued. */
    public static function cancel(int $postId): void
    {
        $job = PostState::job($postId);
        $id = isset($job['gq_id']) && is_numeric($job['gq_id']) ? (int) $job['gq_id'] : 0;
        if ($id <= 0) {
            return;
        }
        PostState::patchJob($postId, ['gq_id' => null, 'gq_token' => null]);
        if (function_exists('gen_queue_cancel')) {
            try {
                gen_queue_cancel($id);
            } catch (\Throwable $e) {
                Logger::log('warning', 'gen-queue cancel failed', ['post_id' => $postId, 'error' => $e->getMessage()], 'gen_queue');
            }
        }
    }

    /** Hooked to gen_queue_register_types. */
    public static function registerType(): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        $desc = \IndianOpinion\TldrBlock\Llm\ClientFactory::describe();
        try {
            gen_queue_register_type(self::JOB_TYPE, [self::class, 'handle'], [
                'group' => self::GROUP,
                'max_attempts' => 1,
                'timeout' => 120,
                'meter' => [
                    'purpose' => SpendMeter::PURPOSE,
                    'provider' => SpendMeter::slug($desc['provider']),
                    'model' => $desc['model'] !== '' ? $desc['model'] : 'unspecified',
                ],
            ]);
        } catch (\Throwable $e) {
            Logger::log('warning', 'gen-queue type registration failed', ['error' => $e->getMessage()], 'gen_queue');
        }
    }

    /**
     * The gen-queue handler. Retries, caps and failures are handled by the
     * plugin's own Generator, so this never throws for a model failure.
     *
     * @return array<string, mixed>
     */
    public static function handle(mixed $job, mixed $ctx = null): array
    {
        $payload = is_object($job) && isset($job->payload) && is_array($job->payload)
            ? $job->payload
            : (is_array($job) ? $job : []);
        $postId = (int) ($payload['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['outcome' => 'skipped'];
        }
        $state = PostState::job($postId);
        $token = (string) ($payload['token'] ?? '');
        if ($token === '' || $token !== (string) ($state['gq_token'] ?? '')) {
            return ['post_id' => $postId, 'outcome' => 'superseded'];
        }
        PostState::patchJob($postId, ['gq_id' => null, 'gq_token' => null]);
        $outcome = Generator::run($postId);
        // Report the spend so gen-queue's own cap sees it. When spend-meter
        // is present the call was already recorded there: `metered` stops
        // gen-queue from recording it a second time.
        $cost = Generator::lastCost();
        if ($cost !== null && $cost > 0 && is_object($ctx) && method_exists($ctx, 'addCost')) {
            try {
                $ctx->addCost($cost, ['metered' => SpendMeter::present()]);
            } catch (\Throwable $e) {
                Logger::log('warning', 'gen-queue cost report failed', ['post_id' => $postId, 'error' => $e->getMessage()], 'gen_queue');
            }
        }

        return ['post_id' => $postId, 'outcome' => $outcome];
    }
}
