<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Integrations;

use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\Prompt;

/**
 * Soft integration with prompt-library (PRD case 44), as published in
 * prompt-library 0.1.0:
 *
 * - `apply_filters('promptlib_render', null, $slug, $values, 'live',
 *   $options)` returns null when the plugin is absent, a rendered array
 *   (`system`, `user`, `version`, ...) or a WP_Error;
 * - `promptlib_register_default($consumer, $definition)` installs the
 *   built-in template as version 1 when the slug is free;
 * - `promptlib_register_consumer($slug, $consumer, $names)` declares the
 *   variables this plugin supplies.
 *
 * Slugs there match `^[a-z0-9]+(?:[-_][a-z0-9]+)*$`, so the slug is
 * `tldr-block-summary`.
 */
final class PromptLibrary
{
    public const SLUG = 'tldr-block-summary';
    public const CONSUMER = 'tldr-block';
    public const RENDER_FILTER = 'promptlib_render';
    public const VARIABLES = ['title', 'body', 'language', 'format', 'format_instruction', 'points_min', 'points_max', 'max_words'];

    public static function present(): bool
    {
        return has_filter(self::RENDER_FILTER) !== false;
    }

    /** Hooked to init: ship the default and declare the variables. */
    public static function register(): void
    {
        try {
            if (function_exists('promptlib_register_default')) {
                promptlib_register_default(self::CONSUMER, self::definition());
            }
            if (function_exists('promptlib_register_consumer')) {
                promptlib_register_consumer(self::SLUG, self::CONSUMER, self::VARIABLES);
            }
        } catch (\Throwable $e) {
            Logger::log('warning', 'prompt-library registration failed', ['error' => $e->getMessage()], 'prompt_library');
        }
    }

    /**
     * The built-in template as a prompt-library definition.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $tpl = Prompt::builtinTemplate();

        return [
            'format' => 'prompt-library/1',
            'slug' => self::SLUG,
            'title' => 'tldr-block summary',
            'description' => 'Short summary of a long post, held for editor approval.',
            'system' => $tpl['system'],
            'user' => $tpl['user'],
            'variables' => [
                ['name' => 'title', 'type' => 'string', 'escape' => 'line', 'description' => 'Post title'],
                ['name' => 'body', 'type' => 'text', 'escape' => 'plain', 'max_length' => 200000, 'description' => 'Post text, delimiters removed'],
                ['name' => 'language', 'type' => 'string', 'escape' => 'line', 'description' => 'Target language name'],
                ['name' => 'format', 'type' => 'enum', 'enum' => ['bullets', 'paragraph'], 'description' => 'Summary format'],
                ['name' => 'format_instruction', 'type' => 'string', 'escape' => 'line', 'max_length' => 500, 'description' => 'Count, length and JSON shape for the format'],
                ['name' => 'points_min', 'type' => 'integer', 'description' => 'Fewest points'],
                ['name' => 'points_max', 'type' => 'integer', 'description' => 'Most points'],
                ['name' => 'max_words', 'type' => 'integer', 'description' => 'Word limit per point or paragraph'],
            ],
            'output' => ['mode' => 'text'],
            'settings' => ['temperature' => 0.2, 'token_cap' => 800],
            'note' => 'Installed by tldr-block.',
        ];
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
        $out = apply_filters(self::RENDER_FILTER, null, self::SLUG, $vars, 'live', [
            'consumer' => self::CONSUMER,
            'fallback' => self::definition(),
        ]);
        if ($out === null) {
            return null;
        }
        if (is_wp_error($out)) {
            throw new \RuntimeException($out->get_error_code() . ': ' . $out->get_error_message());
        }
        if (!is_array($out) || !is_string($out['system'] ?? null) || !is_string($out['user'] ?? null) || $out['user'] === '') {
            throw new \RuntimeException('prompt-library returned no usable template');
        }
        $version = is_scalar($out['version'] ?? null) ? (string) $out['version'] : '0';

        return ['system' => $out['system'], 'user' => $out['user'], 'ref' => self::SLUG . '@' . $version];
    }
}
