<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Splits text into words the way the plugin counts them (PRD case 2).
 *
 * A word is a run of letters, combining marks and digits (Unicode classes
 * L, M and N). Combining marks stay with the word they follow, so an Indic
 * word counts once. Scripts written without spaces (Han, Hiragana,
 * Katakana, Thai, Lao, Khmer, Myanmar) count one word per two characters.
 */
final class WordCounter
{
    private const NO_SPACE = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        if ($text === '') {
            return [];
        }
        // Fast path: no script written without spaces.
        if (preg_match('/[' . self::NO_SPACE . ']/u', $text) !== 1) {
            return preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text, $simple) === false ? [] : $simple[0];
        }
        $pattern = '/(?:[' . self::NO_SPACE . ']\p{M}*)+|(?:(?![' . self::NO_SPACE . '])[\p{L}\p{M}\p{N}])+/u';
        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }
        $words = [];
        foreach ($matches[0] as $run) {
            if (preg_match('/^[' . self::NO_SPACE . ']/u', $run) === 1) {
                foreach (self::pairs($run) as $pair) {
                    $words[] = $pair;
                }
                continue;
            }
            $words[] = $run;
        }

        return $words;
    }

    public static function count(string $text): int
    {
        return count(self::words($text));
    }

    /**
     * Cut a run of a no-space script into two-character words. A character
     * is a base letter plus any combining marks that follow it.
     *
     * @return list<string>
     */
    private static function pairs(string $run): array
    {
        if (preg_match_all('/\P{M}\p{M}*/u', $run, $chars) === false) {
            return [$run];
        }
        $out = [];
        foreach (array_chunk($chars[0], 2) as $chunk) {
            $out[] = implode('', $chunk);
        }

        return $out;
    }
}
