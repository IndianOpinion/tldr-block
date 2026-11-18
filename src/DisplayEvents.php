<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Tells page caches that what readers see has changed (PRD case 58).
 */
final class DisplayEvents
{
    public const REASONS = ['approved', 'withdrawn', 'restored', 'stale', 'category_switch', 'post_switch'];

    public static function changed(int $id, string $reason): void
    {
        if ($reason !== 'category_switch') {
            clean_post_cache($id);
        }
        do_action('tldr_block_display_changed', $id, $reason);
    }
}
