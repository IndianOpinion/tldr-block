<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Text\Fingerprint;

/**
 * Reads and writes the plugin's post meta (PRD section 6.1).
 *
 * @phpstan-type Entry array<string, mixed>
 */
final class PostState
{
    public const STATUS = '_tldr_block_status';
    public const LIVE = '_tldr_block_live';
    public const CANDIDATE = '_tldr_block_candidate';
    public const HISTORY = '_tldr_block_history';
    public const OVERRIDE = '_tldr_block_override';
    public const JOB = '_tldr_block_job';

    public const KEYS = [self::STATUS, self::LIVE, self::CANDIDATE, self::HISTORY, self::OVERRIDE, self::JOB];

    public const STATUSES = ['none', 'queued', 'generating', 'pending', 'approved', 'rejected', 'failed', 'deferred', 'withdrawn', 'short'];

    /** Drop the cached meta of a post so the next read is fresh. */
    public static function refresh(int $postId): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($postId, 'post_meta');
        }
    }

    public static function status(int $postId): string
    {
        $s = get_post_meta($postId, self::STATUS, true);

        return is_string($s) && in_array($s, self::STATUSES, true) ? $s : 'none';
    }

    public static function setStatus(int $postId, string $status): void
    {
        if (in_array($status, self::STATUSES, true)) {
            update_post_meta($postId, self::STATUS, $status);
        }
    }

    /** @return Entry|null */
    public static function live(int $postId): ?array
    {
        return self::arrayMeta($postId, self::LIVE);
    }

    /** @param Entry|null $live */
    public static function setLive(int $postId, ?array $live): void
    {
        self::write($postId, self::LIVE, $live);
    }

    /** @return Entry|null */
    public static function candidate(int $postId): ?array
    {
        return self::arrayMeta($postId, self::CANDIDATE);
    }

    /** @param Entry|null $candidate */
    public static function setCandidate(int $postId, ?array $candidate): void
    {
        self::write($postId, self::CANDIDATE, $candidate);
    }

    /** @return list<Entry> */
    public static function history(int $postId): array
    {
        $h = get_post_meta($postId, self::HISTORY, true);
        if (!is_array($h)) {
            return [];
        }
        $out = [];
        foreach ($h as $row) {
            if (is_array($row) && isset($row['history_id'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Push an entry to the front of the history, capped at the setting.
     *
     * @param Entry $entry
     */
    public static function pushHistory(int $postId, array $entry, string $kind, string $reason = ''): void
    {
        $max = Settings::get()['history'];
        $entry['history_id'] = wp_generate_uuid4();
        $entry['kind'] = $kind;
        $entry['archived_at'] = Clock::now();
        if ($reason !== '') {
            $entry['reason'] = $reason;
        }
        $history = self::history($postId);
        array_unshift($history, $entry);
        $history = array_slice($history, 0, $max);
        if ($history === []) {
            delete_post_meta($postId, self::HISTORY);
            return;
        }
        update_post_meta($postId, self::HISTORY, $history);
    }

    public static function override(int $postId): string
    {
        $o = get_post_meta($postId, self::OVERRIDE, true);

        return in_array($o, ['on', 'off'], true) ? (string) $o : 'auto';
    }

    public static function setOverride(int $postId, string $mode): void
    {
        if ($mode === 'auto') {
            delete_post_meta($postId, self::OVERRIDE);
            return;
        }
        if (in_array($mode, ['on', 'off'], true)) {
            update_post_meta($postId, self::OVERRIDE, $mode);
        }
    }

    /**
     * Job record: attempts, next_run, lease_until, last_error_class,
     * last_error, day, day_count, plus basis_hash, basis_sketch, words,
     * changed_at and reason.
     *
     * @return Entry
     */
    public static function job(int $postId): array
    {
        return self::arrayMeta($postId, self::JOB) ?? [];
    }

    /** @param Entry $job */
    public static function setJob(int $postId, array $job): void
    {
        update_post_meta($postId, self::JOB, $job);
    }

    /** @param Entry $changes */
    public static function patchJob(int $postId, array $changes): void
    {
        self::setJob($postId, array_merge(self::job($postId), $changes));
    }

    /**
     * @param Entry|null $entry
     */
    public static function fingerprintOf(?array $entry): ?Fingerprint
    {
        if ($entry === null || !is_string($entry['source_hash'] ?? null) || $entry['source_hash'] === '') {
            return null;
        }

        return Fingerprint::fromStored($entry['source_hash'], is_string($entry['source_sketch'] ?? null) ? $entry['source_sketch'] : '');
    }

    /**
     * The newest generated-from fingerprint: the queued or last generated
     * source, else the pending candidate's, else the live summary's.
     */
    public static function basis(int $postId): ?Fingerprint
    {
        $job = self::job($postId);
        if (is_string($job['basis_hash'] ?? null) && $job['basis_hash'] !== '') {
            return Fingerprint::fromStored($job['basis_hash'], is_string($job['basis_sketch'] ?? null) ? $job['basis_sketch'] : '');
        }

        return self::fingerprintOf(self::candidate($postId)) ?? self::fingerprintOf(self::live($postId));
    }

    public static function setBasis(int $postId, Fingerprint $fp): void
    {
        self::patchJob($postId, ['basis_hash' => $fp->hash(), 'basis_sketch' => $fp->packedSketch()]);
    }

    /** @return Entry|null */
    private static function arrayMeta(int $postId, string $key): ?array
    {
        $v = get_post_meta($postId, $key, true);

        return is_array($v) && $v !== [] ? $v : null;
    }

    /** @param Entry|null $value */
    private static function write(int $postId, string $key, ?array $value): void
    {
        if ($value === null) {
            delete_post_meta($postId, $key);
            return;
        }
        update_post_meta($postId, $key, $value);
    }
}
