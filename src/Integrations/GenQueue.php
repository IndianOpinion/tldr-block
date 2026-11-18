<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

/**
 * Soft integration with gen-queue (PRD case 25). Used only when its
 * enqueue filter answers with an integer job id. Hook names live here so
 * they can be aligned with gen-queue's released interface in one place.
 */
final class GenQueue
{
    public const ENQUEUE_FILTER = 'gen_queue_enqueue_job';
    public const CANCEL_FILTER = 'gen_queue_cancel_job';
    public const HANDLERS_FILTER = 'gen_queue_handlers';
    public const JOB_TYPE = 'tldr_block.generate';
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
        $id = apply_filters(self::ENQUEUE_FILTER, null, [
            'type' => self::JOB_TYPE,
            'payload' => ['post_id' => $postId],
            'idempotency_key' => 'post:' . $postId,
            'on_conflict' => 'replace',
            'run_after' => $runAfter,
            'group' => self::GROUP,
        ]);

        return is_int($id) ? $id : null;
    }

    public static function cancel(int $postId): void
    {
        if (self::present()) {
            apply_filters(self::CANCEL_FILTER, false, 'post:' . $postId, self::GROUP);
        }
    }

    /**
     * @param array<string, callable> $handlers
     * @return array<string, callable>
     */
    public static function registerHandler(array $handlers): array
    {
        $handlers[self::JOB_TYPE] = static function (array $payload): void {
            $postId = (int) ($payload['post_id'] ?? 0);
            if ($postId > 0) {
                \IndianOpinion\TldrBlock\Generator::run($postId);
            }
        };

        return $handlers;
    }
}
