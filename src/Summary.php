<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

/**
 * An approved summary, as readers see it. Immutable.
 */
final class Summary
{
    /**
     * @param list<string> $points
     */
    public function __construct(
        private readonly string $format,
        private readonly array $points,
        private readonly string $text,
        private readonly string $language,
        private readonly string $origin,
        private readonly \DateTimeImmutable $approvedAt,
        private readonly int $approvedBy,
        private readonly ?\DateTimeImmutable $generatedAt,
        private readonly ?string $model,
        private readonly bool $stale
    ) {
    }

    /**
     * @param array<string, mixed> $live the stored live summary
     */
    public static function fromLive(array $live): ?self
    {
        $format = ($live['format'] ?? '') === 'paragraph' ? 'paragraph' : 'bullets';
        $points = [];
        foreach ((array) ($live['points'] ?? []) as $p) {
            if (is_string($p) && $p !== '') {
                $points[] = $p;
            }
        }
        $text = is_string($live['text'] ?? null) ? $live['text'] : '';
        if (($format === 'bullets' && $points === []) || ($format === 'paragraph' && $text === '')) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $approved = (new \DateTimeImmutable('@' . (int) ($live['approved_at'] ?? 0)))->setTimezone($utc);
        $generated = isset($live['generated_at']) && is_numeric($live['generated_at'])
            ? (new \DateTimeImmutable('@' . (int) $live['generated_at']))->setTimezone($utc)
            : null;
        $origin = in_array($live['origin'] ?? '', ['machine', 'edited', 'human', 'restored'], true) ? (string) $live['origin'] : 'machine';

        return new self(
            $format,
            $format === 'bullets' ? $points : [],
            $format === 'paragraph' ? $text : '',
            is_string($live['language'] ?? null) ? $live['language'] : '',
            $origin,
            $approved,
            (int) ($live['approved_by'] ?? 0),
            $generated,
            is_string($live['model'] ?? null) && $live['model'] !== '' ? $live['model'] : null,
            (bool) ($live['stale'] ?? false)
        );
    }

    public function format(): string
    {
        return $this->format;
    }

    /** @return list<string> */
    public function points(): array
    {
        return $this->points;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function approvedAt(): \DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approvedBy(): int
    {
        return $this->approvedBy;
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Public shape (no approver id). Used by the REST field.
     *
     * @return array{format: string, points?: list<string>, text?: string, approved_at: string, origin: string}
     */
    public function toArray(): array
    {
        $out = ['format' => $this->format];
        if ($this->format === 'paragraph') {
            $out['text'] = $this->text;
        } else {
            $out['points'] = $this->points;
        }
        $out['approved_at'] = $this->approvedAt->format(\DateTimeInterface::ATOM);
        $out['origin'] = $this->origin;

        return $out;
    }
}
