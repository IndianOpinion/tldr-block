<?php

/**
 * Plugin Name: tldr-block custom caller example
 * Description: Runs tldr-block without llm-json-php by supplying a caller
 * of your own. This one calls a local OpenAI-compatible server with the
 * WordPress HTTP API.
 */

declare(strict_types=1);

use IndianOpinion\TldrBlock\Llm\CallFailed;
use IndianOpinion\TldrBlock\Llm\CallRequest;
use IndianOpinion\TldrBlock\Llm\CallResult;
use IndianOpinion\TldrBlock\Llm\Caller;

add_action('plugins_loaded', static function (): void {
    if (!interface_exists(Caller::class)) {
        return;
    }

    $caller = new class implements Caller {
        public function call(CallRequest $request): CallResult
        {
            $response = wp_remote_post('http://127.0.0.1:8080/v1/chat/completions', [
                'timeout' => $request->deadline,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => (string) wp_json_encode([
                    'model' => 'local-model',
                    'temperature' => $request->temperature,
                    'max_tokens' => $request->maxTokens,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $request->system],
                        ['role' => 'user', 'content' => $request->user],
                    ],
                ]),
            ]);
            if (is_wp_error($response)) {
                throw new CallFailed('transport', $response->get_error_message());
            }
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $text = is_array($body) ? ($body['choices'][0]['message']['content'] ?? null) : null;
            $data = is_string($text) ? json_decode($text, true) : null;
            if (!is_array($data)) {
                throw new CallFailed('malformed', 'The server did not return a JSON object.');
            }

            return new CallResult($data, 'local', 'local-model');
        }
    };

    add_filter('tldr_block_client', static fn () => $caller);
});
