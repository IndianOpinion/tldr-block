<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * Mode resolution (PRD case 4) and per-category minimum words (case 3).
 */
final class Modes
{
    public const TERM_MODE = '_tldr_block_mode';
    public const TERM_MIN_WORDS = '_tldr_block_min_words';

    public static function termMode(int $termId): string
    {
        $m = get_term_meta($termId, self::TERM_MODE, true);

        return in_array($m, ['on', 'off'], true) ? (string) $m : 'inherit';
    }

    public static function termMinWords(int $termId): ?int
    {
        $v = get_term_meta($termId, self::TERM_MIN_WORDS, true);
        if ($v === '' || $v === null || $v === false || !is_numeric($v)) {
            return null;
        }

        return max(100, min(10000, (int) $v));
    }

    /**
     * Saves the category fields. Returns true when the mode changed.
     */
    public static function saveTerm(int $termId, string $mode, ?int $minWords): bool
    {
        $before = self::termMode($termId);
        if (in_array($mode, ['on', 'off'], true)) {
            update_term_meta($termId, self::TERM_MODE, $mode);
        } else {
            delete_term_meta($termId, self::TERM_MODE);
            $mode = 'inherit';
        }
        if ($minWords === null) {
            delete_term_meta($termId, self::TERM_MIN_WORDS);
        } else {
            update_term_meta($termId, self::TERM_MIN_WORDS, max(100, min(10000, $minWords)));
        }

        return $before !== $mode;
    }

    /** Resolved mode, `on` or `off`. */
    public static function forPost(int $postId): string
    {
        $mode = self::resolve($postId);
        $filtered = apply_filters('tldr_block_mode', $mode, $postId);

        return $filtered === 'on' ? 'on' : 'off';
    }

    public static function minWords(int $postId): int
    {
        $global = Settings::get()['min_words'];
        $type = get_post_type($postId);
        if (!is_string($type) || !is_object_in_taxonomy($type, 'category')) {
            return $global;
        }
        $best = null;
        foreach (self::categoryIds($postId) as $termId) {
            foreach (self::chain($termId) as $id) {
                $v = self::termMinWords($id);
                if ($v !== null) {
                    $best = $best === null ? $v : min($best, $v);
                    break;
                }
            }
        }

        return $best ?? $global;
    }

    private static function resolve(int $postId): string
    {
        $override = PostState::override($postId);
        if ($override !== 'auto') {
            return $override;
        }
        $type = get_post_type($postId);
        if (is_string($type) && is_object_in_taxonomy($type, 'category')) {
            $anyOn = false;
            foreach (self::categoryIds($postId) as $termId) {
                $m = self::nearestExplicit($termId);
                if ($m === 'off') {
                    // Ruling 1: off in any category wins.
                    return 'off';
                }
                if ($m === 'on') {
                    $anyOn = true;
                }
            }
            if ($anyOn) {
                return 'on';
            }
        }

        return Settings::get()['default_mode'];
    }

    private static function nearestExplicit(int $termId): ?string
    {
        foreach (self::chain($termId) as $id) {
            $m = self::termMode($id);
            if ($m !== 'inherit') {
                return $m;
            }
        }

        return null;
    }

    /**
     * The term and its ancestors, nearest first.
     *
     * @return list<int>
     */
    private static function chain(int $termId): array
    {
        $ids = [$termId];
        foreach (get_ancestors($termId, 'category', 'taxonomy') as $a) {
            $ids[] = (int) $a;
        }

        return $ids;
    }

    /** @return list<int> */
    private static function categoryIds(int $postId): array
    {
        $ids = wp_get_post_categories($postId, ['fields' => 'ids']);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }
}
