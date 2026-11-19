<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Review;
use IndianOpinion\TldrBlock\Settings;

/**
 * Posts, Summaries: the review queue (PRD case 54). No bulk approve
 * (ruling 5): each approval is one deliberate read.
 */
final class ReviewScreen
{
    public const SLUG = 'tldr-block-review';
    public const FILTER_STATUSES = ['pending', 'failed', 'deferred', 'stale'];

    public static function menu(): void
    {
        add_posts_page(__('Summaries', 'tldr-block'), __('Summaries', 'tldr-block'), 'edit_posts', self::SLUG, [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You cannot view summaries.', 'tldr-block'), 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $status = isset($_GET['tldr_status']) ? sanitize_key(wp_unslash((string) $_GET['tldr_status'])) : '';
        $cat = isset($_GET['tldr_cat']) ? absint($_GET['tldr_cat']) : 0;
        // phpcs:enable
        echo self::html($status, $cat); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
    }

    /**
     * Posts needing attention, newest first.
     *
     * @return list<array{id: int, status: string, stale: bool}>
     */
    public static function rows(string $status = '', int $category = 0): array
    {
        $want = in_array($status, self::FILTER_STATUSES, true) ? [$status] : self::FILTER_STATUSES;
        $metaStatuses = array_values(array_diff($want, ['stale']));
        if (in_array('stale', $want, true)) {
            $metaStatuses = array_merge($metaStatuses, ['queued', 'generating', 'approved', 'rejected', 'pending', 'failed', 'deferred']);
        }
        $args = [
            'post_type' => Settings::get()['post_types'],
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'meta_query' => [['key' => PostState::STATUS, 'value' => array_values(array_unique($metaStatuses)), 'compare' => 'IN']],
        ];
        if ($category > 0) {
            $args['cat'] = $category;
        }
        $rows = [];
        foreach (get_posts($args) as $id) {
            $id = (int) $id;
            $s = PostState::status($id);
            $live = PostState::live($id);
            $stale = $live !== null && !empty($live['stale']);
            $match = false;
            foreach ($want as $w) {
                if (($w === 'stale' && $stale) || ($w !== 'stale' && $w === $s)) {
                    $match = true;
                }
            }
            if ($match) {
                $rows[] = ['id' => $id, 'status' => $s, 'stale' => $stale];
            }
        }

        return $rows;
    }

    public static function html(string $status = '', int $category = 0): string
    {
        $out = '<div class="wrap"><h1>' . esc_html__('Summaries to review', 'tldr-block') . '</h1>';
        $out .= '<form method="get"><input type="hidden" name="post_type" value="post" /><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
        $out .= '<select name="tldr_status"><option value="">' . esc_html__('All statuses', 'tldr-block') . '</option>';
        foreach (self::FILTER_STATUSES as $st) {
            $out .= '<option value="' . esc_attr($st) . '"' . selected($status, $st, false) . '>' . esc_html(PostsColumn::label($st)) . '</option>';
        }
        $out .= '</select> ';
        $out .= wp_dropdown_categories(['show_option_all' => __('All categories', 'tldr-block'), 'name' => 'tldr_cat', 'selected' => $category, 'echo' => 0, 'hide_empty' => 0]);
        $out .= ' <button class="button">' . esc_html__('Filter', 'tldr-block') . '</button></form>';

        $rows = self::rows($status, $category);
        if ($rows === []) {
            return $out . '<p>' . esc_html__('Nothing to review.', 'tldr-block') . '</p></div>';
        }
        $out .= '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'tldr-block') . '</th><th>' . esc_html__('Status', 'tldr-block')
            . '</th><th>' . esc_html__('Candidate', 'tldr-block') . '</th><th>' . esc_html__('Actions', 'tldr-block') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = $row['id'];
            $candidate = PostState::candidate($id);
            $link = get_edit_post_link($id, 'raw');
            $out .= '<tr><td><a href="' . esc_url(is_string($link) ? $link : '') . '">' . esc_html(get_the_title($id)) . '</a></td>';
            $out .= '<td>' . esc_html(PostsColumn::label($row['status']) . ($row['stale'] ? ', ' . PostsColumn::label('stale') : '')) . '</td><td>';
            if ($candidate !== null) {
                if (($candidate['format'] ?? '') === 'paragraph') {
                    $out .= '<p>' . esc_html((string) ($candidate['text'] ?? '')) . '</p>';
                } else {
                    $out .= '<ul>';
                    foreach ((array) ($candidate['points'] ?? []) as $p) {
                        $out .= '<li>' . esc_html((string) $p) . '</li>';
                    }
                    $out .= '</ul>';
                }
                foreach ((array) ($candidate['warnings'] ?? []) as $w) {
                    $out .= '<p class="description">' . esc_html(sprintf(__('Warning: %s', 'tldr-block'), (string) $w)) . '</p>';
                }
            }
            $out .= '</td><td>';
            if ($candidate !== null && Review::canApprove($id)) {
                $out .= self::actionForm($id, (string) $candidate['id'], 'approve', __('Approve', 'tldr-block'))
                    . self::actionForm($id, (string) $candidate['id'], 'reject', __('Reject', 'tldr-block'));
            }
            $out .= '</td></tr>';
        }

        return $out . '</tbody></table></div>';
    }

    public static function handleAction(): void
    {
        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('tldr_block_review_' . $postId, '_tldr_block_nonce');
        if ($postId <= 0 || !Review::canApprove($postId)) {
            wp_die(esc_html__('You cannot review this summary.', 'tldr-block'), 403);
        }
        $candidateId = isset($_POST['candidate_id']) ? sanitize_text_field(wp_unslash((string) $_POST['candidate_id'])) : '';
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash((string) $_POST['do'])) : '';
        $r = $do === 'approve'
            ? Review::approve($postId, $candidateId, get_current_user_id())
            : Review::reject($postId, $candidateId, get_current_user_id());
        $arg = $r instanceof \WP_Error ? '&tldr_error=' . rawurlencode((string) $r->get_error_code()) : '&tldr_done=' . rawurlencode($do);
        wp_safe_redirect(admin_url('edit.php?post_type=post&page=' . self::SLUG . $arg));
        exit;
    }

    private static function actionForm(int $postId, string $candidateId, string $do, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="tldr_block_review" />'
            . '<input type="hidden" name="post_id" value="' . esc_attr((string) $postId) . '" />'
            . '<input type="hidden" name="candidate_id" value="' . esc_attr($candidateId) . '" />'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '" />'
            . wp_nonce_field('tldr_block_review_' . $postId, '_tldr_block_nonce', true, false)
            . '<button type="submit" class="button">' . esc_html($label) . '</button></form> ';
    }
}
