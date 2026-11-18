<?php

/**
 * Public template and integration functions (PRD section 5.1).
 */

declare(strict_types=1);

use IndianOpinion\TldrBlock\Eligibility;
use IndianOpinion\TldrBlock\Modes;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Renderer;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\State;
use IndianOpinion\TldrBlock\Summary;

if (!function_exists('tldr_block_get')) {
    /** The displayable summary for the current user, or null. */
    function tldr_block_get(int $post_id): ?Summary
    {
        return Eligibility::displayable($post_id);
    }

    /**
     * Escaped HTML, or ''. Args: heading, show_heading, heading_tag, context.
     *
     * @param array{heading?: string, show_heading?: bool, heading_tag?: string, context?: string} $args
     */
    function tldr_block_render(?int $post_id = null, array $args = []): string
    {
        return Renderer::render($post_id, $args + ['context' => 'template']);
    }

    /**
     * Echo the summary markup.
     *
     * @param array{heading?: string, show_heading?: bool, heading_tag?: string, context?: string} $args
     */
    function tldr_block_the_summary(?int $post_id = null, array $args = []): void
    {
        echo tldr_block_render($post_id, $args); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the renderer.
    }

    /**
     * Full state. Callers must check capability before showing it.
     *
     * @return array<string, mixed>
     */
    function tldr_block_state(int $post_id): array
    {
        return State::of($post_id);
    }

    /**
     * Queue a generation. Args: manual (bool), force (bool).
     *
     * @param array{manual?: bool, force?: bool} $args
     * @return true|\WP_Error
     */
    function tldr_block_queue(int $post_id, array $args = []): bool|\WP_Error
    {
        return Queue::manual($post_id, $args);
    }

    /** Resolved mode, `on` or `off`. */
    function tldr_block_mode_for(int $post_id): string
    {
        return Modes::forPost($post_id);
    }

    /** False when the kill switch is on. Fresh read. */
    function tldr_block_is_enabled(): bool
    {
        return !Settings::killSwitchOn();
    }
}
