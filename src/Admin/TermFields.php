<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\DisplayEvents;
use IndianOpinion\TldrBlock\Modes;

/**
 * Category edit screen fields (PRD case 69).
 */
final class TermFields
{
    public static function register(): void
    {
        add_action('category_add_form_fields', [self::class, 'addFields']);
        add_action('category_edit_form_fields', [self::class, 'editFields']);
        add_action('created_category', [self::class, 'save']);
        add_action('edited_category', [self::class, 'save']);
    }

    public static function addFields(): void
    {
        echo self::fields('inherit', null, false); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
    }

    public static function editFields(\WP_Term $term): void
    {
        echo self::fields(Modes::termMode($term->term_id), Modes::termMinWords($term->term_id), true); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
    }

    public static function fields(string $mode, ?int $min, bool $table): string
    {
        $select = '<select name="tldr_block_mode" id="tldr_block_mode">';
        foreach (['inherit' => __('Inherit', 'tldr-block'), 'on' => __('On', 'tldr-block'), 'off' => __('Off', 'tldr-block')] as $v => $l) {
            $select .= '<option value="' . esc_attr($v) . '"' . selected($mode, $v, false) . '>' . esc_html($l) . '</option>';
        }
        $select .= '</select>';
        $number = '<input type="number" min="100" max="10000" name="tldr_block_min_words" id="tldr_block_min_words" value="' . esc_attr($min === null ? '' : (string) $min) . '" />'
            . '<p class="description">' . esc_html__('Blank inherits.', 'tldr-block') . '</p>';
        $nonce = wp_nonce_field('tldr_block_term', '_tldr_block_term_nonce', true, false);
        if ($table) {
            return '<tr class="form-field"><th scope="row"><label for="tldr_block_mode">' . esc_html__('Summaries', 'tldr-block') . '</label></th><td>' . $select . $nonce . '</td></tr>'
                . '<tr class="form-field"><th scope="row"><label for="tldr_block_min_words">' . esc_html__('Summary minimum words', 'tldr-block') . '</label></th><td>' . $number . '</td></tr>';
        }

        return '<div class="form-field"><label for="tldr_block_mode">' . esc_html__('Summaries', 'tldr-block') . '</label>' . $select . $nonce . '</div>'
            . '<div class="form-field"><label for="tldr_block_min_words">' . esc_html__('Summary minimum words', 'tldr-block') . '</label>' . $number . '</div>';
    }

    public static function save(int $termId): void
    {
        if (!isset($_POST['_tldr_block_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_tldr_block_term_nonce'])), 'tldr_block_term')) {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        $mode = isset($_POST['tldr_block_mode']) ? sanitize_key(wp_unslash((string) $_POST['tldr_block_mode'])) : 'inherit';
        $min = isset($_POST['tldr_block_min_words']) && $_POST['tldr_block_min_words'] !== '' ? absint($_POST['tldr_block_min_words']) : null;
        self::apply($termId, $mode, $min);
    }

    public static function apply(int $termId, string $mode, ?int $min): void
    {
        if (Modes::saveTerm($termId, $mode, $min)) {
            DisplayEvents::changed($termId, 'category_switch');
        }
    }
}
