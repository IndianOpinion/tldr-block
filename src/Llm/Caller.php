<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

/**
 * Anything that can make one structured model call. The plugin ships an
 * adapter for indianopinion/llm-json-php; a site can supply its own caller
 * through the tldr_block_client filter and run without that library.
 */
interface Caller
{
    /**
     * @throws CallFailed on any failure; never returns partial data
     */
    public function call(CallRequest $request): CallResult;
}
