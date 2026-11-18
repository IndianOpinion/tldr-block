<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

/**
 * Validated data from a call, plus what it cost when the provider said.
 */
final class CallResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?float $cost = null,
        public readonly int $durationMs = 0
    ) {
    }
}
