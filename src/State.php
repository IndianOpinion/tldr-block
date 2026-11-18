<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Full state of a post for the panel, CLI and tldr_block_state().
 */
final class State
{
    /**
     * @return array<string, mixed>
     */
    public static function of(int $postId): array
    {
        $job = PostState::job($postId);
        $history = [];
        foreach (PostState::history($postId) as $row) {
            $history[] = [
                'history_id' => (string) $row['history_id'],
                'kind' => (string) ($row['kind'] ?? ''),
                'format' => (string) ($row['format'] ?? 'bullets'),
                'points' => array_values((array) ($row['points'] ?? [])),
                'text' => (string) ($row['text'] ?? ''),
                'origin' => (string) ($row['origin'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'archived_at' => (int) ($row['archived_at'] ?? 0),
            ];
        }
        $live = PostState::live($postId);
        $candidate = PostState::candidate($postId);
        foreach (['source_sketch'] as $k) {
            if ($live !== null) {
                unset($live[$k]);
            }
            if ($candidate !== null) {
                unset($candidate[$k]);
            }
        }

        return [
            'post_id' => $postId,
            'status' => PostState::status($postId),
            'mode' => Modes::forPost($postId),
            'override' => PostState::override($postId),
            'displayable' => Eligibility::displayable($postId) !== null,
            'words' => isset($job['words']) ? (int) $job['words'] : null,
            'min_words' => Modes::minWords($postId),
            'live' => $live,
            'candidate' => $candidate,
            'history' => $history,
            'job' => [
                'attempts' => (int) ($job['attempts'] ?? 0),
                'next_run' => isset($job['next_run']) ? (int) $job['next_run'] : null,
                'lease_until' => (int) ($job['lease_until'] ?? 0),
                'last_error_class' => (string) ($job['last_error_class'] ?? ''),
                'last_error' => (string) ($job['last_error'] ?? ''),
                'deferred_reason' => (string) ($job['deferred_reason'] ?? ''),
                'day' => (string) ($job['day'] ?? ''),
                'day_count' => (int) ($job['day_count'] ?? 0),
            ],
            'kill_switch' => Settings::killSwitchOn(),
        ];
    }

    /**
     * State plus the buttons this user may use (case 45, 53).
     *
     * @return array<string, mixed>
     */
    public static function forUser(int $postId, int $userId): array
    {
        $state = self::of($postId);
        $actions = [];
        if (user_can($userId, 'edit_post', $postId)) {
            $actions[] = 'generate';
        }
        if (Review::canApprove($postId, $userId)) {
            if ($state['candidate'] !== null) {
                array_push($actions, 'approve', 'edit_approve', 'reject');
                if ($state['live'] !== null && !empty($state['live']['stale'])) {
                    $actions[] = 'reject_keep_previous';
                }
            }
            if ($state['live'] !== null && empty($state['live']['withdrawn'])) {
                $actions[] = 'withdraw';
            }
            if ($state['history'] !== []) {
                $actions[] = 'restore';
            }
            $actions[] = 'manual';
        } else {
            // Authors see the state read-only, with no approver identity.
            if (is_array($state['live'])) {
                unset($state['live']['approved_by']);
            }
        }
        $state['actions'] = $actions;
        $state['can_force'] = Queue::mayForce();

        return $state;
    }
}
