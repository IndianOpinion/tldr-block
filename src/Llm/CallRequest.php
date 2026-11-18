<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

/**
 * One summary call: messages, schema and limits.
 */
final class CallRequest
{
    /**
     * @param array<string, mixed> $schema JSON schema with an object root
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $system,
        public readonly string $user,
        public readonly array $schema,
        public readonly string $format,
        public readonly float $temperature = 0.2,
        public readonly int $maxTokens = 800,
        public readonly float $deadline = 90.0,
        public readonly ?string $script = null
    ) {
    }
}
