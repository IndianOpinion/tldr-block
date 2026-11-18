<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use IndianOpinion\TldrBlock\Settings;

/**
 * Finds the caller: the tldr_block_client filter first (a Caller, or an
 * llm-json-php Client), then one built from settings.
 */
final class ClientFactory
{
    public const STORE_PREFIX = 'tldr_block_llm';

    public static function caller(): ?Caller
    {
        $settings = Settings::get();
        $supplied = apply_filters('tldr_block_client', null, $settings);
        if ($supplied instanceof Caller) {
            return $supplied;
        }
        if (class_exists(Client::class) && $supplied instanceof Client) {
            return new LlmJsonCaller($supplied);
        }
        if (!class_exists(Client::class)) {
            return null;
        }
        $key = Settings::apiKey();
        if ($key === '' || $settings['models'] === []) {
            return null;
        }
        try {
            return new LlmJsonCaller(self::build($settings, $key));
        } catch (\Throwable $e) {
            \IndianOpinion\TldrBlock\Logger::log('error', 'client configuration failed', ['error' => $e->getMessage()], 'client_config');

            return null;
        }
    }

    /**
     * @param array{provider: string, base_url: string, models: list<string>} $settings
     */
    private static function build(array $settings, string $key): Client
    {
        $provider = match ($settings['provider']) {
            'openai' => Provider::openAi($key),
            'compatible' => Provider::generic('compatible', $settings['base_url'], $key),
            default => Provider::openRouter($key, function_exists('home_url') ? home_url('/') : null, 'tldr-block'),
        };
        $routes = [];
        foreach ($settings['models'] as $model) {
            $routes[] = Route::to($provider, $model);
        }

        return Client::create(Chain::of(...$routes), new WordPressTransport())
            ->withOptions(Options::defaults()->withStoreKeyPrefix(self::STORE_PREFIX)->withDeadline(90))
            ->withStore(new WordPressTransientStore());
    }

    /**
     * Provider name and first model, for spend records and logs.
     *
     * @return array{provider: string, model: string}
     */
    public static function describe(): array
    {
        $s = Settings::get();

        return ['provider' => $s['provider'], 'model' => $s['models'][0] ?? ''];
    }
}
