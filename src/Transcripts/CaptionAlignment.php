<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * What CaptionAligner measured for one file. The offset is caption time minus
 * the time the words are spoken: positive when captions run late, so the
 * correction is to subtract it.
 */
class CaptionAlignment
{
    /** Offsets smaller than this are left alone; transcript rows are whole seconds anyway. */
    public const SHIFT_THRESHOLD = 1.5;

    /**
     * @param array<int,array{start:float,median:float,matches:int}> $windows each
     *        sampled window that had enough matches to count, in time order
     */
    public function __construct(
        public readonly bool $aligned,
        public readonly ?float $offset,
        public readonly int $matches,
        public readonly ?float $iqr,
        public readonly array $windows,
        public readonly bool $drift,
        public readonly string $reason
    ) {
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, 0, null, [], false, $reason);
    }

    public function shouldShift(): bool
    {
        return $this->aligned && abs((float) $this->offset) >= self::SHIFT_THRESHOLD;
    }

    /** The value for files.caption_alignment. */
    public function status(): string
    {
        if (!$this->aligned) {
            return 'unaligned';
        }

        return $this->shouldShift() ? 'aligned' : 'not_needed';
    }

    /** One line for logs and reports. */
    public function summary(): string
    {
        $parts = [sprintf('%d matches', $this->matches)];
        if ($this->offset !== null) {
            $parts[] = sprintf('offset %+.1fs', $this->offset);
        }
        if ($this->iqr !== null) {
            $parts[] = sprintf('IQR %.1fs', $this->iqr);
        }
        if (!empty($this->windows)) {
            $parts[] = 'windows ' . implode(' ', array_map(
                static fn(array $w) => sprintf('%s:%+.1f(%d)', gmdate('H:i:s', (int) $w['start']), $w['median'], $w['matches']),
                $this->windows
            ));
        }

        return implode(', ', $parts) . ' -> ' . $this->status() . ($this->reason !== '' ? ' (' . $this->reason . ')' : '');
    }
}
