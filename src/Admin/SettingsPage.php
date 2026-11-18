<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Counters;
use IndianOpinion\TldrBlock\Llm\CallFailed;
use IndianOpinion\TldrBlock\Llm\CallRequest;
use IndianOpinion\TldrBlock\Llm\ClientFactory;
use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Settings;

/**
 * Settings, Summaries (PRD case 68).
 */
final class SettingsPage
{
    public const SLUG = 'tldr-block';
    public const NOTICE = 'tldr_block_notice';

    public static function capability(): string
    {
        $cap = apply_filters('tldr_block_settings_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public static function menu(): void
    {
        add_options_page(__('Summaries', 'tldr-block'), __('Summaries', 'tldr-block'), self::capability(), self::SLUG, [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You cannot manage these settings.', 'tldr-block'), 403);
        }
        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
    }

    /** The whole screen, escaped. */
    public static function html(): string
    {
        $s = Settings::get();
        $c = Counters::today();
        $out = '<div class="wrap"><h1>' . esc_html__('Summaries', 'tldr-block') . '</h1>';

        $notice = get_transient(self::NOTICE . '_' . get_current_user_id());
        if (is_string($notice) && $notice !== '') {
            delete_transient(self::NOTICE . '_' . get_current_user_id());
            $out .= '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        if (!Settings::providerConfigured()) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html__('Generation is off until an API key and at least one model are set.', 'tldr-block') . '</p></div>';
        }

        // Today's counters.
        $out .= '<h2>' . esc_html__('Today', 'tldr-block') . '</h2><table class="widefat striped"><tbody>';
        $rows = [
            __('Generations', 'tldr-block') => sprintf('%d / %d', $c['count'], Settings::dailyCap()),
            __('Cost', 'tldr-block') => $c['cost_seen'] ? (string) round($c['cost'], 4) : __('not reported', 'tldr-block'),
            __('Cost cap', 'tldr-block') => $s['cost_cap'] === null
                ? __('none', 'tldr-block')
                : ($c['cost_seen'] ? (string) $s['cost_cap'] : __('inactive: the provider has not reported cost today', 'tldr-block')),
            __('Pending reviews', 'tldr-block') => (string) self::countStatus('pending'),
            __('Failed jobs', 'tldr-block') => (string) self::countStatus('failed'),
            __('Deferred jobs', 'tldr-block') => (string) self::countStatus('deferred'),
            __('Next scheduled run', 'tldr-block') => self::nextRun(),
        ];
        if (Counters::held()) {
            $rows[__('Credit hold until', 'tldr-block')] = gmdate('Y-m-d H:i', Counters::holdUntil()) . ' UTC';
        }
        foreach ($rows as $label => $value) {
            $out .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        $errors = Logger::recentErrors();
        if ($errors !== []) {
            $out .= '<h3>' . esc_html__('Last errors', 'tldr-block') . '</h3><ul>';
            foreach ($errors as $e) {
                $out .= '<li>' . esc_html(gmdate('Y-m-d H:i', $e['time']) . ' UTC: ' . $e['message'] . ($e['post_id'] > 0 ? ' (post ' . $e['post_id'] . ')' : '')) . '</li>';
            }
            $out .= '</ul>';
        }

        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $out .= '<input type="hidden" name="action" value="tldr_block_save_settings" />';
        $out .= wp_nonce_field('tldr_block_save_settings', '_tldr_block_nonce', true, false);
        $out .= '<table class="form-table" role="presentation"><tbody>';

        $locked = Settings::killSwitchLocked();
        $out .= self::row(__('Generation', 'tldr-block'), self::select('generation', ['on' => __('On', 'tldr-block'), 'off' => __('Off (kill switch)', 'tldr-block')], $locked ? 'off' : $s['generation'], $locked)
            . ($locked ? ' <em>' . esc_html__('Locked off by TLDR_BLOCK_DISABLED.', 'tldr-block') . '</em>' : ''));
        $out .= self::row(__('Display', 'tldr-block'), self::select('display', ['on' => __('On', 'tldr-block'), 'off' => __('Off', 'tldr-block')], $s['display']));
        $out .= self::row(__('Global default mode', 'tldr-block'), self::select('default_mode', ['on' => __('On', 'tldr-block'), 'off' => __('Off', 'tldr-block')], $s['default_mode']));

        $types = '';
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if (!is_string($type) || !post_type_supports($type, 'editor')) {
                continue;
            }
            $types .= self::checkbox('post_types][', $type, in_array($type, $s['post_types'], true), $type) . '<br />';
        }
        $out .= self::row(__('Post types', 'tldr-block'), $types);
        $statuses = '';
        foreach (Settings::STATUSES as $st) {
            $statuses .= self::checkbox('statuses][', $st, in_array($st, $s['statuses'], true), $st) . '<br />';
        }
        $out .= self::row(__('Trigger statuses', 'tldr-block'), $statuses);
        $out .= self::row(__('Minimum words', 'tldr-block'), self::number('min_words', $s['min_words'], 100, 10000));
        $out .= self::row(__('Format', 'tldr-block'), self::select('format', ['bullets' => __('Bullet points', 'tldr-block'), 'paragraph' => __('Paragraph', 'tldr-block')], $s['format']));
        $out .= self::row(__('Points (min, max)', 'tldr-block'), self::number('points_min', $s['points_min'], 1, 8) . ' ' . self::number('points_max', $s['points_max'], 1, 10));
        $out .= self::row(__('Words per point', 'tldr-block'), self::number('point_words', $s['point_words'], 8, 60));
        $out .= self::row(__('Paragraph words', 'tldr-block'), self::number('paragraph_words', $s['paragraph_words'], 30, 200));
        $out .= self::row(__('Heading text', 'tldr-block'), '<input type="text" maxlength="60" name="tldr_block[heading]" value="' . esc_attr($s['heading']) . '" />');
        $placements = [
            'top' => __('Top', 'tldr-block'),
            'after_first_paragraph' => __('After the first paragraph', 'tldr-block'),
            'none' => __('None (shortcode, block or template only)', 'tldr-block'),
        ];
        $out .= self::row(__('Placement', 'tldr-block'), self::select('placement', $placements, $s['placement']));
        $stale = ['hide' => __('Hide until a new summary is approved', 'tldr-block'), 'keep' => __('Keep showing the old summary', 'tldr-block')];
        $out .= self::row(__('Stale display', 'tldr-block'), self::select('stale_display', $stale, $s['stale_display']));
        $out .= self::row(__('Change threshold', 'tldr-block'), '<input type="number" step="0.01" min="0.01" max="0.90" name="tldr_block[threshold]" value="' . esc_attr((string) $s['threshold']) . '" />');
        $out .= self::row(__('Debounce (seconds)', 'tldr-block'), self::number('debounce', $s['debounce'], 0, 3600));
        $out .= self::row(__('Daily cap', 'tldr-block'), self::number('daily_cap', Settings::dailyCap(), 0, 10000, Settings::dailyCapLocked()));
        $costCap = '<input type="number" step="0.0001" min="0" name="tldr_block[cost_cap]" value="' . esc_attr($s['cost_cap'] === null ? '' : (string) $s['cost_cap']) . '" />';
        $out .= self::row(
            __('Daily cost cap', 'tldr-block'),
            $costCap . self::describe(__('Provider currency. Blank means none. Applies only when the provider reports cost.', 'tldr-block'))
        );
        $out .= self::row(__('Per-post daily limit', 'tldr-block'), self::number('post_limit', $s['post_limit'], 1, 20));
        $out .= self::row(__('Input limit (characters)', 'tldr-block'), self::number('input_limit', $s['input_limit'], 2000, 200000));
        $out .= self::row(__('History entries', 'tldr-block'), self::number('history', $s['history'], 0, 20));
        $out .= self::row(__('Feeds', 'tldr-block'), self::select('feeds', ['off' => __('Off', 'tldr-block'), 'on' => __('On', 'tldr-block')], $s['feeds']));
        $out .= self::row(__('REST content insertion', 'tldr-block'), self::select('rest_content', ['off' => __('Off', 'tldr-block'), 'on' => __('On', 'tldr-block')], $s['rest_content']));
        $out .= self::row(__('Provider', 'tldr-block'), self::select('provider', ['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'compatible' => __('OpenAI-compatible (base URL)', 'tldr-block')], $s['provider']));
        $out .= self::row(__('Base URL', 'tldr-block'), '<input type="url" class="regular-text" name="tldr_block[base_url]" value="' . esc_attr($s['base_url']) . '" />');
        $models = '<input type="text" class="regular-text" name="tldr_block[models]" value="' . esc_attr(implode(', ', $s['models'])) . '" />';
        $out .= self::row(__('Model chain', 'tldr-block'), $models . self::describe(__('One to three model ids, comma separated, primary first.', 'tldr-block')));

        $source = Settings::apiKeySource();
        if ($source === 'constant' || $source === 'environment') {
            $key = '<em>' . esc_html(sprintf(__('Set by the %s (ends in %s).', 'tldr-block'), $source, Settings::apiKeyHint())) . '</em>';
        } else {
            $hint = Settings::apiKeyHint();
            $key = '<input type="password" autocomplete="off" class="regular-text" name="tldr_block_api_key" value="" placeholder="'
                . esc_attr($hint === '' ? __('not set', 'tldr-block') : sprintf(__('set, ends in %s', 'tldr-block'), $hint)) . '" />'
                . ' <label><input type="checkbox" name="tldr_block_clear_key" value="1" /> ' . esc_html__('Remove the stored key', 'tldr-block') . '</label>';
        }
        $out .= self::row(__('API key', 'tldr-block'), $key);
        $out .= self::row(__('Script check', 'tldr-block'), self::checkbox('script_check', '1', $s['script_check'], __('Reject output mostly in the wrong script', 'tldr-block')));
        $out .= self::row(__('Debug logging', 'tldr-block'), self::checkbox('debug', '1', $s['debug'], __('Add token counts, model and duration to the log', 'tldr-block')));
        $out .= '</tbody></table>';
        $out .= '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Save settings', 'tldr-block') . '</button></p></form>';

        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $out .= '<input type="hidden" name="action" value="tldr_block_test_connection" />';
        $out .= wp_nonce_field('tldr_block_test_connection', '_tldr_block_nonce', true, false);
        $out .= '<p><button type="submit" class="button">' . esc_html__('Test connection', 'tldr-block') . '</button> <span class="description">'
            . esc_html__('Makes one tiny call. It counts against the daily cap.', 'tldr-block') . '</span></p></form></div>';

        return $out;
    }

    public static function handleSave(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You cannot manage these settings.', 'tldr-block'), 403);
        }
        check_admin_referer('tldr_block_save_settings', '_tldr_block_nonce');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field in Settings::sanitize().
        $raw = isset($_POST['tldr_block']) && is_array($_POST['tldr_block']) ? wp_unslash($_POST['tldr_block']) : [];
        self::save(
            is_array($raw) ? $raw : [],
            isset($_POST['tldr_block_api_key']) ? sanitize_text_field(wp_unslash((string) $_POST['tldr_block_api_key'])) : '',
            !empty($_POST['tldr_block_clear_key'])
        );
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG . '&updated=1'));
        exit;
    }

    /**
     * Apply a submitted form. Unchecked boxes and empty lists arrive as
     * missing keys, so they are set explicitly here.
     *
     * @param array<string, mixed> $raw
     */
    public static function save(array $raw, string $key, bool $clearKey): void
    {
        $raw['post_types'] = isset($raw['post_types']) && is_array($raw['post_types']) ? $raw['post_types'] : [];
        $raw['statuses'] = isset($raw['statuses']) && is_array($raw['statuses']) ? $raw['statuses'] : [];
        $raw['script_check'] = !empty($raw['script_check']);
        $raw['debug'] = !empty($raw['debug']);
        if (Settings::killSwitchLocked()) {
            unset($raw['generation']);
        }
        if (Settings::dailyCapLocked()) {
            unset($raw['daily_cap']);
        }
        if (isset($raw['heading']) && is_string($raw['heading'])) {
            $raw['heading'] = sanitize_text_field($raw['heading']);
        }
        Settings::update($raw);
        if (Settings::apiKeySource() !== 'constant' && Settings::apiKeySource() !== 'environment') {
            if ($clearKey) {
                Settings::saveApiKey('');
            } elseif ($key !== '') {
                Settings::saveApiKey($key);
            }
        }
    }

    public static function handleTest(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You cannot manage these settings.', 'tldr-block'), 403);
        }
        check_admin_referer('tldr_block_test_connection', '_tldr_block_nonce');
        $r = self::testConnection();
        set_transient(self::NOTICE . '_' . get_current_user_id(), $r['message'], 120);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }

    /**
     * One tiny call, counted against the daily cap.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array
    {
        $caller = ClientFactory::caller();
        if ($caller === null) {
            return ['ok' => false, 'message' => __('Test connection: no provider is configured.', 'tldr-block')];
        }
        $refusal = Counters::reserve();
        if ($refusal !== '') {
            return ['ok' => false, 'message' => sprintf(__('Test connection refused: %s.', 'tldr-block'), $refusal)];
        }
        try {
            $r = $caller->call(new CallRequest(
                'tldr-block.test',
                'Answer with JSON only.',
                'Reply with {"ok": true}.',
                ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false],
                'test',
                0.0,
                20,
                30.0
            ));
            Counters::addCost($r->cost);
        } catch (CallFailed $e) {
            Logger::log('error', 'test connection failed', ['class' => $e->errorClass]);

            return ['ok' => false, 'message' => sprintf(__('Test connection failed (%s). The call counted against the daily cap.', 'tldr-block'), $e->errorClass)];
        }

        return ['ok' => true, 'message' => sprintf(__('Test connection worked: %1$s answered. The call counted against the daily cap.', 'tldr-block'), $r->model)];
    }

    private static function countStatus(string $status): int
    {
        $ids = get_posts([
            'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 500,
            'no_found_rows' => true, 'meta_key' => PostState::STATUS, 'meta_value' => $status,
        ]);

        return count($ids);
    }

    private static function nextRun(): string
    {
        $next = null;
        $crons = _get_cron_array();
        foreach (is_array($crons) ? $crons : [] as $ts => $hooks) {
            if (isset($hooks[Queue::EVENT]) || isset($hooks[Queue::SWEEP_EVENT])) {
                $next = $next === null ? (int) $ts : min($next, (int) $ts);
            }
        }
        if ($next === null) {
            return __('none', 'tldr-block');
        }

        return gmdate('Y-m-d H:i', $next) . ' UTC' . ($next < Clock::now() ? ' ' . __('(overdue)', 'tldr-block') : '');
    }

    /** A labelled checkbox. A name ending in "][" makes a list field. */
    private static function checkbox(string $name, string $value, bool $checked, string $label): string
    {
        return '<label><input type="checkbox" name="tldr_block[' . esc_attr($name) . ']" value="' . esc_attr($value) . '"'
            . checked($checked, true, false) . ' /> ' . esc_html($label) . '</label>';
    }

    private static function describe(string $text): string
    {
        return ' <span class="description">' . esc_html($text) . '</span>';
    }

    private static function row(string $label, string $field): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
    }

    /** @param array<string, string> $options */
    private static function select(string $name, array $options, string $current, bool $disabled = false): string
    {
        $html = '<select name="tldr_block[' . esc_attr($name) . ']"' . ($disabled ? ' disabled="disabled"' : '') . '>';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    private static function number(string $name, int $value, int $min, int $max, bool $disabled = false): string
    {
        return '<input type="number" name="tldr_block[' . esc_attr($name) . ']" value="' . esc_attr((string) $value) . '" min="' . $min . '" max="' . $max . '"'
            . ($disabled ? ' disabled="disabled"' : '') . ' />';
    }
}
