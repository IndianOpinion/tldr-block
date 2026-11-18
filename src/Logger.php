<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * One line per event to the PHP error log with the prefix "tldr-block:",
 * and the tldr_block_log action. Repeating error lines are throttled to one
 * per ten minutes per kind. Lines name post ids, never post text.
 */
final class Logger
{
    public const THROTTLE_SECONDS = 600;
    public const ERRORS_OPTION = 'tldr_block_errors';

    /** @var null|callable(string): void */
    private static $sink = null;

    /** Replace the error_log sink (tests). */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = [], ?string $throttleKind = null): void
    {
        if ($level === 'debug' && !Settings::get()['debug']) {
            return;
        }
        do_action('tldr_block_log', $level, $message, $context);

        if ($level === 'error' || $level === 'warning') {
            self::remember($message, $context);
        }
        if ($throttleKind !== null) {
            $key = 'tldr_block_throttle_' . substr(md5($throttleKind), 0, 12);
            if (get_transient($key) !== false) {
                return;
            }
            set_transient($key, 1, self::THROTTLE_SECONDS);
        }

        $pairs = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $pairs[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        $line = 'tldr-block: [' . $level . '] ' . $message . ($pairs === [] ? '' : ' ' . implode(' ', $pairs));
        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }
        error_log($line);
    }

    /**
     * Last five errors for the settings screen and Site Health.
     *
     * @return list<array{time: int, message: string, post_id: int}>
     */
    public static function recentErrors(): array
    {
        $stored = get_option(self::ERRORS_OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $row) {
            if (is_array($row) && isset($row['time'], $row['message'])) {
                $out[] = [
                    'time' => (int) $row['time'],
                    'message' => (string) $row['message'],
                    'post_id' => (int) ($row['post_id'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function remember(string $message, array $context): void
    {
        $rows = self::recentErrors();
        array_unshift($rows, [
            'time' => Clock::now(),
            'message' => $message,
            'post_id' => isset($context['post_id']) && is_numeric($context['post_id']) ? (int) $context['post_id'] : 0,
        ]);
        update_option(self::ERRORS_OPTION, array_slice($rows, 0, 5), false);
    }
}
