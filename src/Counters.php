<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Daily generation count and cost, guarded by a lock row in the options
 * table (PRD cases 34, 35). The object cache is never trusted for this.
 */
final class Counters
{
    public const OPTION = 'tldr_block_counters';
    public const LOCK = 'tldr_block_lock';
    public const HOLD = 'tldr_block_hold_until';
    private const LOCK_TTL = 30;

    /**
     * @return array{day: string, count: int, cost: float, cost_seen: bool, cap_reached_sent: list<string>}
     */
    public static function today(): array
    {
        $raw = self::readOption();
        $day = Clock::day();
        if (($raw['day'] ?? '') !== $day) {
            return ['day' => $day, 'count' => 0, 'cost' => 0.0, 'cost_seen' => false, 'cap_reached_sent' => []];
        }
        $sent = [];
        foreach ((array) ($raw['cap_reached_sent'] ?? []) as $k) {
            if (is_string($k)) {
                $sent[] = $k;
            }
        }

        return [
            'day' => $day,
            'count' => (int) ($raw['count'] ?? 0),
            'cost' => (float) ($raw['cost'] ?? 0.0),
            'cost_seen' => (bool) ($raw['cost_seen'] ?? false),
            'cap_reached_sent' => $sent,
        ];
    }

    /**
     * Reserve one generation unit. Returns '' on success, or the refusal
     * reason: `daily_cap`, `cost_cap` or `lock`.
     */
    public static function reserve(): string
    {
        if (!self::acquire()) {
            return 'lock';
        }
        try {
            $c = self::today();
            $cap = Settings::dailyCap();
            $costCap = Settings::get()['cost_cap'];
            if ($costCap !== null && $c['cost_seen'] && $c['cost'] >= $costCap) {
                self::capReached($c, 'cost');
                self::write($c);
                return 'cost_cap';
            }
            if ($c['count'] >= $cap) {
                self::capReached($c, 'count');
                self::write($c);
                return 'daily_cap';
            }
            $c['count']++;
            if ($c['count'] >= $cap) {
                self::capReached($c, 'count');
            }
            self::write($c);

            return '';
        } finally {
            self::release();
        }
    }

    /** Give a unit back. Only used when no call was made at all. */
    public static function refund(): void
    {
        if (!self::acquire()) {
            return;
        }
        try {
            $c = self::today();
            $c['count'] = max(0, $c['count'] - 1);
            self::write($c);
        } finally {
            self::release();
        }
    }

    /** Add a reported cost. Null means the provider did not report one. */
    public static function addCost(?float $cost): void
    {
        if ($cost === null) {
            return;
        }
        if (!self::acquire()) {
            Logger::log('warning', 'cost not recorded: lock busy', [], 'lock');
            return;
        }
        try {
            $c = self::today();
            $c['cost'] += max(0.0, $cost);
            $c['cost_seen'] = true;
            $costCap = Settings::get()['cost_cap'];
            if ($costCap !== null && $c['cost'] >= $costCap) {
                self::capReached($c, 'cost');
            }
            self::write($c);
        } finally {
            self::release();
        }
    }

    public static function remaining(): int
    {
        return max(0, Settings::dailyCap() - self::today()['count']);
    }

    public static function holdUntil(): int
    {
        $v = get_option(self::HOLD, 0);

        return is_numeric($v) ? (int) $v : 0;
    }

    public static function setHold(int $until): void
    {
        update_option(self::HOLD, $until, false);
    }

    public static function held(): bool
    {
        return self::holdUntil() > Clock::now();
    }

    /**
     * @param array{day: string, count: int, cost: float, cost_seen: bool, cap_reached_sent: list<string>} $c
     */
    private static function capReached(array &$c, string $kind): void
    {
        if (in_array($kind, $c['cap_reached_sent'], true)) {
            return;
        }
        $c['cap_reached_sent'][] = $kind;
        Logger::log('warning', 'daily cap reached', ['kind' => $kind, 'day' => $c['day']]);
        do_action('tldr_block_cap_reached', $kind, $c['day']);
    }

    /**
     * @param array{day: string, count: int, cost: float, cost_seen: bool, cap_reached_sent: list<string>} $c
     */
    private static function write(array $c): void
    {
        update_option(self::OPTION, $c, false);
    }

    /** @return array<string, mixed> */
    private static function readOption(): array
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $v = get_option(self::OPTION, []);

        return is_array($v) ? $v : [];
    }

    /**
     * Take the lock row. With a real database this is one `INSERT IGNORE`
     * on the options table's unique key, so exactly one process wins; an
     * expired lock is taken over by one conditional `UPDATE`. add_option()
     * is not used: it checks and then upserts, so two processes could both
     * believe they hold the lock.
     */
    public static function acquire(): bool
    {
        $now = Clock::now();
        $until = $now + self::LOCK_TTL;
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $inserted = self::exec($wpdb, $wpdb->prepare(
                "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $wpdb->options,
                self::LOCK,
                (string) $until
            ));
            if ($inserted === 1) {
                self::forget(self::LOCK);
                return true;
            }
            $taken = self::exec($wpdb, $wpdb->prepare(
                'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
                $wpdb->options,
                (string) $until,
                self::LOCK,
                $now
            ));
            self::forget(self::LOCK);

            return $taken === 1;
        }
        // No database layer (unit tests without wpdb): best effort.
        if (add_option(self::LOCK, $until, '', false)) {
            return true;
        }
        $held = get_option(self::LOCK, 0);
        if (is_numeric($held) && (int) $held < $now) {
            update_option(self::LOCK, $until, false);
            return true;
        }

        return false;
    }

    public static function release(): void
    {
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            self::exec($wpdb, $wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK));
            self::forget(self::LOCK);
            return;
        }
        delete_option(self::LOCK);
    }

    /** Rows affected, or -1 when the statement could not be built or run. */
    private static function exec(\wpdb $wpdb, ?string $sql): int
    {
        if ($sql === null || $sql === '') {
            return -1;
        }
        $r = $wpdb->query($sql);

        return is_int($r) ? $r : -1;
    }

    private static function forget(string $option): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($option, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }
}
