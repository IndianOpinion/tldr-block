<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Plugin settings: defaults, sanitising, constants and the API key.
 *
 * @phpstan-type SettingsArray array{
 *     generation: string, display: string, default_mode: string,
 *     post_types: list<string>, statuses: list<string>, min_words: int,
 *     format: string, points_min: int, points_max: int, point_words: int,
 *     paragraph_words: int, heading: string, placement: string,
 *     stale_display: string, threshold: float, debounce: int, daily_cap: int,
 *     cost_cap: ?float, post_limit: int, input_limit: int, history: int,
 *     feeds: string, rest_content: string, provider: string, base_url: string,
 *     models: list<string>, script_check: bool, debug: bool
 * }
 */
final class Settings
{
    public const OPTION = 'tldr_block_settings';
    public const KEY_OPTION = 'tldr_block_api_key';

    public const PROVIDERS = ['openrouter', 'openai', 'compatible'];
    public const STATUSES = ['publish', 'future', 'pending', 'draft'];

    /**
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        $defaults = [
            'generation' => 'on',
            'display' => 'on',
            // Ruling 10: a fresh install is off; the operator opts in by category or globally.
            'default_mode' => 'off',
            'post_types' => ['post'],
            'statuses' => ['publish', 'future', 'pending'],
            'min_words' => 600,
            'format' => 'bullets',
            'points_min' => 3,
            'points_max' => 5,
            'point_words' => 30,
            'paragraph_words' => 80,
            'heading' => function_exists('__') ? __('Summary', 'tldr-block') : 'Summary',
            'placement' => 'top',
            'stale_display' => 'hide',
            'threshold' => 0.10,
            'debounce' => 300,
            'daily_cap' => 50,
            'cost_cap' => null,
            'post_limit' => 3,
            'input_limit' => 30000,
            'history' => 5,
            'feeds' => 'off',
            'rest_content' => 'off',
            'provider' => 'openrouter',
            'base_url' => '',
            'models' => [],
            'script_check' => true,
            'debug' => false,
        ];
        $filtered = apply_filters('tldr_block_default_settings', $defaults);

        return self::sanitize(is_array($filtered) ? $filtered : [], $defaults);
    }

    /**
     * Stored values merged over the defaults. Read fresh every time.
     *
     * @return SettingsArray
     */
    public static function get(): array
    {
        $defaults = self::defaults();
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return self::sanitize($stored, $defaults);
    }

    /**
     * @param array<string, mixed> $changes
     * @return SettingsArray
     */
    public static function update(array $changes): array
    {
        $next = self::sanitize($changes, self::get());
        update_option(self::OPTION, $next, true);

        return $next;
    }

    /**
     * Clamp and validate every field. Unknown keys are dropped; invalid
     * values fall back to the base.
     *
     * @param array<string, mixed> $in
     * @param SettingsArray $base
     * @return SettingsArray
     */
    public static function sanitize(array $in, array $base): array
    {
        $out = $base;
        foreach (['generation', 'display', 'feeds', 'rest_content'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = self::onOff($in[$k], $base[$k]);
            }
        }
        if (isset($in['default_mode'])) {
            $out['default_mode'] = self::onOff($in['default_mode'], $base['default_mode']);
        }
        if (isset($in['post_types']) && is_array($in['post_types'])) {
            $types = [];
            foreach ($in['post_types'] as $t) {
                if (is_string($t) && preg_match('/^[a-z0-9_-]{1,20}$/', $t) === 1) {
                    $types[] = $t;
                }
            }
            $out['post_types'] = array_values(array_unique($types));
        }
        if (isset($in['statuses']) && is_array($in['statuses'])) {
            $out['statuses'] = array_values(array_intersect(self::STATUSES, array_map('strval', $in['statuses'])));
        }
        $ints = [
            'min_words' => [100, 10000], 'point_words' => [8, 60], 'paragraph_words' => [30, 200],
            'debounce' => [0, 3600], 'daily_cap' => [0, 10000], 'post_limit' => [1, 20],
            'input_limit' => [2000, 200000], 'history' => [0, 20], 'points_min' => [1, 8],
            'points_max' => [1, 10],
        ];
        foreach ($ints as $k => [$min, $max]) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                $out[$k] = max($min, min($max, (int) $in[$k]));
            }
        }
        if ($out['points_max'] < $out['points_min']) {
            $out['points_max'] = $out['points_min'];
        }
        if (isset($in['format']) && in_array($in['format'], ['bullets', 'paragraph'], true)) {
            $out['format'] = $in['format'];
        }
        if (isset($in['heading']) && is_string($in['heading'])) {
            $out['heading'] = mb_substr(trim(self::plain($in['heading'])), 0, 60, 'UTF-8');
        }
        if (isset($in['placement']) && in_array($in['placement'], ['top', 'after_first_paragraph', 'none'], true)) {
            $out['placement'] = $in['placement'];
        }
        if (isset($in['stale_display']) && in_array($in['stale_display'], ['hide', 'keep'], true)) {
            $out['stale_display'] = $in['stale_display'];
        }
        if (isset($in['threshold']) && is_numeric($in['threshold'])) {
            $out['threshold'] = max(0.01, min(0.90, round((float) $in['threshold'], 4)));
        }
        if (array_key_exists('cost_cap', $in)) {
            $v = $in['cost_cap'];
            if ($v === null || $v === '') {
                $out['cost_cap'] = null;
            } elseif (is_numeric($v) && (float) $v >= 0) {
                $out['cost_cap'] = (float) $v;
            }
        }
        if (isset($in['provider']) && in_array($in['provider'], self::PROVIDERS, true)) {
            $out['provider'] = $in['provider'];
        }
        if (isset($in['base_url']) && is_string($in['base_url'])) {
            $url = trim($in['base_url']);
            $out['base_url'] = ($url === '' || preg_match('#^https?://[^\s<>"]+$#i', $url) === 1) ? $url : $base['base_url'];
        }
        if (isset($in['models'])) {
            $models = is_array($in['models']) ? $in['models'] : preg_split('/[\s,]+/', (string) (is_scalar($in['models']) ? $in['models'] : ''));
            $clean = [];
            foreach ((array) $models as $m) {
                $m = is_string($m) ? trim($m) : '';
                if ($m !== '' && preg_match('#^[A-Za-z0-9._:/@+-]{1,120}$#', $m) === 1) {
                    $clean[] = $m;
                }
            }
            $out['models'] = array_slice(array_values(array_unique($clean)), 0, 3);
        }
        foreach (['script_check', 'debug'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /** The API key: constant, then environment, then option. Never printed. */
    public static function apiKey(): string
    {
        if (defined('TLDR_BLOCK_API_KEY') && is_string(constant('TLDR_BLOCK_API_KEY')) && constant('TLDR_BLOCK_API_KEY') !== '') {
            return (string) constant('TLDR_BLOCK_API_KEY');
        }
        $env = getenv('TLDR_BLOCK_API_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $opt = get_option(self::KEY_OPTION, '');

        return is_string($opt) ? $opt : '';
    }

    public static function apiKeySource(): string
    {
        if (defined('TLDR_BLOCK_API_KEY') && is_string(constant('TLDR_BLOCK_API_KEY')) && constant('TLDR_BLOCK_API_KEY') !== '') {
            return 'constant';
        }
        $env = getenv('TLDR_BLOCK_API_KEY');
        if (is_string($env) && $env !== '') {
            return 'environment';
        }

        return self::apiKey() === '' ? '' : 'option';
    }

    public static function saveApiKey(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            delete_option(self::KEY_OPTION);
            return;
        }
        update_option(self::KEY_OPTION, $key, false);
    }

    /** Last four characters of the key, for the settings screen. */
    public static function apiKeyHint(): string
    {
        $key = self::apiKey();

        return $key === '' ? '' : substr($key, -4);
    }

    public static function dailyCap(): int
    {
        if (defined('TLDR_BLOCK_DAILY_CAP') && is_numeric(constant('TLDR_BLOCK_DAILY_CAP'))) {
            return max(0, (int) constant('TLDR_BLOCK_DAILY_CAP'));
        }
        $cap = apply_filters('tldr_block_daily_cap', self::get()['daily_cap']);

        return max(0, is_numeric($cap) ? (int) $cap : 0);
    }

    public static function dailyCapLocked(): bool
    {
        return defined('TLDR_BLOCK_DAILY_CAP');
    }

    /** The kill switch is locked on by the constant. */
    public static function killSwitchLocked(): bool
    {
        return defined('TLDR_BLOCK_DISABLED') && (bool) constant('TLDR_BLOCK_DISABLED');
    }

    /** True when the kill switch is on (setting off or constant set). Fresh read. */
    public static function killSwitchOn(): bool
    {
        if (self::killSwitchLocked()) {
            return true;
        }

        return self::get()['generation'] !== 'on';
    }

    /**
     * A provider is configured when a caller is supplied by filter, or a
     * key and at least one model are set.
     */
    public static function providerConfigured(): bool
    {
        if (has_filter('tldr_block_client')) {
            return true;
        }
        $s = self::get();
        if ($s['provider'] === 'compatible' && $s['base_url'] === '') {
            return false;
        }

        return self::apiKey() !== '' && $s['models'] !== [];
    }

    public static function threshold(): float
    {
        $t = apply_filters('tldr_block_change_threshold', self::get()['threshold']);

        return is_numeric($t) ? max(0.01, min(0.90, (float) $t)) : 0.10;
    }

    public static function debounce(): int
    {
        $d = apply_filters('tldr_block_debounce_seconds', self::get()['debounce']);

        return is_numeric($d) ? max(0, min(3600, (int) $d)) : 300;
    }

    private static function onOff(mixed $v, string $fallback): string
    {
        if ($v === true || $v === 1 || $v === '1' || $v === 'on') {
            return 'on';
        }
        if ($v === false || $v === 0 || $v === '0' || $v === 'off') {
            return 'off';
        }

        return $fallback;
    }

    private static function plain(string $s): string
    {
        return trim((string) preg_replace('/<[^>]*>/', '', $s));
    }
}
