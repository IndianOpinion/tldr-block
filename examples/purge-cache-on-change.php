<?php

/**
 * Plugin Name: tldr-block cache purge example
 * Description: Purges a page cache when what readers see changes. Replace
 * my_purge_url() with your cache's own purge call.
 */

declare(strict_types=1);

add_action('tldr_block_display_changed', static function (int $id, string $reason): void {
    if ($reason === 'category_switch') {
        $link = get_term_link($id, 'category');
        if (is_string($link) && function_exists('my_purge_url')) {
            my_purge_url($link);
        }
        return;
    }
    $link = get_permalink($id);
    if (is_string($link) && function_exists('my_purge_url')) {
        my_purge_url($link);
    }
}, 10, 2);
