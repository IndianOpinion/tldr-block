<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Time source. Tests freeze it; production reads the system clock.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    /** Local day (site time zone) of a timestamp, as Y-m-d. */
    public static function day(?int $timestamp = null): string
    {
        return self::local($timestamp ?? self::now())->format('Y-m-d');
    }

    /** Timestamp of the next local midnight after the given time. */
    public static function nextMidnight(?int $timestamp = null): int
    {
        $local = self::local($timestamp ?? self::now());

        return $local->setTime(0, 0)->modify('+1 day')->getTimestamp();
    }

    private static function local(int $timestamp): \DateTimeImmutable
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
    }
}
