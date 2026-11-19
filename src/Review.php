<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Text\Fingerprint;
use IndianOpinion\TldrBlock\Text\Validator;

/**
 * Editor actions (PRD cases 45 to 53). Capability checks happen in the
 * callers (REST, admin screens); these methods only change state.
 */
final class Review
{
    public static function approveCapability(): string
    {
        $cap = apply_filters('tldr_block_approve_capability', 'edit_others_posts');

        return is_string($cap) && $cap !== '' ? $cap : 'edit_others_posts';
    }

    public static function canApprove(int $postId, ?int $userId = null): bool
    {
        $userId ??= get_current_user_id();

        return $userId > 0 && user_can($userId, self::approveCapability()) && user_can($userId, 'edit_post', $postId);
    }

    /**
     * Approve the candidate, optionally with edited text (cases 46, 47).
     *
     * @param array{points?: mixed, text?: mixed}|null $edited
     */
    public static function approve(int $postId, string $candidateId, int $userId, ?array $edited = null): bool|\WP_Error
    {
        $candidate = PostState::candidate($postId);
        if ($candidate === null || ($candidate['id'] ?? '') !== $candidateId) {
            return new \WP_Error('superseded', __('This candidate was replaced by a newer one. Reload the panel.', 'tldr-block'), ['status' => 409]);
        }
        $origin = 'machine';
        if ($edited !== null && (isset($edited['points']) || isset($edited['text']))) {
            $check = self::validateText((string) $candidate['format'], $edited);
            if ($check instanceof \WP_Error) {
                return $check;
            }
            $candidate['points'] = $check['points'];
            $candidate['text'] = $check['text'];
            $origin = 'edited';
        }
        $live = self::liveFrom($candidate, $origin, $userId);
        // The post may have changed materially since the candidate was
        // generated (a newer job is then queued). The approved text then
        // describes an older version and starts out stale (case 19).
        $from = PostState::fingerprintOf($candidate);
        $now = self::currentFingerprint($postId);
        if ($from !== null && $now !== null && $now->isMaterialChangeFrom($from, Settings::threshold())) {
            $live['stale'] = true;
            Logger::log('info', 'approved text is already stale', ['post_id' => $postId]);
        }
        self::goLive($postId, $live);
        PostState::setCandidate($postId, null);
        PostState::setStatus($postId, 'approved');
        Logger::log('info', 'approved', ['post_id' => $postId, 'user_id' => $userId, 'origin' => $origin]);
        do_action('tldr_block_approved', $postId, $live, $userId);
        DisplayEvents::changed($postId, 'approved');

        return true;
    }

    /**
     * Write my own (case 48). No model call.
     *
     * @param array{points?: mixed, text?: mixed} $input
     */
    public static function manual(int $postId, int $userId, array $input): bool|\WP_Error
    {
        $format = isset($input['text']) && !isset($input['points']) ? 'paragraph' : Settings::get()['format'];
        if (isset($input['points'])) {
            $format = 'bullets';
        }
        $check = self::validateText($format, $input);
        if ($check instanceof \WP_Error) {
            return $check;
        }
        $fp = self::currentFingerprint($postId);
        $live = [
            'format' => $format,
            'points' => $check['points'],
            'text' => $check['text'],
            'language' => Generator::language($postId)['code'],
            'generated_at' => null,
            'model' => null,
            'source_hash' => $fp?->hash() ?? '',
            'source_sketch' => $fp?->packedSketch() ?? '',
        ];
        $live = self::liveFrom($live, 'human', $userId);
        self::goLive($postId, $live);
        if ($fp !== null) {
            PostState::setBasis($postId, $fp);
        }
        PostState::setStatus($postId, PostState::candidate($postId) !== null ? 'pending' : 'approved');
        Logger::log('info', 'approved', ['post_id' => $postId, 'user_id' => $userId, 'origin' => 'human']);
        do_action('tldr_block_approved', $postId, $live, $userId);
        DisplayEvents::changed($postId, 'approved');

        return true;
    }

    /** Reject a candidate (case 49). */
    public static function reject(int $postId, string $candidateId, int $userId, string $reason = '', bool $keepPrevious = false): bool|\WP_Error
    {
        $candidate = PostState::candidate($postId);
        if ($candidate === null || ($candidate['id'] ?? '') !== $candidateId) {
            return new \WP_Error('superseded', __('This candidate was replaced by a newer one. Reload the panel.', 'tldr-block'), ['status' => 409]);
        }
        $reason = mb_substr(trim(wp_strip_all_tags($reason)), 0, 200, 'UTF-8');
        $candidate['rejected_by'] = $userId;
        PostState::pushHistory($postId, $candidate, 'rejected', $reason);
        PostState::setCandidate($postId, null);
        PostState::setStatus($postId, 'rejected');
        Logger::log('info', 'rejected', ['post_id' => $postId, 'user_id' => $userId]);
        do_action('tldr_block_rejected', $postId, $candidateId, $userId, $reason);

        $live = PostState::live($postId);
        if ($keepPrevious && $live !== null && !empty($live['stale'])) {
            $fp = self::currentFingerprint($postId);
            $live['stale'] = false;
            $live['withdrawn'] = false;
            $live['approved_by'] = $userId;
            $live['approved_at'] = Clock::now();
            if ($fp !== null) {
                $live['source_hash'] = $fp->hash();
                $live['source_sketch'] = $fp->packedSketch();
                PostState::setBasis($postId, $fp);
            }
            PostState::setLive($postId, $live);
            PostState::setStatus($postId, 'approved');
            Logger::log('info', 'approved', ['post_id' => $postId, 'user_id' => $userId, 'origin' => 'keep_previous']);
            do_action('tldr_block_approved', $postId, $live, $userId);
            DisplayEvents::changed($postId, 'approved');
        }

        return true;
    }

    /** Withdraw the live summary (case 51). */
    public static function withdraw(int $postId, int $userId): bool|\WP_Error
    {
        $live = PostState::live($postId);
        if ($live === null) {
            return new \WP_Error('no_summary', __('There is no live summary to withdraw.', 'tldr-block'), ['status' => 409]);
        }
        $live['withdrawn'] = true;
        PostState::setLive($postId, $live);
        PostState::setStatus($postId, 'withdrawn');
        Logger::log('info', 'withdrawn', ['post_id' => $postId, 'user_id' => $userId]);
        do_action('tldr_block_withdrawn', $postId, $userId);
        DisplayEvents::changed($postId, 'withdrawn');

        return true;
    }

    /** Restore any history entry (case 52). */
    public static function restore(int $postId, string $historyId, int $userId): bool|\WP_Error
    {
        $entry = null;
        foreach (PostState::history($postId) as $row) {
            if (($row['history_id'] ?? '') === $historyId) {
                $entry = $row;
                break;
            }
        }
        if ($entry === null) {
            return new \WP_Error('not_found', __('That history entry does not exist.', 'tldr-block'), ['status' => 404]);
        }
        $fp = self::currentFingerprint($postId);
        if ($fp !== null) {
            $entry['source_hash'] = $fp->hash();
            $entry['source_sketch'] = $fp->packedSketch();
            PostState::setBasis($postId, $fp);
        }
        $live = self::liveFrom($entry, 'restored', $userId);
        self::goLive($postId, $live);
        PostState::setStatus($postId, PostState::candidate($postId) !== null ? 'pending' : 'approved');
        Logger::log('info', 'approved', ['post_id' => $postId, 'user_id' => $userId, 'origin' => 'restored']);
        do_action('tldr_block_approved', $postId, $live, $userId);
        DisplayEvents::changed($postId, 'restored');

        return true;
    }

    /**
     * Case 32 rules 1 to 5 for text written or edited by a person.
     *
     * @param array{points?: mixed, text?: mixed} $input
     * @return array{points: list<string>, text: string}|\WP_Error
     */
    public static function validateText(string $format, array $input): array|\WP_Error
    {
        $s = Settings::get();
        $output = $format === 'paragraph'
            ? ['summary' => is_string($input['text'] ?? null) ? $input['text'] : null]
            : ['points' => is_array($input['points'] ?? null) ? array_values($input['points']) : null];
        $check = Validator::check($output, [
            'format' => $format,
            'points_min' => $s['points_min'],
            'points_max' => $s['points_max'],
            'max_words' => $s['point_words'],
            'paragraph_words' => $s['paragraph_words'],
        ]);
        $errors = array_values(array_diff($check['errors'], ['instruction']));
        if ($errors !== []) {
            return new \WP_Error('invalid', __('The summary did not pass validation.', 'tldr-block'), ['status' => 422, 'errors' => $errors]);
        }

        return ['points' => $check['points'], 'text' => $check['text']];
    }

    /**
     * @param array<string, mixed> $from
     * @return array<string, mixed>
     */
    private static function liveFrom(array $from, string $origin, int $userId): array
    {
        return [
            'format' => ($from['format'] ?? '') === 'paragraph' ? 'paragraph' : 'bullets',
            'points' => array_values((array) ($from['points'] ?? [])),
            'text' => (string) ($from['text'] ?? ''),
            'language' => (string) ($from['language'] ?? ''),
            'origin' => $origin,
            'approved_by' => $userId,
            'approved_at' => Clock::now(),
            'generated_at' => isset($from['generated_at']) && is_numeric($from['generated_at']) ? (int) $from['generated_at'] : null,
            'model' => isset($from['model']) && is_string($from['model']) ? $from['model'] : null,
            'source_hash' => (string) ($from['source_hash'] ?? ''),
            'source_sketch' => (string) ($from['source_sketch'] ?? ''),
            'stale' => false,
            'withdrawn' => false,
        ];
    }

    /** @param array<string, mixed> $live */
    private static function goLive(int $postId, array $live): void
    {
        $previous = PostState::live($postId);
        if ($previous !== null) {
            PostState::pushHistory($postId, $previous, 'live');
        }
        PostState::setLive($postId, $live);
    }

    private static function currentFingerprint(int $postId): ?Fingerprint
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post ? Fingerprint::of(Eligibility::sourceText($post)) : null;
    }
}
