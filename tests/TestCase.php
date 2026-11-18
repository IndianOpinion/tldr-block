<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests;

use IndianOpinion\TldrBlock\Clock;
use IndianOpinion\TldrBlock\Generator;
use IndianOpinion\TldrBlock\Logger;
use IndianOpinion\TldrBlock\PostState;
use IndianOpinion\TldrBlock\Queue;
use IndianOpinion\TldrBlock\Renderer;
use IndianOpinion\TldrBlock\Settings;
use IndianOpinion\TldrBlock\Tests\Stubs\WpStub;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const NOW = 1_800_000_000; // 2027-01-15 08:00 UTC

    /** @var list<string> */
    protected array $logLines = [];
    protected FakeCaller $caller;

    protected function setUp(): void
    {
        parent::setUp();
        WpStub::reset();
        \WP_CLI::$lines = [];
        Clock::freeze(self::NOW);
        Renderer::reset();
        Generator::resetTick();
        $this->logLines = [];
        Logger::setSink(function (string $line): void {
            $this->logLines[] = $line;
        });
        $this->caller = new FakeCaller();
        add_filter('tldr_block_client', fn () => $this->caller);
        // Turn the site on: global mode on so most tests need no category.
        Settings::update(['default_mode' => 'on']);
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        Logger::setSink(null);
        parent::tearDown();
    }

    /** A long text of distinct words, deterministic per seed. */
    public static function text(int $words, int $seed = 1): string
    {
        mt_srand($seed);
        $vocab = ['river', 'market', 'council', 'budget', 'school', 'farmer', 'monsoon', 'rail', 'court', 'village',
            'water', 'power', 'harbour', 'road', 'clinic', 'teacher', 'report', 'season', 'price', 'crop',
            'district', 'office', 'policy', 'storm', 'bridge', 'factory', 'worker', 'survey', 'tax', 'trade'];
        $out = [];
        $sentence = [];
        for ($i = 0; $i < $words; $i++) {
            $sentence[] = $vocab[mt_rand(0, count($vocab) - 1)] . mt_rand(0, 40);
            if (count($sentence) === 12) {
                $out[] = ucfirst(implode(' ', $sentence)) . '.';
                $sentence = [];
            }
        }
        if ($sentence !== []) {
            $out[] = ucfirst(implode(' ', $sentence)) . '.';
        }
        $paras = array_chunk($out, 6);

        return implode("\n\n", array_map(static fn (array $p): string => implode(' ', $p), $paras));
    }

    public static function html(string $text): string
    {
        return implode("\n", array_map(static fn (string $p): string => '<!-- wp:paragraph --><p>' . $p . '</p><!-- /wp:paragraph -->', explode("\n\n", $text)));
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<int> $cats
     */
    protected function post(int $words = 800, array $fields = [], array $cats = [], int $seed = 1): int
    {
        return WpStub::addPost($fields + ['post_content' => self::html(self::text($words, $seed))], $cats);
    }

    protected function save(int $id): void
    {
        Queue::onSave($id, get_post($id));
    }

    /** Save and run the queued job at once. */
    protected function saveAndRun(int $id): string
    {
        $this->save($id);

        return Generator::run($id);
    }

    /** Generate and approve a summary for a post. */
    protected function approved(int $id, int $user = 2): void
    {
        WpStub::user($user, ['edit_posts', 'edit_others_posts', 'read']);
        $this->saveAndRun($id);
        $c = PostState::candidate($id);
        self::assertNotNull($c, 'candidate expected');
        self::assertTrue(\IndianOpinion\TldrBlock\Review::approve($id, (string) $c['id'], $user));
    }

    protected function editor(int $id = 2): void
    {
        WpStub::user($id, ['edit_posts', 'edit_others_posts', 'edit_published_posts', 'read']);
    }

    /** Front-end singular view of a post. */
    protected function view(int $id): void
    {
        WpStub::$flags = array_merge(WpStub::$flags, ['singular' => true, 'in_the_loop' => true, 'main_query' => true, 'the_id' => $id, 'queried_id' => $id]);
        Renderer::reset();
    }

    protected function render(int $id): string
    {
        Renderer::reset();

        return Renderer::render($id);
    }

    /** @return list<string> */
    protected function logsMatching(string $needle): array
    {
        return array_values(array_filter($this->logLines, static fn (string $l): bool => str_contains($l, $needle)));
    }
}
