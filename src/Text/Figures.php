<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Figure check (PRD case 41): numbers in the summary must appear in the
 * source text after both are normalised to ASCII digits with thousands
 * separators removed.
 */
final class Figures
{
    /** Code points of the digit zero in the common decimal digit blocks. */
    private const ZEROS = [
        0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66,
        0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0x17E0, 0x1810, 0xFF10,
    ];

    /**
     * @return list<string> normalised numbers, in order of first appearance
     */
    public static function extract(string $text): array
    {
        if (preg_match_all('/\p{Nd}+(?:[,.\x{066B}\x{066C}]\p{Nd}+)*/u', $text, $m) === false) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $raw) {
            $n = self::normalise($raw);
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $summaryParts
     * @return list<string> numbers in the summary that are not in the source
     */
    public static function unverified(array $summaryParts, string $source): array
    {
        $known = array_flip(self::extract($source));
        $missing = [];
        foreach ($summaryParts as $part) {
            foreach (self::extract($part) as $n) {
                if (!isset($known[$n]) && !in_array($n, $missing, true)) {
                    $missing[] = $n;
                }
            }
        }

        return $missing;
    }

    public static function normalise(string $raw): string
    {
        $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return '';
        }
        $out = '';
        foreach ($chars as $ch) {
            if ($ch === ',' || $ch === "\u{066C}") {
                continue;
            }
            if ($ch === '.' || $ch === "\u{066B}") {
                $out .= '.';
                continue;
            }
            $out .= (string) self::digitValue($ch);
        }

        return $out;
    }

    private static function digitValue(string $ch): int
    {
        if (class_exists(\IntlChar::class)) {
            $v = \IntlChar::charDigitValue($ch);
            if (is_int($v) && $v >= 0) {
                return $v;
            }
        }
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp >= 0x30 && $cp <= 0x39) {
            return $cp - 0x30;
        }
        foreach (self::ZEROS as $zero) {
            if ($cp >= $zero && $cp <= $zero + 9) {
                return $cp - $zero;
            }
        }

        return 0;
    }
}
