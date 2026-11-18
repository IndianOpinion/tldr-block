<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Tests\Stubs;

/**
 * In-memory state behind the WordPress function stubs. Reset before every
 * test. Options, posts, meta and cron are kept per blog for multisite tests.
 */
final class WpStub
{
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var list<int> */
    public static array $sites = [1];
    public static bool $multisite = false;

    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<int, \WP_Post>> */
    public static array $posts = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $postmeta = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $termmeta = [];
    /** @var array<int, \WP_Term> */
    public static array $terms = [];
    /** @var array<int, list<int>> */
    public static array $postCategories = [];
    /** @var array<int, list<array{ts: int, hook: string, args: array<int, mixed>}>> */
    public static array $cron = [];
    /** @var array<string, mixed> */
    public static array $transients = [];

    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var list<string> */
    public static array $doing = [];
    /** @var array<string, list<array<int, mixed>>> */
    public static array $fired = [];

    public static int $userId = 0;
    /** @var array<int, array<string, bool>> */
    public static array $caps = [];

    /** @var array<string, bool|int> */
    public static array $flags = [];

    /** @var list<string> */
    public static array $styles = [];
    /** @var array<string, callable> */
    public static array $shortcodes = [];
    /** @var array<string, array<string, mixed>> */
    public static array $blocks = [];
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    /** @var array<string, array<string, mixed>> */
    public static array $restFields = [];
    /** @var array<string, array<string, mixed>> */
    public static array $registeredMeta = [];
    /** @var list<string> */
    public static array $cleaned = [];
    /** @var list<string> */
    public static array $privacy = [];

    public static string $locale = 'en_US';
    public static string $timezone = 'UTC';
    public static int $nextId = 1;

    public static function reset(): void
    {
        self::$blog = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$multisite = false;
        self::$options = [];
        self::$posts = [];
        self::$postmeta = [];
        self::$termmeta = [];
        self::$terms = [];
        self::$postCategories = [];
        self::$cron = [];
        self::$transients = [];
        self::$hooks = [];
        self::$doing = [];
        self::$fired = [];
        self::$userId = 0;
        self::$caps = [];
        self::$flags = [];
        self::$styles = [];
        self::$shortcodes = [];
        self::$blocks = [];
        self::$routes = [];
        self::$restFields = [];
        self::$registeredMeta = [];
        self::$cleaned = [];
        self::$privacy = [];
        self::$locale = 'en_US';
        self::$timezone = 'UTC';
        self::$nextId = 1;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<int> $categories
     */
    public static function addPost(array $fields = [], array $categories = []): int
    {
        $id = (int) ($fields['ID'] ?? self::$nextId++);
        self::$nextId = max(self::$nextId, $id + 1);
        $fields['ID'] = $id;
        self::$posts[self::$blog][$id] = new \WP_Post($fields + [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Post ' . $id,
            'post_content' => '',
            'post_password' => '',
            'post_author' => 1,
            'post_modified_gmt' => gmdate('Y-m-d H:i:s', 1_700_000_000 + $id),
        ]);
        self::$postCategories[$id] = $categories;

        return $id;
    }

    /** @param array<string, mixed> $fields */
    public static function updatePost(int $id, array $fields): void
    {
        $post = self::$posts[self::$blog][$id];
        foreach ($fields as $k => $v) {
            $post->$k = $v;
        }
    }

    public static function addTerm(string $name, int $parent = 0): int
    {
        $id = self::$nextId++;
        self::$terms[$id] = new \WP_Term(['term_id' => $id, 'name' => $name, 'slug' => strtolower($name), 'parent' => $parent, 'taxonomy' => 'category']);

        return $id;
    }

    /** @param list<string> $caps */
    public static function user(int $id, array $caps): void
    {
        self::$caps[$id] = array_fill_keys($caps, true);
        self::$userId = $id;
    }

    /** @return list<array<int, mixed>> */
    public static function fired(string $tag): array
    {
        return self::$fired[$tag] ?? [];
    }

    /** @return list<array{ts: int, hook: string, args: array<int, mixed>}> */
    public static function events(?string $hook = null): array
    {
        $out = [];
        foreach (self::$cron[self::$blog] ?? [] as $e) {
            if ($hook === null || $e['hook'] === $hook) {
                $out[] = $e;
            }
        }

        return $out;
    }

    public static function flag(string $name): bool
    {
        return (bool) (self::$flags[$name] ?? false);
    }
}
