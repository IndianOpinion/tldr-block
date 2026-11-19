<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Builds the source text from raw post content (PRD cases 10, 14 and 15).
 *
 * Pure PHP so it can be tested without WordPress. Paragraph breaks are kept
 * as a blank line so truncation can cut at one; everything else collapses
 * to single spaces.
 */
final class SourceText
{
    public const BLOCK_NAME = 'tldr-block/summary';
    public const SHORTCODE = 'tldr';

    public static function fromContent(string $content): string
    {
        $text = self::removeOwnMarkup($content);
        // Any other block comment delimiter.
        $text = (string) preg_replace('/<!--\s*\/?wp:[^>]*?-->/s', '', $text);
        // Drop script and style elements with their contents.
        $text = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', ' ', $text);
        // Other HTML comments.
        $text = (string) preg_replace('/<!--.*?-->/s', ' ', $text);
        // Shortcode tags go; the text between enclosing tags stays.
        $text = (string) preg_replace('/\[\/?[A-Za-z][A-Za-z0-9_-]*(?:\s[^\[\]]*)?\/?\]/', ' ', $text);
        // Block-level boundaries become paragraph breaks before tags go.
        $text = (string) preg_replace(
            '/<\/(?:p|h[1-6]|li|blockquote|figcaption|figure|div|section|pre|td|th|tr|dd|dt)\s*>|<br\s*\/?>|<hr\s*\/?>/i',
            "\n\n",
            $text
        );
        $text = (string) preg_replace('/<[^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $text = $normal;
            }
        }

        return self::collapse($text);
    }

    /**
     * Remove this plugin's own block and shortcode, so the summary never
     * feeds its own fingerprint (PRD case 14).
     */
    public static function removeOwnMarkup(string $content): string
    {
        $name = preg_quote(self::BLOCK_NAME, '/');
        $content = (string) preg_replace('/<!--\s*wp:' . $name . '(?:\s[^>]*?)?\/-->/s', '', $content);
        $content = (string) preg_replace(
            '/<!--\s*wp:' . $name . '(?:\s[^>]*?)?-->.*?<!--\s*\/wp:' . $name . '\s*-->/s',
            '',
            $content
        );
        $tag = preg_quote(self::SHORTCODE, '/');

        return (string) preg_replace('/\[\/?' . $tag . '(?:\s[^\]]*)?\/?\]/', '', $content);
    }

    public static function containsOwnMarkup(string $content): bool
    {
        return str_contains($content, '<!-- wp:' . self::BLOCK_NAME)
            || preg_match('/\[' . preg_quote(self::SHORTCODE, '/') . '(?:\s[^\]]*)?\/?\]/', $content) === 1;
    }

    /**
     * Cut text longer than the limit at the last paragraph break, else the
     * last sentence end, else the limit itself.
     *
     * @return array{0: string, 1: bool} text and whether it was cut
     */
    public static function truncate(string $text, int $limit): array
    {
        if ($limit < 1 || mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text, false];
        }
        $cut = mb_substr($text, 0, $limit, 'UTF-8');
        $para = mb_strrpos($cut, "\n\n", 0, 'UTF-8');
        if ($para !== false && $para > 0) {
            return [rtrim(mb_substr($cut, 0, $para, 'UTF-8')), true];
        }
        if (preg_match_all('/[.!?\x{0964}\x{0965}\x{3002}](?=\s)/u', $cut, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            if (is_array($last) && $last[1] > 0) {
                return [substr($cut, 0, $last[1] + strlen($last[0])), true];
            }
        }

        return [$cut, true];
    }

    private static function collapse(string $text): string
    {
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace('/\n{2,}/u', "\n\n", $text);
        // A single newline left over is not a paragraph break.
        $text = (string) preg_replace('/(?<!\n)\n(?!\n)/u', ' ', $text);

        return trim($text);
    }
}
