<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

/**
 * Soft integration with prompt-library (PRD case 44).
 */
final class PromptLibrary
{
    public const SLUG = 'tldr-block/summary';
    public const DEFAULTS_FILTER = 'prompt_library_defaults';
    public const RENDER_FILTER = 'prompt_library_render';

    public static function present(): bool
    {
        return has_filter(self::RENDER_FILTER) !== false;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function registerDefault(array $defaults): array
    {
        $tpl = \IndianOpinion\TldrBlock\Prompt::builtinTemplate();
        $defaults[self::SLUG] = [
            'system' => $tpl['system'],
            'user' => $tpl['user'],
            'variables' => ['title', 'body', 'language', 'format', 'points_min', 'points_max', 'max_words'],
            'version' => 1,
        ];

        return $defaults;
    }

    /**
     * Render the template. Returns null when absent; throws when the
     * library answered with an error or an unusable shape.
     *
     * @param array<string, string|int> $vars
     * @return array{system: string, user: string, ref: string}|null
     */
    public static function render(array $vars): ?array
    {
        if (!self::present()) {
            return null;
        }
        $out = apply_filters(self::RENDER_FILTER, null, self::SLUG, $vars);
        if (is_wp_error($out)) {
            throw new \RuntimeException($out->get_error_message());
        }
        if (!is_array($out) || !is_string($out['system'] ?? null) || !is_string($out['user'] ?? null) || $out['user'] === '') {
            throw new \RuntimeException('prompt-library returned no usable template');
        }
        $version = is_scalar($out['version'] ?? null) ? (string) $out['version'] : '0';

        return ['system' => $out['system'], 'user' => $out['user'], 'ref' => self::SLUG . '@' . $version];
    }
}
