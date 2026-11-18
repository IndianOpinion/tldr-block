<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock\Text;

/**
 * Exact hash plus a bottom-k sketch of word 3-shingles (PRD cases 12, 13).
 */
final class Fingerprint
{
    public const SKETCH_SIZE = 128;

    /**
     * @param list<int> $sketch sorted ascending, at most SKETCH_SIZE values
     */
    private function __construct(
        private readonly string $hash,
        private readonly array $sketch
    ) {
    }

    public static function of(string $sourceText): self
    {
        $lower = mb_strtolower($sourceText, 'UTF-8');
        $words = WordCounter::words($lower);
        $shingles = [];
        $n = count($words);
        if ($n >= 3) {
            for ($i = 0; $i + 2 < $n; $i++) {
                $shingles[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            }
        } else {
            $shingles = $words;
        }
        $hashes = [];
        foreach ($shingles as $shingle) {
            $hashes[self::hash32($shingle)] = true;
        }
        $values = array_keys($hashes);
        sort($values, SORT_NUMERIC);

        return new self(hash('sha256', $lower), array_slice($values, 0, self::SKETCH_SIZE));
    }

    public static function fromStored(string $hash, string $packedSketch): self
    {
        $raw = base64_decode($packedSketch, true);
        $values = [];
        if (is_string($raw) && $raw !== '' && strlen($raw) % 4 === 0) {
            $unpacked = unpack('N*', $raw);
            if (is_array($unpacked)) {
                foreach ($unpacked as $v) {
                    $values[] = (int) $v;
                }
            }
        }
        sort($values, SORT_NUMERIC);

        return new self($hash, array_slice($values, 0, self::SKETCH_SIZE));
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /** @return list<int> */
    public function sketch(): array
    {
        return $this->sketch;
    }

    public function packedSketch(): string
    {
        return $this->sketch === [] ? '' : base64_encode(pack('N*', ...$this->sketch));
    }

    /**
     * Estimated Jaccard distance between the two shingle sets: the share of
     * the bottom-k of the union that is not in both sketches.
     */
    public function distance(self $other): float
    {
        if ($this->hash === $other->hash) {
            return 0.0;
        }
        if ($this->sketch === [] && $other->sketch === []) {
            return 0.0;
        }
        $a = array_flip($this->sketch);
        $b = array_flip($other->sketch);
        $union = array_unique(array_merge($this->sketch, $other->sketch));
        sort($union, SORT_NUMERIC);
        $union = array_slice($union, 0, self::SKETCH_SIZE);
        $both = 0;
        foreach ($union as $v) {
            if (isset($a[$v], $b[$v])) {
                $both++;
            }
        }

        return 1.0 - ($both / count($union));
    }

    /**
     * Material change: the exact hash differs and the distance is at or
     * above the threshold.
     */
    public function isMaterialChangeFrom(self $basis, float $threshold): bool
    {
        if ($this->hash === $basis->hash) {
            return false;
        }

        return $this->distance($basis) >= $threshold;
    }

    private static function hash32(string $s): int
    {
        return crc32($s);
    }
}
