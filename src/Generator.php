<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Integrations\SpendMeter;
use IndianOpinion\TldrBlock\Llm\CallFailed;
use IndianOpinion\TldrBlock\Llm\CallRequest;
use IndianOpinion\TldrBlock\Llm\CallResult;
use IndianOpinion\TldrBlock\Llm\ClientFactory;
use IndianOpinion\TldrBlock\Text\Figures;
use IndianOpinion\TldrBlock\Text\Fingerprint;
use IndianOpinion\TldrBlock\Text\Language;
use IndianOpinion\TldrBlock\Text\SourceText;
use IndianOpinion\TldrBlock\Text\Validator;

/**
 * The job handler for both queue backends (PRD cases 26 to 44).
 */
final class Generator
{
    public const LEASE_SECONDS = 600;
    public const RETRY_DELAYS = [300, 1800, 7200];
    public const HOLD_SECONDS = 3600;
    public const TICK_JOBS = 3;
    public const TICK_SECONDS = 60;

    private static int $tickJobs = 0;
    private static ?int $tickStart = null;

    /** Reset the per-request tick budget (tests, CLI). */
    public static function resetTick(): void
    {
        self::$tickJobs = 0;
        self::$tickStart = null;
    }

    /**
     * WP-Cron entry point: honours the tick budget of three jobs or sixty
     * seconds; the rest move to the next tick.
     */
    public static function cronRun(int $postId): void
    {
        self::$tickStart ??= Clock::now();
        if (self::$tickJobs >= self::TICK_JOBS || Clock::now() - self::$tickStart >= self::TICK_SECONDS) {
            Queue::schedule($postId, Clock::now() + 60, 'retry');
            return;
        }
        self::$tickJobs++;
        self::run($postId);
    }

    /**
     * Run one job. Returns the outcome: generated, skipped, deferred,
     * failed, retry, discarded, busy.
     */
    public static function run(int $postId): string
    {
        $post = get_post($postId);
        $job = PostState::job($postId);
        if (!$post instanceof \WP_Post) {
            return 'skipped';
        }
        // The cron event that called us is already gone; a CLI or gen-queue
        // run removes the WP-Cron copy so the job does not run twice.
        wp_clear_scheduled_hook(Queue::EVENT, [$postId]);
        PostState::patchJob($postId, ['next_run' => null]);

        $reason = Eligibility::reason($postId, ['manual' => !empty($job['manual']), 'force' => !empty($job['force'])]);
        if ($reason !== '') {
            Logger::log('info', 'skipped', ['post_id' => $postId, 'reason' => $reason]);
            PostState::setStatus($postId, $reason === 'short' ? 'short' : Queue::restingStatus($postId));
            return 'skipped';
        }
        if (Counters::held()) {
            Queue::schedule($postId, Counters::holdUntil(), 'retry');
            Logger::log('info', 'held after credit exhaustion', ['post_id' => $postId], 'credit_hold_wait');
            return 'deferred';
        }
        $now = Clock::now();
        if ((int) ($job['lease_until'] ?? 0) > $now) {
            Logger::log('info', 'skipped: lease held by another runner', ['post_id' => $postId]);
            return 'busy';
        }
        PostState::patchJob($postId, ['lease_until' => $now + self::LEASE_SECONDS]);
        PostState::setStatus($postId, 'generating');

        $settings = Settings::get();
        $day = Clock::day();
        $dayCount = ($job['day'] ?? '') === $day ? (int) ($job['day_count'] ?? 0) : 0;
        if ($dayCount >= $settings['post_limit']) {
            return self::defer($postId, 'post_limit');
        }
        $refusal = Counters::reserve();
        if ($refusal === 'lock') {
            PostState::patchJob($postId, ['lease_until' => 0]);
            Logger::log('error', 'counter lock busy, job skipped', ['post_id' => $postId], 'lock');
            Queue::schedule($postId, Clock::now() + 60, 'retry');
            return 'busy';
        }
        if ($refusal !== '') {
            return self::defer($postId, $refusal);
        }
        $desc = ClientFactory::describe();
        $meter = SpendMeter::preSpend($desc['provider'], $desc['model']);
        if ($meter !== null && !$meter['allowed']) {
            Counters::refund();
            if ($meter['reservation_id'] !== null) {
                SpendMeter::release($meter['reservation_id']);
            }
            return self::defer($postId, $meter['reason']);
        }
        PostState::patchJob($postId, ['day' => $day, 'day_count' => $dayCount + 1]);

        $caller = ClientFactory::caller();
        $full = Eligibility::sourceText($post);
        $startFp = Fingerprint::of($full);
        [$body, $truncated] = SourceText::truncate($full, $settings['input_limit']);
        $lang = self::language($postId);
        $format = $settings['format'];
        $maxWords = $format === 'paragraph' ? $settings['paragraph_words'] : $settings['point_words'];
        $vars = [
            'title' => wp_strip_all_tags((string) $post->post_title),
            'body' => $body,
            'language' => $lang['name'],
            'format' => $format,
            'points_min' => $settings['points_min'],
            'points_max' => $settings['points_max'],
            'max_words' => $maxWords,
        ];
        $prompt = Prompt::build($vars);
        $request = new CallRequest(
            'tldr-block.summary',
            $prompt['system'],
            $prompt['user'],
            Prompt::schema($format),
            $format,
            0.2,
            800,
            90.0,
            Language::scriptFor($lang['code'])
        );

        $started = microtime(true);
        try {
            if ($caller === null) {
                throw new CallFailed('no_provider', 'no caller could be built');
            }
            $result = $caller->call($request);
        } catch (CallFailed $e) {
            Counters::addCost($e->cost);
            if ($meter !== null) {
                SpendMeter::record($meter['reservation_id'], ['status' => 'error', 'provider' => $desc['provider'], 'model' => $desc['model'], 'tokens_in' => null, 'tokens_out' => null, 'cost' => $e->cost]);
            }
            return self::fail($postId, $e->errorClass, $e->getMessage(), $e->creditExhausted);
        } catch (\Throwable $e) {
            if ($meter !== null) {
                SpendMeter::record($meter['reservation_id'], ['status' => 'error', 'provider' => $desc['provider'], 'model' => $desc['model'], 'tokens_in' => null, 'tokens_out' => null, 'cost' => null]);
            }
            return self::fail($postId, 'exception', $e->getMessage(), false);
        }
        Counters::addCost($result->cost);
        if ($meter !== null) {
            SpendMeter::record($meter['reservation_id'], [
                'status' => 'ok', 'provider' => $result->provider, 'model' => $result->model,
                'tokens_in' => $result->promptTokens, 'tokens_out' => $result->completionTokens, 'cost' => $result->cost,
            ]);
        }
        Logger::log('debug', 'call finished', [
            'post_id' => $postId, 'model' => $result->model, 'tokens_in' => $result->promptTokens,
            'tokens_out' => $result->completionTokens, 'ms' => $result->durationMs ?: (int) ((microtime(true) - $started) * 1000),
        ]);

        $check = Validator::check($result->data, [
            'format' => $format,
            'points_min' => $settings['points_min'],
            'points_max' => $settings['points_max'],
            'max_words' => $settings['point_words'],
            'paragraph_words' => $settings['paragraph_words'],
            'language' => $lang['code'],
            'script_check' => $settings['script_check'],
        ]);
        $extra = apply_filters('tldr_block_validate', [], $result->data, ['post_id' => $postId, 'format' => $format, 'language' => $lang['code']]);
        $errors = array_merge($check['errors'], is_array($extra) ? array_values(array_filter($extra, 'is_string')) : []);
        if ($errors !== []) {
            return self::fail($postId, 'validation', implode(',', $errors), false);
        }

        // Case 40: the post may have changed while the model was working.
        $nowPost = get_post($postId);
        if ($nowPost instanceof \WP_Post) {
            $nowFp = Fingerprint::of(Eligibility::sourceText($nowPost));
            if ($nowFp->isMaterialChangeFrom($startFp, Settings::threshold())) {
                PostState::patchJob($postId, ['lease_until' => 0]);
                Logger::log('info', 'result discarded: post changed during the call', ['post_id' => $postId]);
                if (wp_next_scheduled(Queue::EVENT, [$postId]) === false) {
                    PostState::setBasis($postId, $nowFp);
                    Queue::schedule($postId, Clock::now() + Settings::debounce(), 'save');
                } else {
                    PostState::setStatus($postId, 'queued');
                }
                return 'discarded';
            }
        }

        $parts = $format === 'paragraph' ? [$check['text']] : $check['points'];
        $warnings = $check['warnings'];
        if ($truncated) {
            $warnings[] = 'truncated';
        }
        $unverified = Figures::unverified($parts, $full);
        if ($unverified !== []) {
            $warnings[] = 'unverified_figures';
        }
        $candidate = [
            'id' => wp_generate_uuid4(),
            'format' => $format,
            'points' => $check['points'],
            'text' => $check['text'],
            'language' => $lang['code'],
            'model' => $result->model,
            'provider' => $result->provider,
            'generated_at' => Clock::now(),
            'source_hash' => $startFp->hash(),
            'source_sketch' => $startFp->packedSketch(),
            'truncated' => $truncated,
            'warnings' => array_values(array_unique($warnings)),
            'unverified_figures' => $unverified,
            'prompt_ref' => $prompt['ref'],
        ];
        PostState::setCandidate($postId, $candidate);
        PostState::setBasis($postId, $startFp);
        PostState::patchJob($postId, ['attempts' => 0, 'lease_until' => 0, 'last_error_class' => '', 'last_error' => '', 'manual' => false, 'force' => false]);
        PostState::setStatus($postId, 'pending');
        Logger::log('info', 'generated', ['post_id' => $postId, 'model' => $result->model]);
        do_action('tldr_block_generated', $postId, $candidate);

        return 'generated';
    }

    /**
     * Target language: name for the prompt, code for markup and checks.
     *
     * @return array{name: string, code: string}
     */
    public static function language(int $postId): array
    {
        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        $code = Language::codeFromLocale($locale);
        $default = Language::nameForCode($code);
        $name = apply_filters('tldr_block_language', $default, $postId);
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $default;
        if ($name !== $default) {
            $code = Language::codeForName($name) ?? $code;
        }

        return ['name' => $name, 'code' => $code];
    }

    private static function defer(int $postId, string $reason): string
    {
        wp_clear_scheduled_hook(Queue::EVENT, [$postId]);
        PostState::patchJob($postId, ['lease_until' => 0, 'deferred_reason' => $reason, 'next_run' => null]);
        PostState::setStatus($postId, 'deferred');
        Queue::scheduleSweep();
        Logger::log('info', 'deferred', ['post_id' => $postId, 'reason' => $reason]);
        do_action('tldr_block_deferred', $postId, $reason);

        return 'deferred';
    }

    private static function fail(int $postId, string $class, string $message, bool $credit): string
    {
        $job = PostState::job($postId);
        $attempt = (int) ($job['attempts'] ?? 0) + 1;
        $message = mb_substr($message, 0, 300, 'UTF-8');
        PostState::patchJob($postId, [
            'attempts' => $attempt,
            'lease_until' => 0,
            'last_error_class' => $class,
            'last_error' => $message,
        ]);
        Logger::log('error', 'failed', ['post_id' => $postId, 'class' => $class, 'attempt' => $attempt], 'failed_' . $class . '_' . $postId);
        do_action('tldr_block_failed', $postId, $class, $message, $attempt);

        if ($credit) {
            Counters::setHold(Clock::now() + self::HOLD_SECONDS);
            Logger::log('error', 'provider credit exhausted: generation held for one hour', [], 'credit_exhausted');
            PostState::setStatus($postId, 'failed');
            return 'failed';
        }
        if ($attempt <= count(self::RETRY_DELAYS)) {
            Queue::schedule($postId, Clock::now() + self::RETRY_DELAYS[$attempt - 1], 'retry');
            return 'retry';
        }
        PostState::setStatus($postId, 'failed');

        return 'failed';
    }
}
