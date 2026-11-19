<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\Admin\PostsColumn;
use IndianOpinion\TldrBlock\Admin\ReviewScreen;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Plugin;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Rest\Controller;
use IndianOpinion\TldrBlock\Review;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;
use IndianOpinion\TldrBlock\Tests\TestCase;

final class ReviewTest extends TestCase
{
    private function req(string $method, int $id, string $action = '', array $params = []): \WP_REST_Request
    {
        return new \WP_REST_Request($method, '/tldr-block/v1/posts/' . $id . ($action === '' ? '' : '/' . $action), ['id' => $id] + $params);
    }

    /** Dispatch like the REST server: permission first, then callback. */
    private function dispatch(string $method, int $id, string $action = '', array $params = []): \WP_REST_Response|\WP_Error
    {
        WpStub::$routes = [];
        Controller::register();
        $route = WpStub::$routes['tldr-block/v1/posts/(?P<id>\d+)' . ($action === '' ? '' : '/' . $action)];
        $req = $this->req($method, $id, $action, $params);
        self::assertSame($method, $route['methods']);
        $perm = ($route['permission_callback'])($req);
        if ($perm !== true) {
            return $perm;
        }

        return ($route['callback'])($req);
    }

    private function code(\WP_REST_Response|\WP_Error $r): int
    {
        return $r instanceof \WP_Error ? (int) $r->get_error_data()['status'] : $r->get_status();
    }

    /** T45 (case 45). */
    public function testPanelStateListsButtonsPerRole(): void
    {
        $id = $this->post(800, ['post_author' => 5]);
        $this->approved($id);
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 71))]);
        $this->saveAndRun($id);

        $this->editor(2);
        $r = $this->dispatch('GET', $id);
        $state = $r->get_data();
        self::assertSame('pending', $state['status']);
        self::assertNotNull($state['candidate']);
        self::assertTrue($state['live']['stale']);
        foreach (['generate', 'approve', 'edit_approve', 'reject', 'reject_keep_previous', 'withdraw', 'manual'] as $a) {
            self::assertContains($a, $state['actions'], $a);
        }
        self::assertArrayNotHasKey('source_sketch', $state['candidate']);
        self::assertNotContains('restore', $state['actions'], 'no history yet');

        WpStub::user(5, ['edit_posts', 'edit_published_posts', 'read']);
        $author = $this->dispatch('GET', $id)->get_data();
        self::assertSame(['generate'], $author['actions']);
        self::assertArrayNotHasKey('approved_by', $author['live']);
    }

    /** T46 (case 46). */
    public function testApproveAndSuperseded(): void
    {
        $id = $this->post(800);
        $this->saveAndRun($id);
        $old = PostState::candidate($id)['id'];
        Queue::manual($id);
        Generator::run($id);
        $new = PostState::candidate($id)['id'];
        $this->editor();
        $r = $this->dispatch('POST', $id, 'approve', ['candidate_id' => $old]);
        self::assertSame(409, $this->code($r));
        $r = $this->dispatch('POST', $id, 'approve', ['candidate_id' => $new]);
        self::assertSame(200, $this->code($r));
        $live = PostState::live($id);
        self::assertSame('machine', $live['origin']);
        self::assertSame(2, $live['approved_by']);
        self::assertSame(self::NOW, $live['approved_at']);
        self::assertFalse($live['stale']);
        self::assertSame('approved', PostState::status($id));
        self::assertNull(PostState::candidate($id));
        self::assertCount(1, WpStub::fired('tldr_block_approved'));
        self::assertSame([[$id, 'approved']], WpStub::fired('tldr_block_display_changed'));
        self::assertNotSame('', $this->render($id));
    }

    /** Case 19: approving a candidate the post has since moved away from leaves it stale. */
    public function testApprovingAnOutdatedCandidateStartsStale(): void
    {
        $id = $this->post(800);
        $this->saveAndRun($id);
        $c = PostState::candidate($id);
        WpStub::updatePost($id, ['post_content' => self::html(self::text(800, 55))]);
        $this->save($id);
        $this->editor();
        self::assertSame(200, $this->code($this->dispatch('POST', $id, 'approve', ['candidate_id' => $c['id']])));
        self::assertTrue(PostState::live($id)['stale']);
        self::assertSame('', $this->render($id), 'hidden by the default stale display');
    }

    /** T47 (case 47). */
    public function testEditAndApprove(): void
    {
        $id = $this->post(800);
        $this->saveAndRun($id);
        $cid = PostState::candidate($id)['id'];
        $this->editor();
        $bad = $this->dispatch('POST', $id, 'approve', ['candidate_id' => $cid, 'points' => ['One.', 'https://example.org is good.', 'Three.']]);
        self::assertSame(422, $this->code($bad));
        self::assertContains('url', $bad->get_error_data()['errors']);
        $bad2 = $this->dispatch('POST', $id, 'approve', ['candidate_id' => $cid, 'points' => ['Only one.']]);
        self::assertSame(422, $this->code($bad2));
        $ok = $this->dispatch('POST', $id, 'approve', ['candidate_id' => $cid, 'points' => ['<b>Edited</b> one.', 'Edited two.', 'Edited three.']]);
        self::assertSame(200, $this->code($ok));
        $live = PostState::live($id);
        self::assertSame('edited', $live['origin']);
        self::assertSame(['Edited one.', 'Edited two.', 'Edited three.'], $live['points']);
    }

    /** T48 (case 48). */
    public function testWriteMyOwn(): void
    {
        $id = $this->post(800);
        $this->editor();
        $r = $this->dispatch('PUT', $id, 'manual', ['points' => ['Mine one.', 'Mine two.', 'Mine three.']]);
        self::assertSame(200, $this->code($r));
        self::assertSame(0, $this->caller->count());
        $live = PostState::live($id);
        self::assertSame('human', $live['origin']);
        self::assertNotSame('', $live['source_hash']);
        self::assertStringContainsString('Mine two.', $this->render($id));
        // A save with no change queues nothing: the hand-written summary set the basis.
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]));
        $para = $this->dispatch('PUT', $id, 'manual', ['text' => 'One paragraph summary.']);
        self::assertSame(200, $this->code($para));
        self::assertSame('paragraph', PostState::live($id)['format']);
    }

    /** T49 (case 49). */
    public function testRejectAndKeepPrevious(): void
    {
        $id = $this->post(800);
        $this->saveAndRun($id);
        $cid = PostState::candidate($id)['id'];
        $this->editor();
        $calls = $this->caller->count();
        $r = $this->dispatch('POST', $id, 'reject', ['candidate_id' => $cid, 'reason' => str_repeat('x', 300), 'keep_previous' => false]);
        self::assertSame(200, $this->code($r));
        self::assertSame('rejected', PostState::status($id));
        $h = PostState::history($id)[0];
        self::assertSame('rejected', $h['kind']);
        self::assertSame(200, mb_strlen($h['reason']));
        $this->save($id);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$id]), 'no regeneration for the same fingerprint');
        self::assertSame($calls, $this->caller->count());

        // Keep previous: re-approves a stale live summary against the current text.
        $p = $this->post(800, [], [], 8);
        $this->approved($p);
        WpStub::updatePost($p, ['post_content' => self::html(self::text(800, 81))]);
        $this->saveAndRun($p);
        self::assertTrue(PostState::live($p)['stale']);
        $this->editor();
        $this->dispatch('POST', $p, 'reject', ['candidate_id' => PostState::candidate($p)['id'], 'reason' => '', 'keep_previous' => true]);
        self::assertFalse(PostState::live($p)['stale']);
        self::assertNotSame('', $this->render($p));
        $this->save($p);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$p]));
    }

    /** T50 (case 50). */
    public function testEachDisplayConditionHides(): void
    {
        $make = function (int $seed): int {
            $id = $this->post(800, [], [], $seed);
            $this->approved($id);
            WpStub::user(9, ['read']);
            self::assertNotSame('', $this->render($id));
            return $id;
        };
        $a = $make(1);
        Settings::update(['display' => 'off']);
        self::assertSame('', $this->render($a), 'display off');
        Settings::update(['display' => 'on']);

        $b = $make(2);
        PostState::setOverride($b, 'off');
        self::assertSame('', $this->render($b), 'mode off');

        $c = $make(3);
        WpStub::updatePost($c, ['post_status' => 'private']);
        self::assertSame('', $this->render($c), 'private, reader cannot read');
        $this->editor();
        self::assertNotSame('', $this->render($c), 'private, editor can read');
        WpStub::user(9, ['read']);

        $d = $make(4);
        WpStub::updatePost($d, ['post_password' => 'pw']);
        self::assertSame('', $this->render($d), 'password');

        $e = $make(5);
        Settings::update(['min_words' => 900]);
        self::assertSame('', $this->render($e), 'below minimum');
        Settings::update(['min_words' => 600]);

        $f = $make(6);
        Review::withdraw($f, 2);
        WpStub::user(9, ['read']);
        self::assertSame('', $this->render($f), 'withdrawn');

        $g = $make(7);
        $live = PostState::live($g);
        $live['stale'] = true;
        PostState::setLive($g, $live);
        self::assertSame('', $this->render($g), 'stale');
        Settings::update(['stale_display' => 'keep']);
        self::assertNotSame('', $this->render($g), 'stale kept');
    }

    /** T51 (case 51), T52 (case 52), T58 (case 58). */
    public function testWithdrawRestoreAndDisplayEvents(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        $first = PostState::live($id)['points'];
        $this->editor();
        WpStub::$fired = [];
        $this->dispatch('POST', $id, 'withdraw');
        self::assertSame('withdrawn', PostState::status($id));
        self::assertSame('', $this->render($id));
        self::assertSame([[$id, 'withdrawn']], WpStub::fired('tldr_block_display_changed'));
        self::assertSame([[$id, 2]], WpStub::fired('tldr_block_withdrawn'));

        // A second approved summary pushes the first to history.
        $this->caller->queue[] = ['points' => ['Second one here.', 'Second two here.', 'Second three here.']];
        Queue::manual($id);
        Generator::run($id);
        $this->dispatch('POST', $id, 'approve', ['candidate_id' => PostState::candidate($id)['id']]);
        self::assertStringContainsString('Second one here.', $this->render($id));

        $hist = PostState::history($id);
        self::assertSame('live', $hist[0]['kind']);
        WpStub::$fired = [];
        $r = $this->dispatch('POST', $id, 'restore', ['history_id' => $hist[0]['history_id']]);
        self::assertSame(200, $this->code($r));
        $live = PostState::live($id);
        self::assertSame('restored', $live['origin']);
        self::assertSame($first, $live['points']);
        self::assertFalse($live['withdrawn']);
        self::assertStringContainsString(esc_html($first[0]), $this->render($id));
        self::assertSame([[$id, 'restored']], WpStub::fired('tldr_block_display_changed'));
        self::assertSame(404, $this->code($this->dispatch('POST', $id, 'restore', ['history_id' => 'nope'])));
    }

    public function testHistoryIsCapped(): void
    {
        Settings::update(['history' => 2, 'post_limit' => 20]);
        $id = $this->post(800);
        for ($i = 0; $i < 4; $i++) {
            $this->approved($id);
            Queue::manual($id);
        }
        self::assertCount(2, PostState::history($id));
    }

    /** T53 (case 53). */
    public function testPermissions(): void
    {
        $id = $this->post(800, ['post_author' => 5]);
        $this->saveAndRun($id);
        WpStub::user(5, ['edit_posts', 'read']);
        $r = $this->dispatch('POST', $id, 'approve', ['candidate_id' => PostState::candidate($id)['id']]);
        self::assertSame(403, $this->code($r));
        self::assertSame(202, $this->code($this->dispatch('POST', $id, 'generate', ['force' => false])));
        WpStub::user(6, ['read']);
        self::assertSame(403, $this->code($this->dispatch('GET', $id)));
        WpStub::$userId = 0;
        self::assertSame(401, $this->code($this->dispatch('GET', $id)));
        self::assertSame(404, $this->code($this->dispatch('GET', 999)));
        // Another author's post.
        $other = $this->post(800, ['post_author' => 7], [], 4);
        WpStub::user(5, ['edit_posts', 'read']);
        self::assertSame(403, $this->code($this->dispatch('GET', $other)));
        // A filtered approve capability.
        add_filter('tldr_block_approve_capability', fn () => 'publish_posts');
        WpStub::user(5, ['edit_posts', 'publish_posts', 'read']);
        self::assertTrue(Review::canApprove($id, 5));
    }

    public function testGenerateRouteErrors(): void
    {
        $id = $this->post(800);
        $this->editor();
        Settings::update(['generation' => 'off']);
        $r = $this->dispatch('POST', $id, 'generate', ['force' => false]);
        self::assertSame(409, $this->code($r));
        self::assertSame('kill_switch', $r->get_error_code());
        Settings::update(['generation' => 'on', 'daily_cap' => 0]);
        $r = $this->dispatch('POST', $id, 'generate', ['force' => false]);
        self::assertSame(429, $this->code($r));
    }

    public function testOverrideRoute(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        $this->editor();
        WpStub::$fired = [];
        $this->dispatch('POST', $id, 'override', ['mode' => 'off']);
        self::assertSame('off', PostState::override($id));
        self::assertSame([[$id, 'post_switch']], WpStub::fired('tldr_block_display_changed'));
        self::assertSame('', $this->render($id));
        $this->dispatch('POST', $id, 'override', ['mode' => 'auto']);
        self::assertSame('auto', PostState::override($id));

        // Authors and anonymous users cannot switch a post.
        WpStub::user(5, ['edit_posts', 'edit_published_posts', 'read']);
        self::assertSame(403, $this->code($this->dispatch('POST', $id, 'override', ['mode' => 'off'])));
        WpStub::user(0, []);
        self::assertSame(401, $this->code($this->dispatch('POST', $id, 'override', ['mode' => 'off'])));
        self::assertSame('auto', PostState::override($id));
    }

    /** T54 (case 54). */
    public function testReviewScreen(): void
    {
        Settings::update(['daily_cap' => 3]);
        $pending = $this->post(800, [], [], 1);
        $this->saveAndRun($pending);
        $failed = $this->post(800, [], [], 2);
        PostState::setStatus($failed, 'failed');
        $stale = $this->post(800, [], [], 3);
        $this->approved($stale);
        $live = PostState::live($stale);
        $live['stale'] = true;
        PostState::setLive($stale, $live);
        $deferred = $this->post(800, [], [], 4);
        PostState::setStatus($deferred, 'deferred');
        $quiet = $this->post(800, [], [], 5);
        PostState::setStatus($quiet, 'approved');

        $ids = array_column(ReviewScreen::rows(), 'id');
        sort($ids);
        self::assertSame([$pending, $failed, $stale, $deferred], $ids);
        self::assertSame([$failed], array_column(ReviewScreen::rows('failed'), 'id'));
        self::assertSame([$stale], array_column(ReviewScreen::rows('stale'), 'id'));

        $this->editor();
        $html = ReviewScreen::html();
        self::assertStringContainsString('value="approve"', $html);
        self::assertStringContainsString('Farmers asked for more water', $html);
        self::assertStringNotContainsString('bulk', strtolower($html));
        WpStub::user(5, ['edit_posts', 'read']);
        self::assertStringNotContainsString('value="approve"', ReviewScreen::html());
    }

    /** T55 (case 55). */
    public function testPostsColumnAndFilter(): void
    {
        $id = $this->post(800);
        self::assertSame('None', PostsColumn::cellText($id));
        foreach (PostState::STATUSES as $s) {
            PostState::setStatus($id, $s);
            self::assertSame(PostsColumn::label($s), PostsColumn::cellText($id));
        }
        self::assertArrayHasKey('tldr_block', PostsColumn::columns(['title' => 'Title']));
        self::assertSame(['key' => '_tldr_block_status', 'value' => 'pending'], PostsColumn::metaQuery('pending'));
        self::assertSame(['key' => '_tldr_block_status', 'compare' => 'NOT EXISTS'], PostsColumn::metaQuery('none'));
        self::assertNull(PostsColumn::metaQuery('bogus'));
        $a = $this->post(800, [], [], 2);
        PostState::setStatus($a, 'failed');
        self::assertSame([$a], get_posts(['fields' => 'ids', 'post_type' => 'post', 'meta_query' => [PostsColumn::metaQuery('failed')]]));
        WpStub::$flags['admin'] = true;
        $_GET[PostsColumn::QUERY_VAR] = 'failed';
        $q = new \WP_Query(true);
        PostsColumn::filterQuery($q);
        unset($_GET[PostsColumn::QUERY_VAR]);
        self::assertSame([['key' => '_tldr_block_status', 'value' => 'failed']], $q->get('meta_query'));
        ob_start();
        PostsColumn::cell('tldr_block', $a);
        self::assertSame('Failed', ob_get_clean());
    }

    /** T73 (section 8). */
    public function testMetaIsProtectedAndNotWritableThroughRest(): void
    {
        Plugin::registerMeta();
        foreach (PostState::KEYS as $key) {
            $args = WpStub::$registeredMeta['post:post:' . $key];
            self::assertFalse($args['show_in_rest'], $key);
            self::assertFalse(($args['auth_callback'])(false, $key, 1, 5), $key);
            self::assertTrue(is_protected_meta($key, 'post'), $key);
        }
        self::assertFalse(WpStub::$registeredMeta['term:category:_tldr_block_mode']['show_in_rest']);
    }
}
