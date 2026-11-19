<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

/**
 * A model call failed. `errorClass` is a short machine name
 * (chain_exhausted, timeout, validation, credit_exhausted, configuration,
 * ...). `creditExhausted` skips retries and starts the one-hour hold.
 */
final class CallFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $errorClass,
        string $message,
        public readonly bool $creditExhausted = false,
        public readonly ?float $cost = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
