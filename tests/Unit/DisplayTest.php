<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Renderer;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Summary;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;
use IndianOpinion\TldrBlock\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class DisplayTest extends TestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = $this->post(800);
        $this->approved($this->id);
        WpStub::user(9, ['read']);
    }

    /** T56 (case 56). */
    public function testAutoInsertionOnlyOnSingularMainView(): void
    {
        $this->view($this->id);
        $out = Renderer::filterContent('<p>Body</p>');
        self::assertStringStartsWith('<aside class="tldr-block"', $out);
        self::assertStringEndsWith('<p>Body</p>', $out);
        self::assertSame('<p>Again</p>', Renderer::filterContent('<p>Again</p>'), 'never twice in one request');

        $this->view($this->id);
        WpStub::$flags['singular'] = false;
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'archive');

        $this->view($this->id);
        WpStub::$flags['main_query'] = false;
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'secondary query');

        $this->view($this->id);
        WpStub::$flags['in_the_loop'] = false;
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'outside the loop');

        $this->view($this->id);
        WpStub::$flags['queried_id'] = 999;
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'not the queried post');

        $this->view($this->id);
        WpStub::$flags['feed'] = true;
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'feed');
        WpStub::$flags['feed'] = false;

        $this->view($this->id);
        add_filter('get_the_excerpt', fn ($v) => Renderer::filterContent($v));
        self::assertSame('<p>B</p>', apply_filters('get_the_excerpt', '<p>B</p>'), 'excerpt');

        $this->view($this->id);
        WpStub::updatePost($this->id, ['post_content' => '<!-- wp:tldr-block/summary /-->' . get_post($this->id)?->post_content]);
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'), 'content holds the block');
    }

    /** T56: REST responses. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestResponsesSkipUnlessEnabled(): void
    {
        define('REST_REQUEST', true);
        $this->view($this->id);
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'));
        Settings::update(['rest_content' => 'on']);
        $this->view($this->id);
        self::assertStringStartsWith('<aside', (string) Renderer::filterContent('<p>B</p>'));
    }

    /** T57 (case 57). */
    public function testPlacement(): void
    {
        Settings::update(['placement' => 'after_first_paragraph']);
        $this->view($this->id);
        $out = (string) Renderer::filterContent('<div><p>Nested</p></div><p>First</p><p>Second</p>');
        self::assertMatchesRegularExpression('#^<div><p>Nested</p></div><p>First</p><aside#', $out);
        $this->view($this->id);
        $out2 = (string) Renderer::filterContent('<h2>No paragraphs</h2>');
        self::assertStringStartsWith('<aside', $out2);
        Settings::update(['placement' => 'none']);
        $this->view($this->id);
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'));
        self::assertNotSame('', Renderer::shortcode([]), 'shortcode still works');
    }

    /** T59 (case 59). */
    public function testShortcode(): void
    {
        WpStub::$flags['the_id'] = $this->id;
        self::assertStringContainsString('tldr-block__points', Renderer::shortcode(''));
        self::assertSame('', Renderer::shortcode(''), 'once per request');
        Renderer::reset();
        $other = $this->post(800, [], [], 7);
        $this->approved($other);
        WpStub::user(9, ['read']);
        self::assertNotSame('', Renderer::shortcode(['post_id' => (string) $other, 'heading' => 'Key points']));
        Renderer::reset();
        self::assertStringContainsString('>Key points</p>', Renderer::shortcode(['post_id' => (string) $other, 'heading' => 'Key points']));
        Renderer::reset();
        self::assertStringNotContainsString('tldr-block__heading', Renderer::shortcode(['post_id' => (string) $other, 'heading' => '']));
        WpStub::updatePost($other, ['post_status' => 'private']);
        Renderer::reset();
        self::assertSame('', Renderer::shortcode(['post_id' => (string) $other]));
        self::assertSame('', Renderer::shortcode(['post_id' => '99999']));
        self::assertSame('', Renderer::shortcode(['post_id' => 'abc']));
    }

    /** T60 (case 60). */
    public function testBlock(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/blocks/summary/block.json'), true);
        self::assertSame(3, $json['apiVersion']);
        self::assertSame('tldr-block/summary', $json['name']);
        self::assertSame('text', $json['category']);
        WpStub::$flags['the_id'] = $this->id;
        $html = Renderer::renderBlock(['heading' => 'In brief', 'showHeading' => true]);
        self::assertStringContainsString('wp-block-tldr-block-summary', $html);
        self::assertStringContainsString('>In brief</p>', $html);
        // Pending only: the front end gets nothing, never the placeholder.
        $pending = $this->post(800, [], [], 12);
        $this->saveAndRun($pending);
        WpStub::$flags['the_id'] = $pending;
        $out = Renderer::renderBlock([]);
        self::assertSame('', $out);
        $editorJs = (string) file_get_contents(dirname(__DIR__, 2) . '/blocks/summary/editor.js');
        self::assertStringContainsString('Summary pending review', $editorJs);
        // The block in the content stops automatic insertion.
        WpStub::updatePost($this->id, ['post_content' => '<!-- wp:tldr-block/summary /-->x']);
        self::assertTrue(Renderer::hasOwnMarkup($this->id));
        $this->view($this->id);
        self::assertSame('x', Renderer::filterContent('x'));
    }

    /** T61 (case 61). */
    public function testTemplateFunctionEchoesRender(): void
    {
        $expected = tldr_block_render($this->id);
        Renderer::reset();
        ob_start();
        tldr_block_the_summary($this->id);
        self::assertSame($expected, ob_get_clean());
        self::assertNotSame('', $expected);
        self::assertInstanceOf(Summary::class, tldr_block_get($this->id));
        self::assertSame('on', tldr_block_mode_for($this->id));
        self::assertSame('approved', tldr_block_state($this->id)['status']);
        self::assertTrue(tldr_block_is_enabled());
    }

    /** T62 (case 62). */
    public function testMarkupFixture(): void
    {
        $summary = Summary::fromLive([
            'format' => 'bullets', 'points' => ['First <script>alert(1)</script>', 'Second & "third"'],
            'language' => 'hi', 'origin' => 'machine', 'approved_at' => 0, 'approved_by' => 2,
        ]);
        self::assertNotNull($summary);
        self::assertSame(
            '<aside class="tldr-block" aria-label="Summary" lang="hi"><p class="tldr-block__heading">Summary</p>'
            . '<ul class="tldr-block__points"><li>First &lt;script&gt;alert(1)&lt;/script&gt;</li><li>Second &amp; &quot;third&quot;</li></ul></aside>',
            Renderer::markup($summary)
        );
        add_filter('tldr_block_heading_tag', fn () => 'h2');
        $en = Summary::fromLive(['format' => 'paragraph', 'text' => 'Para.', 'language' => 'en', 'approved_at' => 0]);
        self::assertNotNull($en);
        self::assertSame(
            '<aside class="tldr-block" aria-label="Summary"><h2 class="tldr-block__heading">Summary</h2><p class="tldr-block__text">Para.</p></aside>',
            Renderer::markup($en)
        );
        add_filter('tldr_block_html', fn ($h) => '<div>custom</div>');
        self::assertSame('<div>custom</div>', Renderer::markup($en));
    }

    /** T63 (case 63). */
    public function testStylesOnlyWhenPrinted(): void
    {
        $quiet = $this->post(800, [], [], 13);
        self::assertSame('', $this->render($quiet));
        self::assertNotContains('tldr-block', WpStub::$styles);
        $this->render($this->id);
        self::assertContains('tldr-block', WpStub::$styles);
        WpStub::$styles = [];
        add_filter('tldr_block_load_styles', fn () => false);
        $this->render($this->id);
        self::assertNotContains('tldr-block', WpStub::$styles);
        self::assertLessThan(2048, filesize(dirname(__DIR__, 2) . '/assets/tldr-block.css'));
    }

    /** T64 (case 64). */
    public function testRestField(): void
    {
        $value = Renderer::restField(['id' => $this->id]);
        self::assertSame(['format', 'points', 'approved_at', 'origin'], array_keys((array) $value));
        $pending = $this->post(800, [], [], 14);
        $this->saveAndRun($pending);
        self::assertNull(Renderer::restField(['id' => $pending]));
        self::assertStringNotContainsString('approved_by', (string) json_encode($value));
    }

    /** T65 (case 65). */
    public function testFeeds(): void
    {
        WpStub::$flags = ['feed' => true, 'the_id' => $this->id];
        self::assertSame('<p>B</p>', Renderer::filterContent('<p>B</p>'));
        Settings::update(['feeds' => 'on']);
        Renderer::reset();
        WpStub::$styles = [];
        $out = (string) Renderer::filterContent('<p>B</p>');
        self::assertStringStartsWith('<aside class="tldr-block"', $out);
        self::assertSame([], WpStub::$styles, 'no styles in feeds');
    }

    public function testLangAttributeOnlyWhenDifferent(): void
    {
        $live = PostState::live($this->id);
        self::assertSame('en', $live['language']);
        self::assertStringNotContainsString('lang=', $this->render($this->id));
    }
}
