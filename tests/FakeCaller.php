<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests;

use IndianOpinion\TldrBlock\Llm\CallFailed;
use IndianOpinion\TldrBlock\Llm\CallRequest;
use IndianOpinion\TldrBlock\Llm\CallResult;
use IndianOpinion\TldrBlock\Llm\Caller;

/**
 * Scripted caller. Each queued response is an array (data), a CallFailed,
 * or a callable taking the request. With nothing queued it answers with
 * three valid points.
 */
final class FakeCaller implements Caller
{
    /** @var list<CallRequest> */
    public array $requests = [];
    /** @var list<mixed> */
    public array $queue = [];
    public ?float $cost = null;

    public function call(CallRequest $request): CallResult
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? ($request->format === 'paragraph'
            ? ['summary' => 'The council approved the river budget after a long debate.']
            : ['points' => ['The council approved the river budget.', 'Farmers asked for more water before the monsoon.', 'The new bridge opens next season.']]);
        if (is_callable($next)) {
            $next = $next($request);
        }
        if ($next instanceof CallFailed) {
            throw $next;
        }

        return new CallResult((array) $next, 'fake', 'fake/model-1', 100, 40, $this->cost);
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
