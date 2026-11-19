<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Text\SourceText;
use IndianOpinion\TldrBlock\Text\WordCounter;

/**
 * Eligibility for generation (PRD case 1) and displayability (case 50).
 */
final class Eligibility
{
    /** Source text after the plugin's filter (case 10). */
    public static function sourceText(\WP_Post $post): string
    {
        $text = apply_filters('tldr_block_source_text', SourceText::fromContent((string) $post->post_content), $post);

        return is_string($text) ? $text : '';
    }

    public static function wordCount(string $text): int
    {
        $n = apply_filters('tldr_block_word_count', WordCounter::count($text), $text);

        return is_numeric($n) ? max(0, (int) $n) : 0;
    }

    public static function typeAllowed(string $type): bool
    {
        return in_array($type, Settings::get()['post_types'], true);
    }

    /**
     * Why a post is not eligible, or '' when it is. Reasons: type, status,
     * password, private, short, mode_off, kill_switch, no_provider, missing.
     *
     * @param array{manual?: bool, force?: bool, words?: int} $opts
     */
    public static function reason(int $postId, array $opts = []): string
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 'missing';
        }
        $manual = (bool) ($opts['manual'] ?? false);
        $force = (bool) ($opts['force'] ?? false);
        $settings = Settings::get();

        $reason = '';
        if (!self::typeAllowed($post->post_type)) {
            $reason = 'type';
        } elseif ((string) $post->post_password !== '') {
            $reason = 'password';
        } elseif ($post->post_status === 'private' && !apply_filters('tldr_block_allow_private', false, $postId)) {
            $reason = 'private';
        } elseif ($post->post_status !== 'private' && !self::statusAllowed($post->post_status, $manual, $settings['statuses'])) {
            $reason = 'status';
        } elseif (Modes::forPost($postId) !== 'on') {
            $reason = 'mode_off';
        } elseif (!$force) {
            $words = $opts['words'] ?? self::wordCount(self::sourceText($post));
            if ($words < Modes::minWords($postId)) {
                $reason = 'short';
            }
        }

        $eligible = apply_filters('tldr_block_is_eligible', $reason === '', $postId, $reason);
        if ($eligible !== true) {
            $reason = $reason === '' ? 'filtered' : $reason;
        } else {
            $reason = '';
        }
        // The filter cannot override these.
        if (Settings::killSwitchOn()) {
            return 'kill_switch';
        }
        if (!Settings::providerConfigured()) {
            return 'no_provider';
        }

        return $reason;
    }

    /**
     * The displayable summary for the current user, or null (case 50).
     */
    public static function displayable(int $postId): ?Summary
    {
        $settings = Settings::get();
        if ($settings['display'] !== 'on') {
            return null;
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || !self::typeAllowed($post->post_type)) {
            return null;
        }
        $live = PostState::live($postId);
        if ($live === null || !empty($live['withdrawn'])) {
            return null;
        }
        if (!empty($live['stale']) && $settings['stale_display'] !== 'keep') {
            return null;
        }
        $public = $post->post_status === 'publish' && (string) $post->post_password === '';
        if (!$public) {
            if (!current_user_can('read_post', $postId) || post_password_required($post)) {
                return null;
            }
        }
        if (Modes::forPost($postId) !== 'on') {
            return null;
        }
        $job = PostState::job($postId);
        $words = isset($job['words']) && is_numeric($job['words'])
            ? (int) $job['words']
            : self::wordCount(self::sourceText($post));
        if ($words < Modes::minWords($postId)) {
            return null;
        }

        return Summary::fromLive($live);
    }

    /**
     * @param list<string> $allowed
     */
    private static function statusAllowed(string $status, bool $manual, array $allowed): bool
    {
        if ($manual) {
            return in_array($status, ['publish', 'future', 'pending', 'draft'], true);
        }

        return in_array($status, $allowed, true);
    }
}
