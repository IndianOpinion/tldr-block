<?php

/**
 * Theme template snippet: print the approved summary in a custom place.
 * Set Placement to "None" in Settings, Summaries, then call this inside
 * the loop of single.php.
 */

declare(strict_types=1);

if (function_exists('tldr_block_the_summary')) {
    tldr_block_the_summary(get_the_ID() ?: null, ['heading' => 'In short', 'heading_tag' => 'h2']);
}

// Or read the data and build your own markup:
$summary = function_exists('tldr_block_get') ? tldr_block_get((int) get_the_ID()) : null;
if ($summary !== null && $summary->format() === 'bullets') {
    echo '<ol class="my-summary">';
    foreach ($summary->points() as $point) {
        echo '<li>' . esc_html($point) . '</li>';
    }
    echo '</ol>';
}
