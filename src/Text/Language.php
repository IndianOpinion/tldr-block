<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Language names, codes and expected scripts.
 */
final class Language
{
    /** @var array<string, array{0: string, 1: string|null}> code => [English name, script] */
    private const TABLE = [
        'en' => ['English', 'Latin'], 'fr' => ['French', 'Latin'], 'de' => ['German', 'Latin'],
        'es' => ['Spanish', 'Latin'], 'pt' => ['Portuguese', 'Latin'], 'it' => ['Italian', 'Latin'],
        'nl' => ['Dutch', 'Latin'], 'id' => ['Indonesian', 'Latin'], 'sw' => ['Swahili', 'Latin'],
        'hi' => ['Hindi', 'Devanagari'], 'mr' => ['Marathi', 'Devanagari'], 'ne' => ['Nepali', 'Devanagari'],
        'bn' => ['Bengali', 'Bengali'], 'as' => ['Assamese', 'Bengali'], 'ta' => ['Tamil', 'Tamil'],
        'te' => ['Telugu', 'Telugu'], 'kn' => ['Kannada', 'Kannada'], 'ml' => ['Malayalam', 'Malayalam'],
        'gu' => ['Gujarati', 'Gujarati'], 'pa' => ['Punjabi', 'Gurmukhi'], 'or' => ['Odia', 'Oriya'],
        'ur' => ['Urdu', 'Arabic'], 'ar' => ['Arabic', 'Arabic'], 'fa' => ['Persian', 'Arabic'],
        'ru' => ['Russian', 'Cyrillic'], 'uk' => ['Ukrainian', 'Cyrillic'], 'el' => ['Greek', 'Greek'],
        'he' => ['Hebrew', 'Hebrew'], 'th' => ['Thai', 'Thai'], 'ko' => ['Korean', 'Hangul'],
        'zh' => ['Chinese', 'Han'], 'ja' => ['Japanese', null], 'si' => ['Sinhala', 'Sinhala'],
    ];

    /** Two or three letter code from a locale such as hi_IN or en-GB. */
    public static function codeFromLocale(string $locale): string
    {
        $parts = preg_split('/[_-]/', strtolower(trim($locale)));
        $code = is_array($parts) ? (string) ($parts[0] ?? '') : '';

        return preg_match('/^[a-z]{2,3}$/', $code) === 1 ? $code : 'en';
    }

    public static function nameForCode(string $code): string
    {
        $code = strtolower($code);
        if (isset(self::TABLE[$code])) {
            return self::TABLE[$code][0];
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, 'en');
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /** Reverse lookup by English name, for a filtered language name. */
    public static function codeForName(string $name): ?string
    {
        $needle = strtolower(trim($name));
        foreach (self::TABLE as $code => $row) {
            if (strtolower($row[0]) === $needle) {
                return $code;
            }
        }
        if (preg_match('/^[a-z]{2,3}(?:[_-][a-z0-9]+)?$/i', $needle) === 1) {
            return self::codeFromLocale($needle);
        }

        return null;
    }

    public static function scriptFor(string $code): ?string
    {
        return self::TABLE[strtolower($code)][1] ?? null;
    }

    /**
     * Share of letters in the given script, or null when the text has no
     * letters.
     */
    public static function scriptShare(string $text, string $script): ?float
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === false || $letters === 0) {
            return null;
        }
        $in = preg_match_all('/\p{' . $script . '}/u', $text);
        if ($in === false) {
            return null;
        }

        return $in / $letters;
    }
}
