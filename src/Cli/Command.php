<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Cli;

use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Counters;
use IndianOpinion\TldrBlock\Eligibility;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\State;
use IndianOpinion\TldrBlock\Text\Fingerprint;

/**
 * Generate, review state and run summary jobs. Nothing here approves.
 */
final class Command
{
    /**
     * Queue eligible published posts that have no summary yet.
     *
     * ## OPTIONS
     *
     * [--category=<slug>]
     * : Only posts in this category.
     *
     * [--post_type=<type>]
     * : Post type. Default: the first allowed type.
     *
     * [--limit=<n>]
     * : Most posts to queue. Default 50.
     *
     * [--spread]
     * : Schedule what does not fit today's remaining cap on following days.
     *
     * [--dry-run]
     * : Print what would be queued and the estimated number of days.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : 50;
        $type = isset($assoc['post_type']) ? (string) $assoc['post_type'] : (Settings::get()['post_types'][0] ?? 'post');
        $dry = !empty($assoc['dry-run']);
        $spread = !empty($assoc['spread']);
        $found = [];
        $page = 1;
        while (count($found) < $limit) {
            $q = [
                'post_type' => $type,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 500,
                'paged' => $page,
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
            ];
            if (isset($assoc['category'])) {
                $q['category_name'] = sanitize_title((string) $assoc['category']);
            }
            $ids = get_posts($q);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $id = (int) $id;
                if (PostState::live($id) !== null || PostState::candidate($id) !== null || Queue::isQueued($id)) {
                    continue;
                }
                if (Eligibility::reason($id) !== '') {
                    continue;
                }
                $found[] = $id;
                if (count($found) >= $limit) {
                    break;
                }
            }
            if (count($ids) < 500) {
                break;
            }
            $page++;
        }

        $remaining = Counters::remaining();
        $cap = max(1, Settings::dailyCap());
        $today = array_slice($found, 0, $remaining);
        $later = array_slice($found, $remaining);
        $days = ($today === [] ? 0 : 1) + (int) ceil(count($later) / $cap);

        if ($dry) {
            foreach ($found as $id) {
                \WP_CLI::line(sprintf('%d %s', $id, get_the_title($id)));
            }
            \WP_CLI::success(sprintf('%d posts would be queued; %d fit today; estimated %d day(s).', count($found), count($today), $spread ? $days : 1));
            return;
        }

        $now = Clock::now();
        foreach ($today as $i => $id) {
            self::queueOne($id, $now + $i * 5);
        }
        $scheduledLater = 0;
        if ($spread) {
            foreach ($later as $i => $id) {
                $day = intdiv($i, $cap);
                $at = Clock::nextMidnight($now + $day * 86400) + 300 + ($i % $cap) * 5;
                self::queueOne($id, $at);
                $scheduledLater++;
            }
        }
        $skipped = count($later) - $scheduledLater;
        \WP_CLI::success(sprintf('Queued %d today, %d on later days, %d left out by the cap.', count($today), $scheduledLater, $skipped));
    }

    /**
     * Today's counters, or one post's full state.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : A post id.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        if (isset($args[0])) {
            $id = absint($args[0]);
            if (!get_post($id) instanceof \WP_Post) {
                \WP_CLI::error('No such post.');
            }
            \WP_CLI::line((string) wp_json_encode(State::of($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        $c = Counters::today();
        \WP_CLI::line(sprintf('day: %s', $c['day']));
        \WP_CLI::line(sprintf('generations: %d / %d', $c['count'], Settings::dailyCap()));
        \WP_CLI::line('cost: ' . ($c['cost_seen'] ? (string) round($c['cost'], 6) : 'not reported'));
        \WP_CLI::line('kill switch: ' . (Settings::killSwitchOn() ? 'on' : 'off'));
        $byStatus = [];
        foreach (PostState::STATUSES as $s) {
            $n = count(get_posts([
                'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
                'no_found_rows' => true, 'meta_key' => PostState::STATUS, 'meta_value' => $s,
            ]));
            if ($n > 0) {
                $byStatus[] = $s . '=' . $n;
            }
        }
        \WP_CLI::line('queue length: ' . count(self::queuedIds()));
        \WP_CLI::line('by status: ' . ($byStatus === [] ? 'none' : implode(' ', $byStatus)));
    }

    /**
     * Queue one post now.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post.
     *
     * [--force]
     * : Ignore the word minimum.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        $id = absint($args[0] ?? 0);
        $r = Queue::manual($id, ['manual' => true, 'force' => !empty($assoc['force'])]);
        if ($r instanceof \WP_Error) {
            \WP_CLI::error($r->get_error_code() . ': ' . $r->get_error_message());
        }
        \WP_CLI::success(sprintf('Queued post %d.', $id));
    }

    /**
     * Run due jobs now (for a system cron).
     *
     * ## OPTIONS
     *
     * [--max=<n>]
     * : Most jobs to run. Default 3.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $max = isset($assoc['max']) ? max(1, (int) $assoc['max']) : 3;
        $ran = 0;
        foreach (self::queuedIds() as $id) {
            if ($ran >= $max) {
                break;
            }
            $job = PostState::job($id);
            if ((int) ($job['next_run'] ?? PHP_INT_MAX) > Clock::now()) {
                continue;
            }
            wp_clear_scheduled_hook(Queue::EVENT, [$id]);
            $outcome = Generator::run($id);
            \WP_CLI::line(sprintf('%d %s', $id, $outcome));
            $ran++;
        }
        \WP_CLI::success(sprintf('Ran %d job(s).', $ran));
    }

    /**
     * Turn the kill switch on.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function pause(array $args = [], array $assoc = []): void
    {
        Settings::update(['generation' => 'off']);
        \WP_CLI::success('Generation paused.');
    }

    /**
     * Turn the kill switch off.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function resume(array $args = [], array $assoc = []): void
    {
        Settings::update(['generation' => 'on']);
        if (Settings::killSwitchLocked()) {
            \WP_CLI::warning('TLDR_BLOCK_DISABLED is set, so generation stays off.');
            return;
        }
        \WP_CLI::success('Generation resumed.');
    }

    /** @return list<int> */
    private static function queuedIds(): array
    {
        $ids = get_posts([
            'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 500,
            'no_found_rows' => true, 'meta_key' => PostState::STATUS, 'meta_value' => 'queued',
        ]);

        return array_values(array_map('intval', $ids));
    }

    private static function queueOne(int $id, int $at): void
    {
        $post = get_post($id);
        if ($post instanceof \WP_Post) {
            PostState::setBasis($id, Fingerprint::of(Eligibility::sourceText($post)));
        }
        PostState::patchJob($id, ['attempts' => 0, 'changed_at' => Clock::now(), 'manual' => false, 'force' => false]);
        Queue::schedule($id, $at, 'backfill');
    }
}
