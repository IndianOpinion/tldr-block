<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Admin\EditorPanel;
use IndianOpinion\TldrBlock\Admin\PostsColumn;
use IndianOpinion\TldrBlock\Admin\ReviewScreen;
use IndianOpinion\TldrBlock\Admin\SettingsPage;
use IndianOpinion\TldrBlock\Admin\SiteHealth;
use IndianOpinion\TldrBlock\Admin\TermFields;
use IndianOpinion\TldrBlock\Integrations\GenQueue;
use IndianOpinion\TldrBlock\Integrations\PromptLibrary;
use IndianOpinion\TldrBlock\Rest\Controller;

/**
 * Wires every hook. Nothing here calls a provider or queues anything.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static string $file = '';
    private static bool $booted = false;

    public static function boot(string $file): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$file = $file;

        add_action('init', [self::class, 'init']);
        add_action('plugins_loaded', [self::class, 'integrations'], 20);
        add_action('wp_after_insert_post', [self::class, 'onSave'], 10, 2);
        add_action(Queue::EVENT, [Generator::class, 'cronRun']);
        add_action(Queue::SWEEP_EVENT, [Queue::class, 'sweep']);
        add_filter('the_content', [Renderer::class, 'filterContent'], 20);
        add_action('rest_api_init', [self::class, 'rest']);
        add_action('wp_enqueue_scripts', [self::class, 'registerStyle']);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);

        if (is_admin()) {
            add_action('admin_menu', [SettingsPage::class, 'menu']);
            add_action('admin_menu', [ReviewScreen::class, 'menu']);
            add_action('admin_post_tldr_block_save_settings', [SettingsPage::class, 'handleSave']);
            add_action('admin_post_tldr_block_test_connection', [SettingsPage::class, 'handleTest']);
            add_action('admin_post_tldr_block_review', [ReviewScreen::class, 'handleAction']);
            add_action('admin_init', [self::class, 'privacy']);
            add_action('admin_init', [PostsColumn::class, 'register']);
            TermFields::register();
            EditorPanel::register();
            add_filter('site_status_tests', [SiteHealth::class, 'register']);
        }

        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('tldr-block', Cli\Command::class);
        }
    }

    public static function url(string $path): string
    {
        return plugins_url($path, self::$file === '' ? dirname(__DIR__) . '/tldr-block.php' : self::$file);
    }

    public static function dir(): string
    {
        return dirname(__DIR__);
    }

    public static function init(): void
    {
        load_plugin_textdomain('tldr-block', false, dirname(plugin_basename(self::$file === '' ? dirname(__DIR__) . '/tldr-block.php' : self::$file)) . '/languages');
        self::registerMeta();
        add_shortcode('tldr', [Renderer::class, 'shortcode']);
        register_block_type(self::dir() . '/blocks/summary', ['render_callback' => [Renderer::class, 'renderBlock']]);
    }

    public static function registerMeta(): void
    {
        foreach (Settings::get()['post_types'] as $type) {
            foreach (PostState::KEYS as $key) {
                register_post_meta($type, $key, [
                    'single' => true,
                    'show_in_rest' => false,
                    'auth_callback' => '__return_false',
                    'revisions_enabled' => false,
                ]);
            }
        }
        foreach ([Modes::TERM_MODE, Modes::TERM_MIN_WORDS] as $key) {
            register_term_meta('category', $key, [
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => '__return_false',
            ]);
        }
    }

    public static function integrations(): void
    {
        add_action(GenQueue::REGISTER_ACTION, [GenQueue::class, 'registerType']);
        add_action('init', [PromptLibrary::class, 'register'], 20);
    }

    public static function onSave(int $postId, mixed $post = null): void
    {
        Queue::onSave($postId, $post instanceof \WP_Post ? $post : null);
    }

    /**
     * Trash and untrash do not always reach wp_after_insert_post with the
     * final status, so cancel on the transition too.
     */
    public static function onTransition(string $new, string $old, mixed $post): void
    {
        if ($post instanceof \WP_Post && $new === 'trash' && $old !== 'trash') {
            Queue::cancel($post->ID, 'trash');
        }
    }

    public static function rest(): void
    {
        Controller::register();
        foreach (Settings::get()['post_types'] as $type) {
            register_rest_field($type, 'tldr_block', [
                'get_callback' => [Renderer::class, 'restField'],
                'schema' => [
                    'description' => __('The approved summary shown to readers, or null.', 'tldr-block'),
                    'type' => ['object', 'null'],
                    'context' => ['view', 'edit', 'embed'],
                    'readonly' => true,
                ],
            ]);
        }
    }

    public static function registerStyle(): void
    {
        wp_register_style(Renderer::STYLE_HANDLE, self::url('assets/tldr-block.css'), [], self::VERSION);
    }

    public static function privacy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            'tldr-block',
            '<p>' . esc_html__(
                'This site uses a third-party language model provider to draft short summaries of some posts. '
                . 'The title and text of those posts are sent to the provider. No reader, commenter or author data is sent. '
                . 'An editor reviews every summary before it is shown.',
                'tldr-block'
            ) . '</p>'
        );
    }
}
