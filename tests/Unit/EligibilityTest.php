<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\Admin\TermFields;
use IndianOpinion\TldrBlock\Eligibility;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Modes;
use IndianOpinion\TldrBlock\Plugin;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Renderer;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;
use IndianOpinion\TldrBlock\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class EligibilityTest extends TestCase
{
    /** T1 (case 1). */
    public function testEligiblePostQueuesAndEachFailingConditionDoesNot(): void
    {
        $id = $this->post(800);
        $this->save($id);
        self::assertSame('queued', PostState::status($id));
        self::assertCount(1, WpStub::events(Queue::EVENT));

        $cases = [
            'type' => ['post_type' => 'page'],
            'status' => ['post_status' => 'draft'],
            'password' => ['post_password' => 'secret'],
            'private' => ['post_status' => 'private'],
        ];
        foreach ($cases as $why => $fields) {
            $p = $this->post(800, $fields);
            $this->save($p);
            self::assertSame('none', PostState::status($p), $why);
            self::assertFalse(wp_next_scheduled(Queue::EVENT, [$p]), $why);
        }
        $short = $this->post(200);
        $this->save($short);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$short]));
        self::assertSame('short', Eligibility::reason($short));

        $off = $this->post(800);
        PostState::setOverride($off, 'off');
        $this->save($off);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$off]));

        Settings::update(['generation' => 'off']);
        $k = $this->post(800);
        $this->save($k);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$k]));
        self::assertSame('kill_switch', Eligibility::reason($k));
        Settings::update(['generation' => 'on']);

        remove_all_filters('tldr_block_client');
        $np = $this->post(800);
        $this->save($np);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$np]));
        self::assertSame('no_provider', Eligibility::reason($np));
    }

    public function testEligibilityFilterCanVetoButNotOverrideKillSwitch(): void
    {
        $id = $this->post(800);
        add_filter('tldr_block_is_eligible', fn () => false);
        self::assertSame('filtered', Eligibility::reason($id));
        remove_all_filters('tldr_block_is_eligible');
        add_filter('tldr_block_is_eligible', fn () => true);
        Settings::update(['generation' => 'off']);
        self::assertSame('kill_switch', Eligibility::reason($id));
        $priv = $this->post(800, ['post_status' => 'private']);
        Settings::update(['generation' => 'on']);
        remove_all_filters('tldr_block_is_eligible');
        add_filter('tldr_block_allow_private', fn () => true);
        self::assertSame('', Eligibility::reason($priv));
    }

    /** T3 (case 3). */
    public function testCategoryMinimumWords(): void
    {
        $cat = WpStub::addTerm('Short');
        $plain = WpStub::addTerm('Plain');
        update_term_meta($cat, Modes::TERM_MIN_WORDS, 300);
        $a = $this->post(400, [], [$cat]);
        $b = $this->post(400, [], [$plain]);
        self::assertSame(300, Modes::minWords($a));
        self::assertSame(600, Modes::minWords($b));
        self::assertSame('', Eligibility::reason($a));
        self::assertSame('short', Eligibility::reason($b));
        $child = WpStub::addTerm('Child', $cat);
        $c = $this->post(400, [], [$child]);
        self::assertSame(300, Modes::minWords($c));
    }

    /** T4 (case 4). */
    public function testModeResolution(): void
    {
        Settings::update(['default_mode' => 'off']);
        $on = WpStub::addTerm('On');
        $off = WpStub::addTerm('Off');
        update_term_meta($on, Modes::TERM_MODE, 'on');
        update_term_meta($off, Modes::TERM_MODE, 'off');
        $child = WpStub::addTerm('Child', $off);

        $p = $this->post(800, [], [$on]);
        self::assertSame('on', Modes::forPost($p));
        PostState::setOverride($p, 'off');
        self::assertSame('off', Modes::forPost($p), 'post override beats category');

        $both = $this->post(800, [], [$on, $off]);
        self::assertSame('off', Modes::forPost($both), 'off in any category wins');

        $c = $this->post(800, [], [$child]);
        self::assertSame('off', Modes::forPost($c), 'child inherits parent off');

        $none = $this->post(800, [], [WpStub::addTerm('Nothing')]);
        self::assertSame('off', Modes::forPost($none), 'global default');
        Settings::update(['default_mode' => 'on']);
        self::assertSame('on', Modes::forPost($none));

        $page = $this->post(800, ['post_type' => 'page'], [$off]);
        self::assertSame('on', Modes::forPost($page), 'type without categories uses the global default');

        add_filter('tldr_block_mode', fn () => 'off');
        self::assertSame('off', Modes::forPost($none));
    }

    public function testFreshInstallDefaultModeIsOff(): void
    {
        self::assertSame('off', Settings::defaults()['default_mode']);
    }

    /** T5 (case 5) and T69 (case 69). */
    public function testCategorySwitchHidesAndShowsWithoutCalls(): void
    {
        $cat = WpStub::addTerm('News');
        $id = $this->post(800, [], [$cat]);
        $this->approved($id);
        $calls = $this->caller->count();
        self::assertNotSame('', $this->render($id));

        TermFields::apply($cat, 'off', null);
        self::assertSame('off', Modes::termMode($cat));
        self::assertSame('', $this->render($id));
        self::assertSame([[$cat, 'category_switch']], WpStub::fired('tldr_block_display_changed') === [] ? [] : array_slice(WpStub::fired('tldr_block_display_changed'), -1));

        TermFields::apply($cat, 'on', 450);
        self::assertSame(450, Modes::termMinWords($cat));
        self::assertNotSame('', $this->render($id));
        self::assertSame($calls, $this->caller->count());
        self::assertNotNull(PostState::live($id), 'stored summaries are kept');

        // Saving with no mode change fires nothing.
        $before = count(WpStub::fired('tldr_block_display_changed'));
        TermFields::apply($cat, 'on', 500);
        self::assertCount($before, WpStub::fired('tldr_block_display_changed'));
    }

    /** T6 (case 6). */
    public function testSwitchingCategoryOnQueuesNothing(): void
    {
        Settings::update(['default_mode' => 'off']);
        $cat = WpStub::addTerm('Later');
        $id = $this->post(800, [], [$cat]);
        $this->save($id);
        self::assertSame([], WpStub::events(Queue::EVENT));
        TermFields::apply($cat, 'on', null);
        self::assertSame([], WpStub::events(Queue::EVENT));
        self::assertSame(0, $this->caller->count());
    }

    /** T7 (case 7). */
    public function testKillSwitch(): void
    {
        $approved = $this->post(800);
        $this->approved($approved);
        $queued = $this->post(800, [], [], 2);
        $this->save($queued);
        Settings::update(['generation' => 'off']);
        $calls = $this->caller->count();

        $fresh = $this->post(800, [], [], 3);
        $this->save($fresh);
        self::assertFalse(wp_next_scheduled(Queue::EVENT, [$fresh]));
        self::assertSame('skipped', Generator::run($queued));
        self::assertSame($calls, $this->caller->count());
        $err = Queue::manual($approved);
        self::assertInstanceOf(\WP_Error::class, $err);
        self::assertSame('kill_switch', $err->get_error_code());
        self::assertNotSame('', $this->render($approved));
        self::assertFalse(tldr_block_is_enabled());
    }

    /** T7 (case 7): the constant locks the switch. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testKillSwitchConstantLocks(): void
    {
        define('TLDR_BLOCK_DISABLED', true);
        Settings::update(['generation' => 'on']);
        self::assertTrue(Settings::killSwitchOn());
        self::assertTrue(Settings::killSwitchLocked());
        $html = \IndianOpinion\TldrBlock\Admin\SettingsPage::html();
        self::assertStringContainsString('Locked off by TLDR_BLOCK_DISABLED', $html);
        self::assertStringContainsString('name="tldr_block[generation]" disabled="disabled"', $html);
    }

    /** T8 (case 8). */
    public function testDisplaySwitch(): void
    {
        $id = $this->post(800);
        $this->approved($id);
        Settings::update(['display' => 'off']);
        self::assertSame('', $this->render($id));
        $this->view($id);
        self::assertSame('<p>x</p>', Renderer::filterContent('<p>x</p>'));
        self::assertSame('', (string) do_shortcode_stub($id));
        self::assertNull(Renderer::restField(['id' => $id]));
        // Generation continues.
        $other = $this->post(800, [], [], 5);
        self::assertSame('generated', $this->saveAndRun($other));
        // The editor preview still has the live summary.
        self::assertNotNull(\IndianOpinion\TldrBlock\State::of($id)['live']);
    }

    /** T9 (case 9). */
    public function testActivationWithNoKeyMakesNoCallAndQueuesNothing(): void
    {
        remove_all_filters('tldr_block_client');
        WpStub::$flags['admin'] = true;
        Plugin::boot(__FILE__);
        do_action('plugins_loaded');
        do_action('init');
        $id = $this->post(800);
        do_action('wp_after_insert_post', $id, get_post($id));
        self::assertSame([], WpStub::events(Queue::EVENT));
        self::assertFalse(Settings::providerConfigured());
        self::assertStringContainsString('Generation is off until an API key and at least one model are set.', \IndianOpinion\TldrBlock\Admin\SettingsPage::html());
        // Key alone is not enough; key and a model are.
        Settings::saveApiKey('k-123456');
        self::assertFalse(Settings::providerConfigured());
        Settings::update(['models' => 'vendor/model-a, vendor/model-b']);
        self::assertTrue(Settings::providerConfigured());
    }
}

function do_shortcode_stub(int $id): string
{
    return Renderer::shortcode(['post_id' => (string) $id]);
}
