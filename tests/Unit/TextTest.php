<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Unit;

use IndianOpinion\TldrBlock\Tests\TestCase;
use IndianOpinion\TldrBlock\Text\Figures;
use IndianOpinion\TldrBlock\Text\Fingerprint;
use IndianOpinion\TldrBlock\Text\SourceText;
use IndianOpinion\TldrBlock\Text\WordCounter;

final class TextTest extends TestCase
{
    /** T2 (case 2). */
    public function testWordCountsAcrossScripts(): void
    {
        self::assertSame(9, WordCounter::count('The quick brown fox, aged 7, jumps over dogs.'));
        // Hindi: combining marks stay with their word.
        self::assertSame(4, WordCounter::count('हिन्दी भाषा बहुत सुंदर'));
        self::assertSame(3, WordCounter::count('தமிழ் மொழி அழகானது'));
        // Japanese without spaces: two characters per word (7 characters).
        self::assertSame(4, WordCounter::count('日本語の文章です'));
        // Mixed: 3 Latin words, 2 Han characters (1 word), 1 Devanagari word.
        self::assertSame(5, WordCounter::count('Delhi news today 新闻 समाचार'));
        self::assertSame(0, WordCounter::count(''));
        self::assertSame(0, WordCounter::count(' , . ; '));
    }

    /** T10 (case 10). */
    public function testSourceTextStripsMarkupAndKeepsCaptions(): void
    {
        $html = '<!-- wp:heading --><h2>Big &amp; small</h2><!-- /wp:heading -->'
            . '<p>First <strong>para</strong>&nbsp;text.</p><script>var x = 1;</script><style>p{}</style>'
            . '[gallery ids="1,2"]<figure><img src="a.jpg"/><figcaption>A caption here</figcaption></figure>'
            . '[note]Inside note[/note]<p>Caf&eacute; &lt;ok&gt;</p>';
        $text = SourceText::fromContent($html);
        self::assertStringContainsString('Big & small', $text);
        self::assertStringContainsString('First para text.', $text);
        self::assertStringContainsString('A caption here', $text);
        self::assertStringContainsString('Inside note', $text);
        self::assertStringContainsString('Café <ok>', $text);
        self::assertStringNotContainsString('var x', $text);
        self::assertStringNotContainsString('p{}', $text);
        self::assertStringNotContainsString('gallery', $text);
        self::assertStringNotContainsString('wp:', $text);
        self::assertStringNotContainsString('<strong>', $text);
    }

    /** T11 (case 11): the title is not part of the fingerprint. */
    public function testTitleIsNotInTheFingerprint(): void
    {
        $id = $this->post(800);
        $before = Fingerprint::of(SourceText::fromContent((string) get_post($id)?->post_content));
        \IndianOpinion\TldrBlock\Tests\Stubs\WpStub::updatePost($id, ['post_title' => 'A completely new title']);
        $after = Fingerprint::of(SourceText::fromContent((string) get_post($id)?->post_content));
        self::assertSame($before->hash(), $after->hash());
    }

    /** T12 (case 12). */
    public function testSketchMatchesFixtureAndShortTexts(): void
    {
        $fp = Fingerprint::of('The council met on Monday and approved the river budget for the coming year after a long debate.');
        self::assertCount(16, $fp->sketch());
        self::assertSame(self::FIXTURE_SKETCH_SHA, hash('sha256', $fp->packedSketch()));
        $two = Fingerprint::of('Two words');
        self::assertCount(2, $two->sketch());
        $long = Fingerprint::of(self::text(1000));
        self::assertCount(128, $long->sketch());
        self::assertLessThan(800, strlen($long->packedSketch()));
        $round = Fingerprint::fromStored($long->hash(), $long->packedSketch());
        self::assertSame($long->sketch(), $round->sketch());
    }

    /** Case 12: empty and tiny texts give usable fingerprints. */
    public function testEmptyAndTinyFingerprints(): void
    {
        $empty = Fingerprint::of('');
        self::assertSame([], $empty->sketch());
        self::assertSame('', $empty->packedSketch());
        self::assertSame(0.0, $empty->distance(Fingerprint::of('')));
        self::assertFalse(Fingerprint::of('')->isMaterialChangeFrom($empty, 0.1));
        $one = Fingerprint::of('Word');
        self::assertCount(1, $one->sketch());
        self::assertSame(1.0, $one->distance($empty));
        self::assertTrue($one->isMaterialChangeFrom($empty, 0.9));
        self::assertTrue(Fingerprint::of('Other')->isMaterialChangeFrom($one, 0.9));
        self::assertFalse(Fingerprint::of('word')->isMaterialChangeFrom($one, 0.1), 'case does not matter');
        $three = Fingerprint::of('one two three');
        self::assertCount(1, $three->sketch());
        $stored = Fingerprint::fromStored($empty->hash(), '');
        self::assertSame(0.0, $stored->distance($empty));
        $junk = Fingerprint::fromStored('abc', 'not base64!');
        self::assertSame([], $junk->sketch());
        // Punctuation only: no words, but a different exact hash.
        self::assertSame([], Fingerprint::of('!!! ...')->sketch());
    }

    public const FIXTURE_SKETCH_SHA = '9a366b1253b605f86488d9324b5682d1286d5874629c9878eb6e76055f939dcd';

    /** T13 (case 13). */
    public function testMaterialChangeThreshold(): void
    {
        $base = self::text(1000, 3);
        $fp = Fingerprint::of($base);
        $oneWord = (string) preg_replace('/^(\w+)/', 'changed', $base);
        self::assertFalse(Fingerprint::of($oneWord)->isMaterialChangeFrom($fp, 0.10));
        $added = $base . "\n\n" . self::text(150, 99);
        self::assertTrue(Fingerprint::of($added)->isMaterialChangeFrom($fp, 0.10));

        // Ten small edits: none material alone against the previous save,
        // but material together against the generated-from sketch.
        $current = $base;
        $prev = $fp;
        $materialAlone = 0;
        for ($i = 0; $i < 10; $i++) {
            $current .= "\n\n" . self::text(18, 200 + $i);
            $now = Fingerprint::of($current);
            if ($now->isMaterialChangeFrom($prev, 0.10)) {
                $materialAlone++;
            }
            $prev = $now;
        }
        self::assertSame(0, $materialAlone);
        self::assertTrue(Fingerprint::of($current)->isMaterialChangeFrom($fp, 0.10));
        self::assertFalse(Fingerprint::of($base)->isMaterialChangeFrom($fp, 0.10));
    }

    /** T14 (case 14). */
    public function testOwnBlockAndShortcodeLeaveFingerprintUnchanged(): void
    {
        $html = self::html(self::text(300));
        $fp = Fingerprint::of(SourceText::fromContent($html));
        $withBlock = '<!-- wp:tldr-block/summary {"heading":"Key points"} /-->' . $html;
        $withShortcode = '[tldr heading="In short"]' . $html . '[tldr]';
        self::assertSame($fp->hash(), Fingerprint::of(SourceText::fromContent($withBlock))->hash());
        self::assertSame($fp->hash(), Fingerprint::of(SourceText::fromContent($withShortcode))->hash());
        self::assertTrue(SourceText::containsOwnMarkup($withBlock));
        self::assertTrue(SourceText::containsOwnMarkup($withShortcode));
        self::assertFalse(SourceText::containsOwnMarkup($html));
    }

    /** T15 (case 15) at the text level. */
    public function testTruncationCutsAtParagraphBreak(): void
    {
        $text = self::text(7000);
        self::assertGreaterThan(40000, mb_strlen($text));
        [$cut, $truncated] = SourceText::truncate($text, 30000);
        self::assertTrue($truncated);
        self::assertLessThanOrEqual(30000, mb_strlen($cut));
        self::assertStringEndsWith('.', $cut);
        self::assertStringStartsWith($cut, $text);
        self::assertSame("\n\n", substr($text, strlen($cut), 2));
        [$same, $t2] = SourceText::truncate('short text', 30000);
        self::assertFalse($t2);
        self::assertSame('short text', $same);
        // No paragraph break: cut at a sentence end.
        [$s, $t3] = SourceText::truncate('One. Two three. Four five six seven', 20);
        self::assertTrue($t3);
        self::assertSame('One. Two three.', $s);
    }

    /** T41 (case 41). */
    public function testFigureCheck(): void
    {
        $source = 'The plan costs 1,200 crore and covers ४५ districts. Growth was 3.5 percent.';
        self::assertSame(['9'], Figures::unverified(['It costs 1200 crore.', 'It covers 45 districts and 9 towns.', 'Growth 3.5 percent.'], $source));
        self::assertSame([], Figures::unverified(['४५ districts, 1,200 crore'], $source));
        self::assertSame('45', Figures::normalise('४५'));
        self::assertSame('120000', Figures::normalise('1,20,000'));
    }

    /** T74 (section 9): reported, not a hard failure on slow runners. */
    public function testFingerprintPerformance(): void
    {
        $text = self::text(10000);
        $start = hrtime(true);
        Fingerprint::of($text);
        $ms = (hrtime(true) - $start) / 1e6;
        fwrite(STDERR, sprintf("\nfingerprint of 10000 words: %.2f ms\n", $ms));
        self::assertLessThan(2000, $ms);
    }
}
