<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

use IndianOpinion\TldrBlock\Logger;

/**
 * Soft integration with spend-meter (PRD case 38), contract version 1 as
 * published in spend-meter 0.1.0:
 *
 * - `apply_filters('spend_meter_pre_spend', null, array $intent)` returns
 *   null when no meter is present, else a decision array;
 * - `allowed` must be exactly true; any other value or reason is a deny;
 * - a reservation id is followed by exactly one `spend_meter_record` (the
 *   call happened) or `spend_meter_release` (no call was made).
 *
 * Present only when SPEND_METER_API equals 1; any other version, or an
 * unusable answer, is treated as absent and the plugin's own caps apply.
 */
final class SpendMeter
{
    public const CONTRACT = 1;
    public const PRE_SPEND_FILTER = 'spend_meter_pre_spend';
    public const RECORD_ACTION = 'spend_meter_record';
    public const RELEASE_ACTION = 'spend_meter_release';
    public const PURPOSE = 'tldr-block.summary';
    public const CALLER = 'tldr-block';

    public static function present(): bool
    {
        return defined('SPEND_METER_API') && constant('SPEND_METER_API') === self::CONTRACT;
    }

    /**
     * Ask the meter. Returns null when the meter is absent or answered
     * with something unusable (treated as absent), else the decision.
     *
     * @return array{allowed: bool, reason: string, reservation_id: ?string}|null
     */
    public static function preSpend(string $provider, string $model, int $estInputTokens = 0, int $maxOutputTokens = 0): ?array
    {
        if (!self::present()) {
            return null;
        }
        $intent = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::slug($provider),
            'model' => self::model($model),
        ];
        if ($estInputTokens > 0) {
            $intent['est_input_tokens'] = $estInputTokens;
        }
        if ($maxOutputTokens > 0) {
            $intent['max_output_tokens'] = $maxOutputTokens;
        }
        try {
            $d = apply_filters(self::PRE_SPEND_FILTER, null, $intent);
        } catch (\Throwable $e) {
            Logger::log('warning', 'spend-meter error, treated as absent', ['error' => $e->getMessage()], 'spend_meter');

            return null;
        }
        if ($d === null) {
            return null;
        }
        if (!is_array($d) || !array_key_exists('allowed', $d)) {
            Logger::log('warning', 'spend-meter answered with an unusable decision, treated as absent', [], 'spend_meter');

            return null;
        }
        $rid = $d['reservation_id'] ?? null;

        return [
            'allowed' => $d['allowed'] === true,
            'reason' => is_string($d['reason'] ?? null) && $d['reason'] !== '' ? $d['reason'] : 'spend_meter',
            'reservation_id' => is_string($rid) && $rid !== '' ? $rid : null,
        ];
    }

    /**
     * Record one call (the call happened). `status` is `ok`,
     * `error_billed` or `error_unbilled`; `cost` is in the provider's
     * currency and is sent as integer nanos.
     *
     * @param array{status: string, provider: string, model: string, input_tokens?: ?int, output_tokens?: ?int, cost?: ?float, latency_ms?: int, error_code?: string, http_status?: int} $call
     */
    public static function record(?string $reservationId, array $call): void
    {
        $row = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::slug($call['provider']),
            'model' => self::model($call['model']),
            'status' => in_array($call['status'], ['ok', 'error_billed', 'error_unbilled'], true) ? $call['status'] : 'error_unbilled',
            'input_tokens' => max(0, (int) ($call['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($call['output_tokens'] ?? 0)),
        ];
        if ($reservationId !== null) {
            $row['reservation_id'] = $reservationId;
        }
        if (isset($call['cost'])) {
            $row['provider_cost'] = (int) round(max(0.0, $call['cost']) * 1_000_000_000);
        }
        if (isset($call['latency_ms']) && $call['latency_ms'] > 0) {
            $row['latency_ms'] = $call['latency_ms'];
        }
        if (isset($call['error_code']) && $call['error_code'] !== '') {
            $row['error_code'] = substr($call['error_code'], 0, 64);
        }
        if (isset($call['http_status'])) {
            $row['http_status'] = $call['http_status'];
        }
        do_action(self::RECORD_ACTION, $row);
    }

    public static function release(string $reservationId): void
    {
        do_action(self::RELEASE_ACTION, $reservationId);
    }

    /** A provider name made to fit the meter's slug pattern. */
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9_.-]+/', '-', $s);
        $s = ltrim($s, '_.-');

        return $s === '' ? 'unknown' : substr($s, 0, 64);
    }

    private static function model(string $model): string
    {
        $model = trim($model);

        return $model === '' ? 'unspecified' : substr($model, 0, 128);
    }
}
