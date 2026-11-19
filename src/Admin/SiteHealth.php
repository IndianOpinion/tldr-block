<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Admin;

use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Settings;

/**
 * One Site Health test (PRD case 70).
 */
final class SiteHealth
{
    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function register(array $tests): array
    {
        $tests['direct']['tldr_block'] = [
            'label' => __('Summaries', 'tldr-block'),
            'test' => [self::class, 'test'],
        ];

        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string, problems: list<string>}
     */
    public static function test(): array
    {
        $problems = [];
        $notes = [];
        if (!Settings::providerConfigured()) {
            $problems[] = __('No model provider is configured, so no summaries are generated.', 'tldr-block');
        } else {
            $notes[] = __('A model provider is configured.', 'tldr-block');
        }
        if (Settings::killSwitchOn()) {
            $problems[] = Settings::killSwitchLocked()
                ? __('Generation is locked off by TLDR_BLOCK_DISABLED.', 'tldr-block')
                : __('Generation is paused by the kill switch.', 'tldr-block');
        }
        $overdue = self::overdueJobs();
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && $overdue > 0) {
            $problems[] = __('WP-Cron is disabled and jobs are overdue, so no system cron seems to run it. Add a system cron for wp cron event run --due-now or wp tldr-block run.', 'tldr-block');
        }
        if ($overdue > 0) {
            $problems[] = sprintf(__('%d summary jobs are overdue by more than an hour.', 'tldr-block'), $overdue);
        }
        $errors = Logger::recentErrors();
        if ($errors !== []) {
            $notes[] = sprintf(__('Last error: %s', 'tldr-block'), $errors[0]['message']);
        }
        $good = $problems === [];
        $description = '';
        foreach (array_merge($problems, $notes) as $line) {
            $description .= '<p>' . esc_html($line) . '</p>';
        }

        return [
            'label' => $good ? __('Summaries are working', 'tldr-block') : __('Summaries need attention', 'tldr-block'),
            'status' => $good ? 'good' : 'recommended',
            'badge' => ['label' => __('Summaries', 'tldr-block'), 'color' => $good ? 'green' : 'orange'],
            'description' => $description,
            'actions' => '',
            'test' => 'tldr_block',
            'problems' => $problems,
        ];
    }

    public static function overdueJobs(): int
    {
        $count = 0;
        $crons = _get_cron_array();
        foreach (is_array($crons) ? $crons : [] as $ts => $hooks) {
            if ((int) $ts < Clock::now() - 3600 && is_array($hooks) && isset($hooks[Queue::EVENT])) {
                $count += is_array($hooks[Queue::EVENT]) ? count($hooks[Queue::EVENT]) : 1;
            }
        }

        return $count;
    }
}
