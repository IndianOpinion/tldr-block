<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

/**
 * Soft integration with spend-meter (PRD case 38). Present only when
 * SPEND_METER_API equals 1; any other version is treated as absent.
 */
final class SpendMeter
{
    public const CONTRACT = 1;
    public const PRE_SPEND_FILTER = 'spend_meter_pre_spend';
    public const RECORD_ACTION = 'spend_meter_record';
    public const RELEASE_ACTION = 'spend_meter_release';

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
    public static function preSpend(string $provider, string $model): ?array
    {
        if (!self::present()) {
            return null;
        }
        try {
            $d = apply_filters(self::PRE_SPEND_FILTER, null, [
                'purpose' => 'tldr-block.summary',
                'caller' => 'tldr-block',
                'provider' => $provider,
                'model' => $model,
            ]);
        } catch (\Throwable $e) {
            \IndianOpinion\TldrBlock\Logger::log('warning', 'spend-meter error, treated as absent', ['error' => $e->getMessage()], 'spend_meter');

            return null;
        }
        if (!is_array($d) || !array_key_exists('allowed', $d)) {
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
     * @param array<string, mixed> $record
     */
    public static function record(?string $reservationId, array $record): void
    {
        do_action(self::RECORD_ACTION, array_merge($record, [
            'reservation_id' => $reservationId,
            'purpose' => 'tldr-block.summary',
            'caller' => 'tldr-block',
        ]));
    }

    public static function release(string $reservationId): void
    {
        do_action(self::RELEASE_ACTION, $reservationId);
    }
}
