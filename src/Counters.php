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
        if (!self::lock()) {
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
            self::unlock();
        }
    }

    /** Give a unit back. Only used when no call was made at all. */
    public static function refund(): void
    {
        if (!self::lock()) {
            return;
        }
        try {
            $c = self::today();
            $c['count'] = max(0, $c['count'] - 1);
            self::write($c);
        } finally {
            self::unlock();
        }
    }

    /** Add a reported cost. Null means the provider did not report one. */
    public static function addCost(?float $cost): void
    {
        if ($cost === null) {
            return;
        }
        if (!self::lock()) {
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
            self::unlock();
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
        }
        $v = get_option(self::OPTION, []);

        return is_array($v) ? $v : [];
    }

    /**
     * Take the lock: an insert that fails when the row exists. An expired
     * lock is taken over once.
     */
    private static function lock(): bool
    {
        $until = Clock::now() + self::LOCK_TTL;
        if (add_option(self::LOCK, $until, '', false)) {
            return true;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::LOCK, 'options');
        }
        $held = get_option(self::LOCK, 0);
        if (is_numeric($held) && (int) $held < Clock::now()) {
            delete_option(self::LOCK);

            return add_option(self::LOCK, $until, '', false);
        }

        return false;
    }

    private static function unlock(): void
    {
        delete_option(self::LOCK);
    }
}
