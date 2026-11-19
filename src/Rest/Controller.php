<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Rest;

use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Review;
use IndianOpinion\TldrBlock\State;

/**
 * Routes under tldr-block/v1 (PRD section 5.5).
 */
final class Controller
{
    public const NS = 'tldr-block/v1';

    public static function register(): void
    {
        $id = '/posts/(?P<id>\d+)';
        $idArg = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        register_rest_route(self::NS, $id, [
            'methods' => 'GET',
            'callback' => [self::class, 'get'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $idArg,
        ]);
        register_rest_route(self::NS, $id . '/generate', [
            'methods' => 'POST',
            'callback' => [self::class, 'generate'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => $idArg + ['force' => ['type' => 'boolean', 'default' => false]],
        ]);
        $text = [
            'points' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 2000], 'maxItems' => 20],
            'text' => ['type' => 'string', 'maxLength' => 5000],
        ];
        register_rest_route(self::NS, $id . '/approve', [
            'methods' => 'POST',
            'callback' => [self::class, 'approve'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg + ['candidate_id' => ['type' => 'string', 'required' => true, 'maxLength' => 64]] + $text,
        ]);
        register_rest_route(self::NS, $id . '/reject', [
            'methods' => 'POST',
            'callback' => [self::class, 'reject'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg + [
                'candidate_id' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                'reason' => ['type' => 'string', 'default' => '', 'maxLength' => 1000],
                'keep_previous' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
        register_rest_route(self::NS, $id . '/withdraw', [
            'methods' => 'POST',
            'callback' => [self::class, 'withdraw'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg,
        ]);
        register_rest_route(self::NS, $id . '/restore', [
            'methods' => 'POST',
            'callback' => [self::class, 'restore'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg + ['history_id' => ['type' => 'string', 'required' => true, 'maxLength' => 64]],
        ]);
        register_rest_route(self::NS, $id . '/override', [
            'methods' => 'POST',
            'callback' => [self::class, 'override'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg + ['mode' => ['type' => 'string', 'required' => true, 'enum' => ['auto', 'on', 'off']]],
        ]);
        register_rest_route(self::NS, $id . '/manual', [
            'methods' => 'PUT',
            'callback' => [self::class, 'manual'],
            'permission_callback' => [self::class, 'canApprove'],
            'args' => $idArg + $text,
        ]);
    }

    public static function canEdit(\WP_REST_Request $request): bool|\WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!get_post($id) instanceof \WP_Post) {
            return new \WP_Error('not_found', __('Post not found.', 'tldr-block'), ['status' => 404]);
        }
        if (!current_user_can('edit_post', $id)) {
            return new \WP_Error('rest_forbidden', __('You cannot edit this post.', 'tldr-block'), ['status' => rest_authorization_required_code()]);
        }

        return true;
    }

    public static function canApprove(\WP_REST_Request $request): bool|\WP_Error
    {
        $edit = self::canEdit($request);
        if ($edit !== true) {
            return $edit;
        }
        if (!current_user_can(Review::approveCapability())) {
            return new \WP_Error('rest_forbidden', __('You cannot review summaries.', 'tldr-block'), ['status' => rest_authorization_required_code()]);
        }

        return true;
    }

    public static function get(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(State::forUser((int) $request->get_param('id'), get_current_user_id()), 200);
    }

    public static function generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');
        $r = Queue::manual($id, ['manual' => true, 'force' => (bool) $request->get_param('force')]);
        if ($r instanceof \WP_Error) {
            return $r;
        }

        return new \WP_REST_Response(State::forUser($id, get_current_user_id()), 202);
    }

    public static function approve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');
        $edited = null;
        if ($request->get_param('points') !== null || $request->get_param('text') !== null) {
            $edited = array_filter(['points' => $request->get_param('points'), 'text' => $request->get_param('text')], static fn ($v): bool => $v !== null);
        }

        return self::respond($id, Review::approve($id, (string) $request->get_param('candidate_id'), get_current_user_id(), $edited));
    }

    public static function reject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');

        return self::respond($id, Review::reject(
            $id,
            (string) $request->get_param('candidate_id'),
            get_current_user_id(),
            (string) $request->get_param('reason'),
            (bool) $request->get_param('keep_previous')
        ));
    }

    public static function withdraw(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');

        return self::respond($id, Review::withdraw($id, get_current_user_id()));
    }

    public static function restore(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');

        return self::respond($id, Review::restore($id, (string) $request->get_param('history_id'), get_current_user_id()));
    }

    public static function manual(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');
        $input = array_filter(['points' => $request->get_param('points'), 'text' => $request->get_param('text')], static fn ($v): bool => $v !== null);

        return self::respond($id, Review::manual($id, get_current_user_id(), $input));
    }

    /** Per-post override (auto, on, off); fires display_changed with post_switch. */
    public static function override(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $mode = (string) $request->get_param('mode');
        if (\IndianOpinion\TldrBlock\PostState::override($id) !== $mode) {
            \IndianOpinion\TldrBlock\PostState::setOverride($id, $mode);
            \IndianOpinion\TldrBlock\DisplayEvents::changed($id, 'post_switch');
        }

        return new \WP_REST_Response(State::forUser($id, get_current_user_id()), 200);
    }

    private static function respond(int $id, bool|\WP_Error $result): \WP_REST_Response|\WP_Error
    {
        if ($result instanceof \WP_Error) {
            return $result;
        }

        return new \WP_REST_Response(State::forUser($id, get_current_user_id()), 200);
    }
}
