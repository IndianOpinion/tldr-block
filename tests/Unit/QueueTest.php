<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Integrations\GenQueue;
use IndianOpinion\TldrBlock\Plugin;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;
use IndianOpinion\TldrBlock\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class QueueTest extends TestCase
{
    /** T11 (case 11) at the save level. */
    public function testTitleOnlyChangeQueuesNothing(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        WpStub::updatePost($id, ['post_title' => 'Entirely different headline words here']);
        $this->save($id);
        self::assertSame('approved', PostState::status($id));
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        self::assertFalse((bool) PostState::live($id)['stale']);
    }

    /** T13 (case 13) at the save level: small edits add up. */
    public function testSmallEditsAddUpAgainstGeneratedFrom(): void
    {
        $id = $this->post(1000, [], [], 3);
        $this->approved($id);
        $base = self::text(1000, 3);
        $content = $base;
        $queuedAt = null;
        for ($i = 0; $i < 10; $i++) {
            $content .= "\n\n" . self::text(18, 200 + $i);
            WpStub::updatePost($id, ['post_content' => self::html($content)]);
            $this->save($id);
            if ($queuedAt === null && wp_next_scheduled(Queue::EVENT, [$id]) !== false) {
                $queuedAt = $i;
            }
        }
        self::assertNotNull($queuedAt, 'the edits together are material');
        self::assertGreaterThan(0, $queuedAt, 'the first small edit alone is not');
        self::assertTrue((bool) PostState::live($id)['stale']);
    }

    /** T14 (case 14) at the save level. */
    public function testInsertingOwnBlockQueuesNothing(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        $content = (string) get_post($id)?->post_content;
        WpStub::updatePost($id, ['post_content' => '<!-- wp:tldr-block/summary /-->' . $content . '[tldr]']);
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        self::assertSame('approved', PostState::status($id));
    }

    /** T16 (case 16): autosave and revision. */
    public function testAutosaveAndRevisionQueueNothing(): void
    {
        $rev = $this->post(800, ['post_type' => 'revision']);
        $this->save($rev);
        $auto = $this->post(800, ['is_autosave' => true]);
        $this->save($auto);
        self::assertSame([], WpStub::events(Queue::EVENT));
    }

    /** T16 (case 16): imports. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportingQueuesNothing(): void
    {
        define('WP_IMPORTING', true);
        $id = $this->post(800);
        $this->save($id);
        self::assertSame([], WpStub::events(Queue::EVENT));
    }

    /** T16 (case 16): DOING_AUTOSAVE. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoingAutosaveQueuesNothing(): void
    {
        define('DOING_AUTOSAVE', true);
        $id = $this->post(800);
        $this->save($id);
        self::assertSame([], WpStub::events(Queue::EVENT));
    }

    /** T17 (case 17). */
    public function testBulkCategoryEditQueuesNothing(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        WpStub::$postCategories[$id] = [WpStub::addTerm('Another')];
        update_post_meta($id, 'unrelated', 'x');
        WpStub::updatePost($id, ['post_status' => 'publish']);
        $this->save($id);
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        self::assertSame(1, $this->caller->count());
    }

    /** T18 (case 18). */
    public function testDebounceKeepsOneJobFromTheLastSave(): void
    {
        $id = $this->post(800);
        for ($i = 0; $i < 3; $i++) {
            Clock::freeze(self::NOW + $i * 60);
            WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 10 + $i))]);
            $this->save($id);
        }
        $events = WpStub::events(Queue::EVENT);
        self::assertCount(1, $events);
        self::assertSame(self::NOW + 120 + 300, $events[0]['ts']);
        self::assertSame([$id], $events[0]['args']);
        self::assertSame(3, count(WpStub::fired('tldr_block_queued')));
    }

    /** T19 (case 19) and T58 for stale. */
    public function testMaterialChangeMarksStaleAndHides(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        self::assertNotSame('', $this->render($id));
        $before = count(WpStub::fired('tldr_block_display_changed'));
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 77))]);
        $this->save($id);
        self::assertTrue((bool) PostState::live($id)['stale']);
        self::assertSame('', $this->render($id));
        $fired = array_slice(WpStub::fired('tldr_block_display_changed'), $before);
        self::assertSame([[$id, 'stale']], $fired);
        self::assertCount(1, WpStub::fired('tldr_block_stale'));
        self::assertIsFloat(WpStub::fired('tldr_block_stale')[0][1]);
        self::assertContains('post:' . $id, WpStub::$cleaned);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$id]));
    }

    /** T20 (case 20). */
    public function testStaleKeepShowsUntilNewApproval(): void
    {
        Settings::update(['stale_display' => 'keep']);
        $id = $this->post(800);
        $this->approved($id);
        $old = PostState::live($id)['points'];
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 77))]);
        $this->caller->queue[] = ['points' => ['A new first point here.', 'A new second point here.', 'A new third point here.']];
        $this->saveAndRun($id);
        self::assertStringContainsString(esc_html($old[0]), $this->render($id));
        $c = PostState::candidate($id);
        \IndianOpinion\TldrBlock\Review::approve($id, (string) $c['id'], 2);
        $html = $this->render($id);
        self::assertStringContainsString('A new first point here.', $html);
    }

    /** T21 (case 21). */
    public function testDroppingBelowMinimumHidesAndRestores(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        WpStub::updatePost($id, ['post_content' => self::html(self::text(300))]);
        $this->save($id);
        self::assertSame('short', PostState::status($id));
        self::assertSame('', $this->render($id));
        self::assertNotNull(PostState::live($id));
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800))]);
        $this->save($id);
        self::assertNotSame('short', PostState::status($id));
        self::assertNotSame('', $this->render($id));
    }

    /** T22 (case 22). */
    public function testDraftAndTrashCancelAndRestoreKeepsData(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 50))]);
        $this->save($id);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        WpStub::updatePost($id, ['post_status' => 'draft']);
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));

        WpStub::updatePost($id, ['post_status' => 'publish', 'post_content' => self::html(self::text(800, 51))]);
        $this->save($id);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        WpStub::updatePost($id, ['post_status' => 'trash']);
        Plugin::onTransition('trash', 'publish', get_post($id));
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        self::assertNotNull(PostState::live($id));
        WpStub::updatePost($id, ['post_status' => 'publish']);
        $this->save($id);
        self::assertNotNull(PostState::live($id));
    }

    /** T23 (case 23). */
    public function testPasswordCancelsAndHides(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 52))]);
        Settings::update(['stale_display' => 'keep']);
        $this->save($id);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        WpStub::updatePost($id, ['post_password' => 'pw']);
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        WpStub::user(9, ['read']);
        self::assertSame('', $this->render($id));
    }

    /** T24 (case 24). */
    public function testManualGenerateOnDraftAndForce(): void
    {
        $draft = $this->post(800, ['post_status' => 'draft']);
        self::assertTrue(Queue::manual($draft));
        $events = WpStub::events(Queue::EVENT);
        self::assertSame(self::NOW, $events[0]['ts'], 'no debounce');
        self::assertSame('generated', Generator::run($draft));

        $short = $this->post(200);
        WpStub::user(3, ['edit_posts', 'read']);
        $e = Queue::manual($short, ['force' => true]);
        self::assertInstanceOf(\WP_Error::class, $e);
        self::assertSame('forbidden', $e->get_error_code());
        $e2 = Queue::manual($short);
        self::assertInstanceOf(\WP_Error::class, $e2);
        self::assertSame('not_eligible', $e2->get_error_code());
        WpStub::user(1, ['manage_options', 'edit_posts', 'edit_others_posts']);
        self::assertTrue(Queue::manual($short, ['force' => true]));
        self::assertSame('generated', Generator::run($short));
    }

    /** T25 (case 25). */
    public function testGenQueueBackendAndCronFallback(): void
    {
        $id = $this->post(800);
        $this->save($id);
        $events = WpStub::events(Queue::EVENT);
        self::assertCount(1, $events);
        self::assertSame([$id], $events[0]['args']);

        $got = [];
        $nextId = 42;
        add_filter(GenQueue::ENQUEUE_FILTER, function ($v, $type, $payload, $args) use (&$got, &$nextId) {
            $got[] = [$type, $payload, $args];
            return $nextId++;
        }, 10, 4);
        $other = $this->post(800, [], [], 9);
        $this->save($other);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$other]));
        self::assertCount(1, $got);
        [$type, $payload, $args] = $got[0];
        self::assertSame('tldr-block.generate', $type);
        self::assertSame($other, $payload['post_id']);
        self::assertStringStartsWith('post:' . $other . ':', $args['idempotency_key']);
        self::assertSame(self::NOW + 300, $args['run_after']);
        self::assertSame('tldr-block', $args['group']);
        self::assertSame(42, PostState::job($other)['gq_id']);

        // A second material save pushes the run time back with a new job;
        // the first job, if it still runs, does nothing.
        \IndianOpinion\TldrBlock\Clock::freeze(self::NOW + 100);
        WpStub::updatePost($other, ['post_content' => self::html(self::text(800, 77))]);
        $this->save($other);
        self::assertCount(2, $got);
        self::assertSame(self::NOW + 400, $got[1][2]['run_after']);
        self::assertSame(43, PostState::job($other)['gq_id']);
        $stale = (object) ['payload' => $got[0][1]];
        self::assertSame('superseded', GenQueue::handle($stale)['outcome']);
        self::assertSame(0, $this->caller->count());

        $fresh = (object) ['payload' => $got[1][1]];
        $this->caller->cost = 0.003;
        $ctx = new class {
            /** @var list<array{0: float|string, 1: array<string, mixed>}> */
            public array $costs = [];

            /** @param array<string, mixed> $usage */
            public function addCost(float|string $amount, array $usage = []): void
            {
                $this->costs[] = [$amount, $usage];
            }
        };
        self::assertSame('generated', GenQueue::handle($fresh, $ctx)['outcome']);
        self::assertSame('pending', PostState::status($other));
        self::assertSame([[0.003, ['metered' => false]]], $ctx->costs, 'spend reaches the queue cap, unmetered without spend-meter');

        // A WP_Error from gen-queue falls back to WP-Cron.
        remove_all_filters(GenQueue::ENQUEUE_FILTER);
        add_filter(GenQueue::ENQUEUE_FILTER, fn () => new \WP_Error('gen_queue', 'storage down'));
        $third = $this->post(800, [], [], 10);
        $this->save($third);
        self::assertNotFalse(wp_next_scheduled(Queue::EVENT, [$third]));
    }

    /** T26 (case 26). */
    public function testIneligibleAtRunTimeIsSkippedWithoutCall(): void
    {
        $id = $this->post(800);
        $this->save($id);
        WpStub::updatePost($id, ['post_password' => 'x']);
        self::assertSame('skipped', Generator::run($id));
        self::assertSame(0, $this->caller->count());
        self::assertNotEmpty($this->logsMatching('skipped'));
    }

    /** T37 (case 37). */
    public function testDeferredJobsRunAfterMidnightOldestFirst(): void
    {
        Settings::update(['daily_cap' => 1]);
        $first = $this->post(800, [], [], 29);
        $ids = [];
        foreach ([2, 1] as $n => $age) {
            $ids[$age] = $this->post(800, [], [], 30 + $n);
        }
        Queue::manual($first);
        Generator::run($first);
        foreach ($ids as $age => $id) {
            Clock::freeze(self::NOW + $age);
            $this->save($id);
            self::assertSame('deferred', Generator::run($id));
        }
        $sweep = WpStub::events(Queue::SWEEP_EVENT);
        self::assertCount(1, $sweep);
        $midnight = Clock::nextMidnight(self::NOW);
        self::assertGreaterThanOrEqual($midnight + 300, $sweep[0]['ts']);
        self::assertLessThanOrEqual($midnight + 2100, $sweep[0]['ts']);

        Clock::freeze($sweep[0]['ts']);
        $order = Queue::sweep();
        self::assertSame([$ids[1], $ids[2]], $order);
        $e1 = wp_next_scheduled(Queue::EVENT, [$ids[1]]);
        $e2 = wp_next_scheduled(Queue::EVENT, [$ids[2]]);
        self::assertLessThan($e2, $e1);
    }

    public function testLocalDayFollowsSiteTimeZone(): void
    {
        WpStub::$timezone = 'Asia/Kolkata';
        // 2027-01-15 20:00 UTC is 16 January 01:30 in Kolkata.
        self::assertSame('2027-01-16', Clock::day(1_800_000_000 + 12 * 3600));
        self::assertSame(gmmktime(18, 30, 0, 1, 15, 2027), Clock::nextMidnight(self::NOW));
    }
}
