<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Settings;

/**
 * Posts list column and status filter (PRD case 55).
 */
final class PostsColumn
{
    public const COLUMN = 'tldr_block';
    public const QUERY_VAR = 'tldr_block_status';

    public static function register(): void
    {
        foreach (Settings::get()['post_types'] as $type) {
            add_filter('manage_' . $type . '_posts_columns', [self::class, 'columns']);
            add_action('manage_' . $type . '_posts_custom_column', [self::class, 'cell'], 10, 2);
        }
        add_action('restrict_manage_posts', [self::class, 'dropdown']);
        add_action('pre_get_posts', [self::class, 'filterQuery']);
    }

    public static function label(string $status): string
    {
        $labels = [
            'none' => __('None', 'tldr-block'),
            'queued' => __('Queued', 'tldr-block'),
            'generating' => __('Generating', 'tldr-block'),
            'pending' => __('Pending review', 'tldr-block'),
            'approved' => __('Approved', 'tldr-block'),
            'rejected' => __('Rejected', 'tldr-block'),
            'failed' => __('Failed', 'tldr-block'),
            'deferred' => __('Deferred', 'tldr-block'),
            'withdrawn' => __('Withdrawn', 'tldr-block'),
            'short' => __('Too short', 'tldr-block'),
            'stale' => __('Stale', 'tldr-block'),
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $columns[self::COLUMN] = __('Summary', 'tldr-block');

        return $columns;
    }

    public static function cell(string $column, int $postId): void
    {
        if ($column === self::COLUMN) {
            echo esc_html(self::cellText($postId));
        }
    }

    public static function cellText(int $postId): string
    {
        $text = self::label(PostState::status($postId));
        $live = PostState::live($postId);
        if ($live !== null && !empty($live['stale'])) {
            $text .= ', ' . self::label('stale');
        }

        return $text;
    }

    public static function dropdown(string $postType = 'post'): void
    {
        if (!in_array($postType, Settings::get()['post_types'], true)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $current = isset($_GET[self::QUERY_VAR]) ? sanitize_key(wp_unslash((string) $_GET[self::QUERY_VAR])) : '';
        echo '<select name="' . esc_attr(self::QUERY_VAR) . '"><option value="">' . esc_html__('All summary states', 'tldr-block') . '</option>';
        foreach (PostState::STATUSES as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($current, $s, false) . '>' . esc_html(self::label($s)) . '</option>';
        }
        echo '</select>';
    }

    public static function filterQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $status = isset($_GET[self::QUERY_VAR]) ? sanitize_key(wp_unslash((string) $_GET[self::QUERY_VAR])) : '';
        $meta = self::metaQuery($status);
        if ($meta !== null) {
            $query->set('meta_query', [$meta]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function metaQuery(string $status): ?array
    {
        if (!in_array($status, PostState::STATUSES, true)) {
            return null;
        }
        if ($status === 'none') {
            return ['key' => PostState::STATUS, 'compare' => 'NOT EXISTS'];
        }

        return ['key' => PostState::STATUS, 'value' => $status];
    }
}
