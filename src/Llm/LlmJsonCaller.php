<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Llm;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Schema;

/**
 * Adapter from the Caller interface to an llm-json-php Client.
 */
final class LlmJsonCaller implements Caller
{
    public function __construct(private readonly Client $client)
    {
    }

    public function call(CallRequest $request): CallResult
    {
        try {
            $schema = Schema::fromArray(str_replace(['.', '-'], '_', $request->purpose), $request->schema);
            $path = $request->format === 'paragraph' ? '/summary' : '/points/*';
            $profile = GuardProfile::forSchema($schema)
                ->withInvisibleCharacters(Action::Reject)
                ->withControlCharacters(Action::Reject, [$path])
                ->withSentenceEnd([$path], Action::Reject);
            $req = Request::for($request->purpose, $schema)
                ->withMessages([
                    ['role' => 'system', 'content' => $request->system],
                    ['role' => 'user', 'content' => $request->user],
                ])
                ->withTemperature($request->temperature)
                ->withTokenCap($request->maxTokens)
                ->withDeadline($request->deadline)
                ->withGuardProfile($profile);
            $result = $this->client->complete($req);
        } catch (CreditExhaustedException $e) {
            throw new CallFailed('credit_exhausted', $e->getMessage(), true, null, $e);
        } catch (ChainExhaustedException $e) {
            throw new CallFailed($e->isDeadline() ? 'timeout' : 'chain_exhausted', $e->getMessage(), false, null, $e);
        } catch (ConfigurationException $e) {
            throw new CallFailed('configuration', $e->getMessage(), false, null, $e);
        } catch (LlmJsonException $e) {
            $short = (new \ReflectionClass($e))->getShortName();
            $class = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Exception', '', $short)));
            throw new CallFailed($class === '' ? 'llm_error' : $class, $e->getMessage(), false, null, $e);
        }
        $usage = $result->totalUsage();

        return new CallResult(
            $result->data(),
            $result->provider(),
            $result->model(),
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->cost,
            $result->durationMs()
        );
    }
}
