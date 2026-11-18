<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\Admin\SettingsPage;
use IndianOpinion\TldrBlock\Admin\SiteHealth;
use IndianOpinion\TldrBlock\Admin\TermFields;
use IndianOpinion\TldrBlock\Cli\Command;
use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Counters;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Llm\CallFailed;
use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\Modes;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;
use IndianOpinion\TldrBlock\Tests\TestCase;
use IndianOpinion\TldrBlock\Uninstaller;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class AdminTest extends TestCase
{
    /** T66 (case 66). */
    public function testBackfill(): void
    {
        Settings::update(['daily_cap' => 2]);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->post(800, [], [], 50 + $i);
        }
        $done = $this->post(800, [], [], 60);
        $this->approved($done);
        $short = $this->post(200);
        // The approval above used one unit today: one is left.
        WpStub::$cron = [];
        WpStub::$postmeta[1] = array_intersect_key(WpStub::$postmeta[1], [$done => true]);

        $cmd = new Command();
        $cmd->backfill([], ['dry-run' => true]);
        self::assertSame([], WpStub::events(Queue::EVENT));
        self::assertStringContainsString('5 posts would be queued; 1 fit today', implode("\n", \WP_CLI::$lines));

        \WP_CLI::$lines = [];
        $cmd->backfill([], ['dry-run' => true, 'spread' => true]);
        self::assertStringContainsString('estimated 3 day(s)', implode("\n", \WP_CLI::$lines));

        $cmd->backfill([], []);
        self::assertCount(1, WpStub::events(Queue::EVENT), 'never more than the remaining cap');
        self::assertSame('backfill', WpStub::fired('tldr_block_queued')[count(WpStub::fired('tldr_block_queued')) - 1][1]);

        $cmd->backfill([], ['spread' => true]);
        $events = WpStub::events(Queue::EVENT);
        self::assertCount(5, $events);
        $midnight = Clock::nextMidnight();
        $later = array_filter($events, fn ($e) => $e['ts'] >= $midnight);
        // One more fits today's remaining unit; three go to the next two days.
        self::assertCount(3, $later);
        $day2 = array_filter($events, fn ($e) => $e['ts'] >= $midnight + 86400);
        self::assertCount(1, $day2);
        foreach ($events as $e) {
            self::assertNotContains($e['args'][0], [$done, $short]);
        }
        self::assertNull(PostState::live($ids[0]), 'backfill never approves');
    }

    public function testBackfillLimitAndCategory(): void
    {
        $cat = WpStub::addTerm('Sport');
        $in = $this->post(800, [], [$cat], 1);
        $this->post(800, [], [], 2);
        $cmd = new Command();
        $cmd->backfill([], ['category' => 'sport']);
        self::assertSame([$in], array_map(fn ($e) => $e['args'][0], WpStub::events(Queue::EVENT)));
        WpStub::$cron = [];
        foreach (array_keys(WpStub::$postmeta[1] ?? []) as $pid) {
            WpStub::$postmeta[1][$pid] = [];
        }
        $cmd->backfill([], ['limit' => '1']);
        self::assertCount(1, WpStub::events(Queue::EVENT));
    }

    /** T67 (case 67). */
    public function testCliCommands(): void
    {
        $cmd = new Command();
        $id = $this->post(800);
        $cmd->generate([(string) $id], []);
        self::assertStringContainsString('Queued post ' . $id, end(\WP_CLI::$lines));
        $cmd->run([], ['max' => '5']);
        self::assertContains($id . ' generated', \WP_CLI::$lines);
        self::assertSame('pending', PostState::status($id));

        \WP_CLI::$lines = [];
        $cmd->status([], []);
        $out = implode("\n", \WP_CLI::$lines);
        self::assertStringContainsString('generations: 1 / 50', $out);
        self::assertStringContainsString('cost: not reported', $out);
        self::assertStringContainsString('pending=1', $out);
        self::assertStringContainsString('queue length: 0', $out);

        \WP_CLI::$lines = [];
        $cmd->status([(string) $id], []);
        self::assertStringContainsString('"status": "pending"', implode("\n", \WP_CLI::$lines));

        $cmd->pause();
        self::assertTrue(Settings::killSwitchOn());
        try {
            $cmd->generate([(string) $id], []);
            self::fail('expected an error');
        } catch (\WP_CLI_Stop $e) {
            self::assertStringContainsString('kill_switch', $e->getMessage());
        }
        $cmd->resume();
        self::assertFalse(Settings::killSwitchOn());

        // run --max honours the limit and only due jobs.
        $a = $this->post(800, [], [], 2);
        $b = $this->post(800, [], [], 3);
        $this->save($a);
        $this->save($b);
        \WP_CLI::$lines = [];
        $cmd->run([], []);
        self::assertStringContainsString('Ran 0 job(s)', end(\WP_CLI::$lines));
        Clock::freeze(self::NOW + 301);
        $cmd->run([], ['max' => '1']);
        self::assertStringContainsString('Ran 1 job(s)', end(\WP_CLI::$lines));

        try {
            $cmd->status(['99999'], []);
            self::fail('expected an error');
        } catch (\WP_CLI_Stop $e) {
            self::assertSame('No such post.', $e->getMessage());
        }
    }

    /** T67: --force from the CLI. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliForce(): void
    {
        define('WP_CLI', true);
        $short = $this->post(200);
        (new Command())->generate([(string) $short], ['force' => true]);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$short]));
    }

    /** T68 (case 68). */
    public function testSettingsScreenKeyAndTestCall(): void
    {
        Settings::saveApiKey('sk-live-SECRETVALUE-9876');
        $html = SettingsPage::html();
        self::assertStringNotContainsString('SECRETVALUE', $html);
        self::assertStringContainsString('set, ends in 9876', $html);
        self::assertStringContainsString('name="tldr_block_api_key" value=""', $html);
        self::assertStringContainsString('counts against the daily cap', $html);

        $r = SettingsPage::testConnection();
        self::assertTrue($r['ok']);
        self::assertStringContainsString('counted against the daily cap', $r['message']);
        self::assertSame(1, Counters::today()['count']);
        self::assertSame('tldr-block.test', $this->caller->requests[0]->purpose);
        $this->caller->queue[] = new CallFailed('authentication', 'bad key');
        $r2 = SettingsPage::testConnection();
        self::assertFalse($r2['ok']);
        self::assertSame(2, Counters::today()['count']);
        self::assertStringNotContainsString('SECRETVALUE', implode("\n", $this->logLines));

        // Saving: unchecked boxes clear lists; blank key keeps the old one; clear removes it.
        SettingsPage::save(['min_words' => '700', 'models' => 'a/b, c/d', 'heading' => '<b>Key</b> points'], '', false);
        $s = Settings::get();
        self::assertSame(700, $s['min_words']);
        self::assertSame(['a/b', 'c/d'], $s['models']);
        self::assertSame('Key points', $s['heading']);
        self::assertSame([], $s['post_types']);
        self::assertSame('sk-live-SECRETVALUE-9876', Settings::apiKey());
        SettingsPage::save(['post_types' => ['post']], 'new-key-1111', false);
        self::assertSame('new-key-1111', Settings::apiKey());
        SettingsPage::save(['post_types' => ['post']], '', true);
        self::assertSame('', Settings::apiKey());
    }

    public function testSettingsSanitising(): void
    {
        $s = Settings::update([
            'min_words' => 5, 'threshold' => 3, 'debounce' => -1, 'daily_cap' => 99999, 'points_min' => 6, 'points_max' => 2,
            'format' => 'poem', 'placement' => 'bottom', 'cost_cap' => 'abc', 'base_url' => 'javascript:alert(1)',
            'statuses' => ['publish', 'trash'], 'post_types' => ['post', 'bad type!'], 'heading' => str_repeat('h', 80),
        ]);
        self::assertSame(100, $s['min_words']);
        self::assertSame(0.9, $s['threshold']);
        self::assertSame(0, $s['debounce']);
        self::assertSame(10000, $s['daily_cap']);
        self::assertSame(6, $s['points_max']);
        self::assertSame('bullets', $s['format']);
        self::assertSame('top', $s['placement']);
        self::assertNull($s['cost_cap']);
        self::assertSame('', $s['base_url']);
        self::assertSame(['publish'], $s['statuses']);
        self::assertSame(['post'], $s['post_types']);
        self::assertSame(60, mb_strlen($s['heading']));
        add_filter('tldr_block_default_settings', fn ($d) => ['min_words' => 1234] + $d);
        self::assertSame(1234, Settings::defaults()['min_words']);
        add_filter('tldr_block_change_threshold', fn () => 0.25);
        self::assertSame(0.25, Settings::threshold());
    }

    /** API key from the environment. */
    public function testApiKeyFromEnvironment(): void
    {
        putenv('TLDR_BLOCK_API_KEY=env-key-4321');
        try {
            Settings::saveApiKey('option-key');
            self::assertSame('env-key-4321', Settings::apiKey());
            self::assertSame('environment', Settings::apiKeySource());
            self::assertStringContainsString('Set by the environment (ends in 4321)', SettingsPage::html());
        } finally {
            putenv('TLDR_BLOCK_API_KEY');
        }
    }

    /** T69 (case 69): the form handler with nonce and capability. */
    public function testCategoryFormSave(): void
    {
        $cat = WpStub::addTerm('Opinion');
        $_POST = ['_tldr_block_term_nonce' => 'nonce-tldr_block_term', 'tldr_block_mode' => 'off', 'tldr_block_min_words' => '800'];
        WpStub::user(3, ['edit_posts']);
        TermFields::save($cat);
        self::assertSame('inherit', Modes::termMode($cat), 'no manage_categories');
        WpStub::user(1, ['manage_categories']);
        TermFields::save($cat);
        self::assertSame('off', Modes::termMode($cat));
        self::assertSame(800, Modes::termMinWords($cat));
        self::assertSame([[$cat, 'category_switch']], WpStub::fired('tldr_block_display_changed'));
        $_POST['_tldr_block_term_nonce'] = 'forged';
        $_POST['tldr_block_mode'] = 'on';
        TermFields::save($cat);
        self::assertSame('off', Modes::termMode($cat));
        $_POST = [];
        $edit = TermFields::fields('off', 800, true);
        self::assertStringContainsString('value="off" selected="selected"', $edit);
        self::assertStringContainsString('value="800"', $edit);
    }

    /** T70 (case 70). */
    public function testSiteHealth(): void
    {
        $r = SiteHealth::test();
        self::assertSame('good', $r['status']);
        remove_all_filters('tldr_block_client');
        $r = SiteHealth::test();
        self::assertSame('recommended', $r['status']);
        self::assertStringContainsString('No model provider', $r['description']);
        add_filter('tldr_block_client', fn () => $this->caller);
        Settings::update(['generation' => 'off']);
        self::assertStringContainsString('paused by the kill switch', SiteHealth::test()['description']);
        Settings::update(['generation' => 'on']);
        wp_schedule_single_event(self::NOW - 7200, Queue::EVENT, [1]);
        self::assertStringContainsString('1 summary jobs are overdue', SiteHealth::test()['description']);
        Logger::log('error', 'something broke', ['post_id' => 4]);
        self::assertStringContainsString('Last error: something broke', SiteHealth::test()['description']);
        $tests = SiteHealth::register([]);
        self::assertArrayHasKey('tldr_block', $tests['direct']);
    }

    /** T70: WP-Cron disabled with overdue jobs. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSiteHealthCronDisabled(): void
    {
        define('DISABLE_WP_CRON', true);
        wp_schedule_single_event(self::NOW - 7200, Queue::EVENT, [1]);
        self::assertStringContainsString('WP-Cron is disabled', SiteHealth::test()['description']);
    }

    /** T71 (case 71). */
    public function testUninstallOnEachSite(): void
    {
        WpStub::$multisite = true;
        WpStub::$sites = [1, 2];
        foreach ([1, 2] as $blog) {
            switch_to_blog($blog);
            Settings::update(['min_words' => 700, 'default_mode' => 'on']);
            Settings::saveApiKey('k');
            $cat = WpStub::addTerm('C' . $blog);
            update_term_meta($cat, Modes::TERM_MODE, 'on');
            $id = $this->post(800, [], [], $blog);
            $this->approved($id);
            Counters::setHold(5);
            Logger::log('error', 'x');
            $this->save($this->post(800, [], [], 10 + $blog));
            Queue::scheduleSweep();
            restore_current_blog();
        }
        set_transient('tldr_block_throttle_abc', 1);
        set_transient('tldr_block_llm.cooldown', 1);
        $calls = $this->caller->count();
        Uninstaller::run();
        foreach ([1, 2] as $blog) {
            foreach (array_keys(WpStub::$options[$blog] ?? []) as $name) {
                self::assertStringNotContainsString('tldr_block', $name, "blog $blog");
            }
            foreach (WpStub::$postmeta[$blog] ?? [] as $meta) {
                foreach (array_keys($meta) as $k) {
                    self::assertStringNotContainsString('_tldr_block', $k);
                }
            }
            foreach (WpStub::$termmeta[$blog] ?? [] as $meta) {
                self::assertSame([], $meta);
            }
            self::assertSame([], array_filter(WpStub::$cron[$blog] ?? [], fn ($e) => str_starts_with($e['hook'], 'tldr_block')));
        }
        self::assertSame([], WpStub::$transients);
        self::assertSame($calls, $this->caller->count());
        self::assertSame(1, WpStub::$blog);
    }

    /** T72 (failure table). */
    public function testFailureTableEachRowLogsOnce(): void
    {
        // Provider down: retries then failed; the live summary is unchanged.
        Settings::update(['post_limit' => 20]);
        $id = $this->post(800);
        $this->approved($id);
        $live = PostState::live($id);
        Queue::manual($id);
        $this->caller->queue[] = new CallFailed('chain_exhausted', 'down');
        self::assertSame('retry', Generator::run($id));
        self::assertSame($live, PostState::live($id));
        self::assertCount(1, $this->logsMatching('[error] failed post_id=' . $id . ' class=chain_exhausted'));

        // Malformed output counts as a failed attempt.
        $this->caller->queue[] = ['nope' => true];
        Generator::run($id);
        self::assertCount(1, $this->logsMatching('class=validation'));

        // Lock held: job skipped, logged, retried next tick.
        add_option(Counters::LOCK, self::NOW + 30);
        $b = $this->post(800, [], [], 21);
        $this->save($b);
        self::assertSame('busy', Generator::run($b));
        self::assertCount(1, $this->logsMatching('counter lock busy'));
        self::assertSame(self::NOW + 60, wp_next_scheduled(Queue::EVENT, [$b]));
        // An expired lock is taken over.
        update_option(Counters::LOCK, self::NOW - 1);
        self::assertSame('generated', Generator::run($b));

        // Meta read fails on the front end: nothing printed.
        update_post_meta($id, PostState::LIVE, 'corrupt');
        self::assertSame('', $this->render($id));

        // spend-meter answering garbage is treated as absent (constant absent here).
        // Prompt-library failure: covered in GeneratorTest. Edit mid-call: covered in GeneratorTest.
        // Cap reached: one deferred line.
        Settings::update(['daily_cap' => 0]);
        $c = $this->post(800, [], [], 22);
        $this->saveAndRun($c);
        self::assertCount(1, $this->logsMatching('deferred post_id=' . $c . ' reason=daily_cap'));
        self::assertCount(1, $this->logsMatching('daily cap reached'));
        // Every line carries the prefix and never the post text.
        foreach ($this->logLines as $line) {
            self::assertStringStartsWith('tldr-block: ', $line);
            self::assertStringNotContainsString('river', $line);
        }
    }

    public function testPrivacyTextAndHooksRegistered(): void
    {
        \IndianOpinion\TldrBlock\Plugin::privacy();
        self::assertStringContainsString('third-party language model provider', WpStub::$privacy[0]);
        \IndianOpinion\TldrBlock\Plugin::rest();
        self::assertArrayHasKey('post:tldr_block', WpStub::$restFields);
        self::assertArrayHasKey('tldr-block/v1/posts/(?P<id>\d+)/approve', WpStub::$routes);
    }
}
