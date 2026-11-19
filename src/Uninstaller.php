<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Llm\ClientFactory;

/**
 * Removes everything the plugin created, on each site (PRD case 71).
 * Never calls a provider.
 */
final class Uninstaller
{
    public const BATCH = 500;
    public const OPTIONS = [
        Settings::OPTION, Settings::KEY_OPTION, Counters::OPTION, Counters::LOCK, Counters::HOLD, Logger::ERRORS_OPTION,
    ];

    public static function run(): void
    {
        if (is_multisite()) {
            $offset = 0;
            do {
                $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
                foreach ($sites as $siteId) {
                    switch_to_blog((int) $siteId);
                    self::site();
                    restore_current_blog();
                }
                $offset += 100;
            } while (count($sites) === 100);
            return;
        }
        self::site();
    }

    public static function site(): void
    {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        foreach (PostState::KEYS as $key) {
            do {
                $ids = get_posts([
                    'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => self::BATCH,
                    'no_found_rows' => true, 'meta_key' => $key, 'suppress_filters' => true,
                ]);
                foreach ($ids as $id) {
                    delete_post_meta((int) $id, $key);
                }
            } while (count($ids) === self::BATCH);
        }
        foreach ([Modes::TERM_MODE, Modes::TERM_MIN_WORDS] as $key) {
            delete_metadata('term', 0, $key, '', true);
        }
        wp_unschedule_hook(Queue::EVENT);
        wp_unschedule_hook(Queue::SWEEP_EVENT);

        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            foreach (['tldr_block_throttle_', 'tldr_block_notice_', ClientFactory::STORE_PREFIX] as $prefix) {
                $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
                $likeTimeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
                self::exec($wpdb, $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $like, $likeTimeout));
            }
            if (is_multisite() && isset($wpdb->sitemeta)) {
                $like = $wpdb->esc_like('_site_transient_' . ClientFactory::STORE_PREFIX) . '%';
                $likeTimeout = $wpdb->esc_like('_site_transient_timeout_' . ClientFactory::STORE_PREFIX) . '%';
                self::exec($wpdb, $wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s', $wpdb->sitemeta, $like, $likeTimeout));
            }
        }
    }

    private static function exec(\wpdb $wpdb, mixed $sql): void
    {
        if (is_string($sql) && $sql !== '') {
            $wpdb->query($sql);
        }
    }
}
