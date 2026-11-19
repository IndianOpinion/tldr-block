<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\Eligibility;
use IndianOpinion\TldrBlock\Plugin;
use IndianOpinion\TldrBlock\Settings;

/**
 * The Summary panel: a document sidebar panel in the block editor and a
 * meta box in the classic editor (PRD case 45). Both talk to the REST
 * routes; no build step, the scripts use the wp globals (ruling 8).
 */
final class EditorPanel
{
    public static function register(): void
    {
        add_action('enqueue_block_editor_assets', [self::class, 'blockEditorAssets']);
        add_action('add_meta_boxes', [self::class, 'metaBox'], 10, 2);
    }

    public static function blockEditorAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $type = $screen !== null ? $screen->post_type : '';
        if ($type === '' || !Eligibility::typeAllowed($type)) {
            return;
        }
        wp_enqueue_script(
            'tldr-block-panel',
            Plugin::url('assets/editor-panel.js'),
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-data'],
            Plugin::VERSION,
            true
        );
        wp_set_script_translations('tldr-block-panel', 'tldr-block');
    }

    public static function metaBox(string $postType, mixed $post = null): void
    {
        if (!Eligibility::typeAllowed($postType)) {
            return;
        }
        add_meta_box(
            'tldr-block',
            __('Summary', 'tldr-block'),
            [self::class, 'renderMetaBox'],
            $postType,
            'side',
            'default',
            ['__back_compat_meta_box' => true]
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        wp_enqueue_script('tldr-block-metabox', Plugin::url('assets/metabox.js'), ['wp-api-fetch', 'wp-i18n'], Plugin::VERSION, true);
        wp_set_script_translations('tldr-block-metabox', 'tldr-block');
        echo '<div class="tldr-block-metabox" data-post-id="' . esc_attr((string) $post->ID) . '">'
            . esc_html__('Loading summary state...', 'tldr-block') . '</div>';
        if (Settings::killSwitchOn()) {
            echo '<p class="description">' . esc_html__('Generation is paused by the kill switch.', 'tldr-block') . '</p>';
        }
    }
}
