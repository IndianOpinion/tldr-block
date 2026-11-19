<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Integrations\GenQueue;
use IndianOpinion\TldrBlock\Text\Fingerprint;

/**
 * Save handling, debounce, scheduling and cancelling (PRD cases 16 to 26,
 * 37).
 */
final class Queue
{
    public const EVENT = 'tldr_block_generate';
    public const SWEEP_EVENT = 'tldr_block_deferred';

    /**
     * Hooked to wp_after_insert_post, after terms and meta are saved.
     */
    public static function onSave(int $postId, ?\WP_Post $post = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }
        if (wp_is_post_autosave($postId) !== false || wp_is_post_revision($postId) !== false) {
            return;
        }
        $post = $post ?? get_post($postId);
        if (!$post instanceof \WP_Post || !Eligibility::typeAllowed($post->post_type)) {
            return;
        }
        $settings = Settings::get();
        $job = PostState::job($postId);

        if (
            $post->post_status === 'trash'
            || (string) $post->post_password !== ''
            || ($post->post_status === 'private' && !apply_filters('tldr_block_allow_private', false, $postId))
        ) {
            self::cancel($postId, $post->post_status === 'trash' ? 'trash' : 'protected');
            return;
        }
        if ($post->post_status !== 'private' && !in_array($post->post_status, $settings['statuses'], true)) {
            if (empty($job['manual'])) {
                self::cancel($postId, 'status');
            }
            return;
        }

        $text = Eligibility::sourceText($post);
        $fp = Fingerprint::of($text);
        $words = Eligibility::wordCount($text);
        PostState::patchJob($postId, ['words' => $words]);

        if ($words < Modes::minWords($postId)) {
            self::cancel($postId, 'short');
            PostState::setStatus($postId, 'short');
            return;
        }
        if (PostState::status($postId) === 'short') {
            PostState::setStatus($postId, self::restingStatus($postId));
        }

        $threshold = Settings::threshold();
        $live = PostState::live($postId);
        $liveFp = PostState::fingerprintOf($live);
        if ($live !== null && empty($live['stale']) && $liveFp !== null && $fp->isMaterialChangeFrom($liveFp, $threshold)) {
            $distance = $fp->distance($liveFp);
            $live['stale'] = true;
            PostState::setLive($postId, $live);
            Logger::log('info', 'stale', ['post_id' => $postId, 'distance' => round($distance, 4)]);
            do_action('tldr_block_stale', $postId, $distance);
            DisplayEvents::changed($postId, 'stale');
        }

        $basis = PostState::basis($postId);
        if ($basis !== null && !$fp->isMaterialChangeFrom($basis, $threshold)) {
            return;
        }
        if (Eligibility::reason($postId, ['words' => $words]) !== '') {
            return;
        }
        PostState::setBasis($postId, $fp);
        PostState::patchJob($postId, ['attempts' => 0, 'changed_at' => Clock::now(), 'manual' => false, 'force' => false]);
        self::schedule($postId, Clock::now() + Settings::debounce(), 'save');
    }

    /**
     * Manual Generate (case 24). Returns true or a WP_Error with one of the
     * codes kill_switch, no_provider, not_eligible, cap_reached, post_limit.
     *
     * @param array{manual?: bool, force?: bool, reason?: string} $args
     * @return true|\WP_Error
     */
    public static function manual(int $postId, array $args = []): bool|\WP_Error
    {
        $force = (bool) ($args['force'] ?? false);
        if ($force && !self::mayForce()) {
            return new \WP_Error('forbidden', __('Only users who manage the settings can force generation.', 'tldr-block'), ['status' => 403]);
        }
        if (Settings::killSwitchOn()) {
            return new \WP_Error('kill_switch', __('Generation is paused by the kill switch.', 'tldr-block'), ['status' => 409]);
        }
        if (!Settings::providerConfigured()) {
            return new \WP_Error('no_provider', __('No model provider is configured.', 'tldr-block'), ['status' => 409]);
        }
        $reason = Eligibility::reason($postId, ['manual' => true, 'force' => $force]);
        if ($reason !== '') {
            return new \WP_Error('not_eligible', sprintf(__('This post is not eligible: %s.', 'tldr-block'), $reason), ['status' => 409, 'reason' => $reason]);
        }
        if (Counters::remaining() <= 0) {
            return new \WP_Error('cap_reached', __('The daily generation cap is reached.', 'tldr-block'), ['status' => 429]);
        }
        $job = PostState::job($postId);
        if (($job['day'] ?? '') === Clock::day() && (int) ($job['day_count'] ?? 0) >= Settings::get()['post_limit']) {
            return new \WP_Error('post_limit', __('This post reached its daily generation limit.', 'tldr-block'), ['status' => 429]);
        }
        $post = get_post($postId);
        if ($post instanceof \WP_Post) {
            PostState::setBasis($postId, Fingerprint::of(Eligibility::sourceText($post)));
        }
        PostState::patchJob($postId, ['attempts' => 0, 'changed_at' => Clock::now(), 'manual' => true, 'force' => $force]);
        self::schedule($postId, Clock::now(), (string) ($args['reason'] ?? 'manual'));

        return true;
    }

    public static function mayForce(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        $cap = apply_filters('tldr_block_settings_capability', 'manage_options');

        return current_user_can(is_string($cap) ? $cap : 'manage_options');
    }

    /**
     * Put one job on the queue backend. One post has at most one job.
     */
    public static function schedule(int $postId, int $runAt, string $reason): void
    {
        if (Counters::held()) {
            $runAt = max($runAt, Counters::holdUntil());
        }
        wp_clear_scheduled_hook(self::EVENT, [$postId]);
        $backend = 'gen-queue';
        if (GenQueue::enqueue($postId, $runAt) === null) {
            $backend = 'wp-cron';
            wp_schedule_single_event($runAt, self::EVENT, [$postId]);
        }
        PostState::patchJob($postId, ['next_run' => $runAt, 'reason' => $reason, 'backend' => $backend]);
        PostState::setStatus($postId, 'queued');
        Logger::log('info', 'queued', ['post_id' => $postId, 'reason' => $reason, 'run_at' => $runAt]);
        do_action('tldr_block_queued', $postId, $reason);
    }

    public static function isQueued(int $postId): bool
    {
        return PostState::status($postId) === 'queued' || wp_next_scheduled(self::EVENT, [$postId]) !== false;
    }

    public static function cancel(int $postId, string $why): void
    {
        $had = wp_next_scheduled(self::EVENT, [$postId]) !== false || PostState::status($postId) === 'queued';
        wp_clear_scheduled_hook(self::EVENT, [$postId]);
        GenQueue::cancel($postId);
        $job = PostState::job($postId);
        if ($job !== []) {
            PostState::patchJob($postId, ['next_run' => null]);
        }
        if (in_array(PostState::status($postId), ['queued', 'deferred'], true)) {
            PostState::setStatus($postId, self::restingStatus($postId));
        }
        if ($had) {
            Logger::log('info', 'job cancelled', ['post_id' => $postId, 'reason' => $why]);
        }
    }

    /** Status to show when nothing is in flight. */
    public static function restingStatus(int $postId): string
    {
        if (PostState::candidate($postId) !== null) {
            return 'pending';
        }
        $live = PostState::live($postId);
        if ($live !== null) {
            return empty($live['withdrawn']) ? 'approved' : 'withdrawn';
        }

        return 'none';
    }

    /** Schedule the next-day retry of deferred jobs (case 37). */
    public static function scheduleSweep(): int
    {
        $next = wp_next_scheduled(self::SWEEP_EVENT);
        if (is_int($next) && $next > Clock::now()) {
            return $next;
        }
        $at = Clock::nextMidnight() + 300 + random_int(0, 1800);
        wp_schedule_single_event($at, self::SWEEP_EVENT);

        return $at;
    }

    /**
     * Re-queue deferred posts, oldest change first.
     *
     * @return list<int> post ids in queue order
     */
    public static function sweep(): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 500,
            'no_found_rows' => true,
            'meta_key' => PostState::STATUS,
            'meta_value' => 'deferred',
        ]);
        $rows = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $job = PostState::job($id);
            $rows[] = [$id, (int) ($job['changed_at'] ?? 0)];
        }
        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $order = [];
        foreach ($rows as $i => [$id]) {
            self::schedule($id, Clock::now() + $i * 5, 'retry');
            $order[] = $id;
        }

        return $order;
    }
}
