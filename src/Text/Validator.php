<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Output validation (PRD case 32), also used for edited and hand-written
 * summaries (cases 47 and 48, rules 1 to 5).
 */
final class Validator
{
    private const URL = '/\bhttps?:\/\/|\bwww\.[\p{L}\p{N}-]+\.|\b[a-z0-9-]+\.(?:com|org|net|gov|edu|info|io|co|in|uk|ly|me|biz)\b(?:\/|\s|$|[.,;:!?)])/iu';
    private const INSTRUCTION = '/\b(?:ignore|disregard)\b.{0,40}\b(?:instructions?|prompts?|rules)\b|\bsystem prompt\b|\bas an ai\b|\blanguage model\b/iu';

    /**
     * @param array<string, mixed> $output decoded model output or edited input
     * @param array{format: string, points_min: int, points_max: int, max_words: int, paragraph_words: int, language?: string, script_check?: bool} $rules
     * @return array{points: list<string>, text: string, errors: list<string>, warnings: list<string>}
     */
    public static function check(array $output, array $rules): array
    {
        $errors = [];
        $warnings = [];
        $points = [];
        $text = '';
        $format = $rules['format'] === 'paragraph' ? 'paragraph' : 'bullets';

        if ($format === 'bullets') {
            $raw = $output['points'] ?? null;
            if (!is_array($raw)) {
                return ['points' => [], 'text' => '', 'errors' => ['shape'], 'warnings' => []];
            }
            $seen = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    $errors[] = 'shape';
                    continue;
                }
                $clean = self::clean($item);
                if ($clean === '') {
                    $errors[] = 'empty_point';
                    continue;
                }
                $key = mb_strtolower($clean, 'UTF-8');
                if (isset($seen[$key])) {
                    $errors[] = 'duplicate_point';
                    continue;
                }
                $seen[$key] = true;
                $points[] = $clean;
            }
            $count = count($raw);
            if ($count < $rules['points_min']) {
                $errors[] = 'too_few_points';
            }
            if ($count > $rules['points_max']) {
                $errors[] = 'too_many_points';
            }
            foreach ($points as $p) {
                self::lengthCheck($p, $rules['max_words'], 'point', $errors, $warnings);
            }
            $parts = $points;
        } else {
            $raw = $output['summary'] ?? ($output['text'] ?? null);
            if (!is_string($raw)) {
                return ['points' => [], 'text' => '', 'errors' => ['shape'], 'warnings' => []];
            }
            $text = self::clean($raw);
            if ($text === '') {
                $errors[] = 'empty_text';
            } else {
                self::lengthCheck($text, $rules['paragraph_words'], 'text', $errors, $warnings);
            }
            $parts = [$text];
        }

        foreach ($parts as $part) {
            if (preg_match(self::URL, $part) === 1) {
                $errors[] = 'url';
            }
            if (preg_match(self::INSTRUCTION, $part) === 1) {
                $errors[] = 'instruction';
            }
        }

        if (($rules['script_check'] ?? false) && isset($rules['language'])) {
            $script = Language::scriptFor($rules['language']);
            if ($script !== null) {
                $share = Language::scriptShare(implode(' ', $parts), $script);
                if ($share !== null && $share < 0.5) {
                    $errors[] = 'wrong_script';
                }
            }
        }

        return [
            'points' => $points,
            'text' => $text,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Strip HTML tags and Markdown markup, collapse whitespace, trim.
     */
    public static function clean(string $s): string
    {
        $s = (string) preg_replace('/<[^>]*>/', '', $s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = (string) preg_replace('/<[^>]*>/', '', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        // Leading list or heading markers.
        $s = (string) preg_replace('/^(?:[-*+\x{2022}\x{2023}\x{25E6}>]+|#{1,6}|\(?\d{1,2}[.)])\s*/u', '', $s);
        // Emphasis and code markers.
        $s = (string) preg_replace('/(\*\*|__|\*|`+)(.+?)\1/u', '$2', $s);
        $s = str_replace(['**', '`'], '', $s);

        return trim($s);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private static function lengthCheck(string $s, int $limit, string $kind, array &$errors, array &$warnings): void
    {
        $words = WordCounter::count($s);
        if ($words > (int) floor($limit * 1.5)) {
            $errors[] = 'long_' . $kind;
        } elseif ($words > $limit) {
            $warnings[] = 'long_' . $kind;
        }
    }
}
