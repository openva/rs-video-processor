<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * Measures how far a file's captions are from its audio.
 *
 * Official captions are offset from the speech by an amount that differs from
 * file to file (from -15 minutes to +10 seconds have been seen) but holds steady
 * within a file. This transcribes a few short stretches of the video with word
 * timestamps, finds four-word phrases that occur exactly once in the captions,
 * and takes the median of caption time minus spoken time.
 *
 * The result is trusted only if there are enough matches, they agree closely,
 * and separate stretches of the video give the same answer. Captions that
 * belong to another recording match nothing consistently and come back
 * unaligned.
 */
class CaptionAligner
{
    /** Words per phrase. */
    private const PHRASE_WORDS = 4;

    /** Shorter phrases ("and of the bill") are too likely to recur by chance. */
    private const MIN_PHRASE_LETTERS = 12;

    /** A cue this long has no usable timing for the words inside it. */
    private const MAX_CUE_SECONDS = 20.0;

    private const MIN_MATCHES = 15;

    private const MAX_IQR = 3.0;

    /**
     * Per-window medians must agree within this. Shifting by the median then
     * leaves every part of the file within about 2s of the audio, where
     * unshifted it would be off by the whole offset. A file whose offset
     * drifts a little is still better shifted than not.
     */
    private const MAX_WINDOW_SPREAD = 4.0;

    /** Windows that differ by more than this are noted, but still shifted. */
    private const NOTE_WINDOW_SPREAD = 2.0;

    /** A window with fewer matches than this is too thin to have its own opinion. */
    private const MIN_WINDOW_MATCHES = 4;

    /** Agreement has to be shown by at least this many windows. */
    private const MIN_WINDOWS = 2;

    public function __construct(
        private WordTranscriber $transcriber,
        private AudioExtractor $audio,
        private int $windowCount = 5,
        private int $windowSeconds = 120,
        private int $skipSeconds = 180
    ) {
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments the captions
     * @param string $videoUrl the copy viewers play (files.path)
     */
    public function align(array $segments, string $videoUrl): CaptionAlignment
    {
        if (empty($segments)) {
            return CaptionAlignment::failed('no captions');
        }

        $spoken = [];
        $skipped = [];
        $starts = $this->windowStarts($segments);
        // A window can fall past the end of the video when the captions run
        // longer than it; the other windows still count.
        foreach ($this->audio->extractWindows($videoUrl, $starts, $this->windowSeconds) as $i => $path) {
            $start = $starts[$i];
            try {
                if ($path instanceof \Throwable) {
                    throw $path;
                }
                try {
                    $words = $this->transcriber->transcribeWords($path);
                } finally {
                    @unlink($path);
                }
            } catch (\Throwable $e) {
                $skipped[] = sprintf('%s skipped: %s', gmdate('H:i:s', (int) $start), $e->getMessage());
                continue;
            }
            foreach ($words as $j => $word) {
                $words[$j]['start'] += $start;
                $words[$j]['end'] += $start;
            }
            $spoken[] = ['start' => $start, 'words' => $words];
        }
        if (empty($spoken)) {
            throw new \RuntimeException('no window could be transcribed (' . implode('; ', $skipped) . ')');
        }

        $result = self::measure($segments, $spoken);
        if (empty($skipped)) {
            return $result;
        }

        return new CaptionAlignment(
            $result->aligned,
            $result->offset,
            $result->matches,
            $result->iqr,
            $result->windows,
            $result->drift,
            implode('; ', array_filter([$result->reason, implode('; ', $skipped)]))
        );
    }

    /**
     * Where to sample: spread across the stretch the captions cover, after the
     * first few minutes, which are often uncaptioned.
     *
     * @param array<int,array{start:float,end:float,text:string}> $segments
     * @return float[]
     */
    public function windowStarts(array $segments): array
    {
        $first = min(array_column($segments, 'start'));
        $last = max(array_column($segments, 'end'));

        $from = max($first, $this->skipSeconds);
        $to = $last - $this->windowSeconds;
        if ($to <= $from) {
            // Too short to skip anything: one window over what there is.
            return [max(0.0, min($first, $to))];
        }

        $count = max(1, min($this->windowCount, (int) floor(($to - $from) / $this->windowSeconds) + 1));
        if ($count === 1) {
            return [$from];
        }
        $starts = [];
        for ($i = 0; $i < $count; $i++) {
            $starts[] = round($from + ($to - $from) * $i / ($count - 1), 2);
        }

        return $starts;
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments the captions
     * @param array<int,array{start:float,words:list<array{word:string,start:float,end:float}>}> $spoken
     *        each sampled window, its words timed from the start of the video
     */
    public static function measure(array $segments, array $spoken): CaptionAlignment
    {
        $captionPhrases = self::uniquePhrases(self::captionWords($segments));

        $all = [];
        $windows = [];
        foreach ($spoken as $window) {
            $words = [];
            foreach ($window['words'] as $word) {
                foreach (self::tokens($word['word']) as $token) {
                    $words[] = ['word' => $token, 'time' => (float) $word['start']];
                }
            }
            // A phrase said twice in the window could match the wrong saying.
            $differences = [];
            foreach (self::uniquePhrases($words) as $phrase => $spokenAt) {
                if (isset($captionPhrases[$phrase])) {
                    $differences[] = $captionPhrases[$phrase] - $spokenAt;
                }
            }
            $all = array_merge($all, $differences);
            if (count($differences) >= self::MIN_WINDOW_MATCHES) {
                $windows[] = [
                    'start' => (float) $window['start'],
                    'median' => round(self::median($differences), 2),
                    'matches' => count($differences),
                ];
            }
        }

        $matches = count($all);
        if ($matches === 0) {
            return CaptionAlignment::failed('nothing said matches the captions');
        }
        $offset = round(self::median($all), 2);
        $iqr = round(self::quantile($all, 0.75) - self::quantile($all, 0.25), 2);

        $reasons = [];
        if ($matches < self::MIN_MATCHES) {
            $reasons[] = sprintf('only %d matches', $matches);
        }
        if ($iqr > self::MAX_IQR) {
            $reasons[] = sprintf('matches disagree (IQR %.1fs)', $iqr);
        }
        $drift = false;
        $notes = [];
        if (count($windows) < self::MIN_WINDOWS) {
            $reasons[] = sprintf('only %d window(s) matched', count($windows));
        } else {
            $medians = array_column($windows, 'median');
            $spread = max($medians) - min($medians);
            if ($spread > self::NOTE_WINDOW_SPREAD) {
                $drift = count($windows) >= 3 && self::monotonic($medians);
                $description = $drift
                    ? sprintf('drift: offset moves from %+.1fs to %+.1fs across the file', $medians[0], end($medians))
                    : sprintf('windows disagree by %.1fs', $spread);
                if ($spread > self::MAX_WINDOW_SPREAD) {
                    $reasons[] = $description;
                } else {
                    $notes[] = $description;
                }
            }
        }

        return new CaptionAlignment(empty($reasons), $offset, $matches, $iqr, $windows, $drift, implode('; ', array_merge($reasons, $notes)));
    }

    /**
     * Every caption word with an estimated time, in order. Cues are only a few
     * words long, so phrases are built across cue boundaries. Captions that roll
     * up (each cue repeating the end of the one before) are de-duplicated, or
     * every phrase would occur twice and none would count as unique.
     *
     * @return list<array{word:string,time:float}|null> null marks a break no phrase may cross
     */
    private static function captionWords(array $segments): array
    {
        usort($segments, static fn(array $a, array $b) => $a['start'] <=> $b['start']);

        $words = [];
        $previous = [];
        foreach ($segments as $segment) {
            $tokens = self::tokens((string) $segment['text']);
            if (empty($tokens)) {
                continue;
            }
            $duration = (float) $segment['end'] - (float) $segment['start'];
            if ($duration > self::MAX_CUE_SECONDS || $duration < 0) {
                $words[] = null;
                $previous = [];
                continue;
            }

            $all = count($tokens);
            $repeat = self::overlap($previous, $tokens);
            $previous = $tokens;
            foreach (array_slice($tokens, $repeat) as $i => $token) {
                // Spread the words evenly over the cue.
                $time = (float) $segment['start'] + $duration * ($repeat + $i) / $all;
                $words[] = ['word' => $token, 'time' => $time];
            }
        }

        return $words;
    }

    /**
     * How many leading words of a cue repeat the end of the cue before it. One
     * word in common is as likely to be said twice as to be repeated.
     */
    private static function overlap(array $previous, array $next): int
    {
        for ($k = min(count($previous), count($next)); $k >= 2; $k--) {
            if (array_slice($previous, -$k) === array_slice($next, 0, $k)) {
                return $k;
            }
        }

        return 0;
    }

    /**
     * Phrases that occur exactly once, with the time of their first word.
     *
     * @param list<array{word:string,time:float}|null> $words
     * @return array<string,float>
     */
    private static function uniquePhrases(array $words): array
    {
        $seen = [];
        $count = count($words);
        for ($i = 0; $i + self::PHRASE_WORDS <= $count; $i++) {
            $phrase = [];
            for ($j = $i; $j < $i + self::PHRASE_WORDS; $j++) {
                if ($words[$j] === null) {
                    continue 2;
                }
                $phrase[] = $words[$j]['word'];
            }
            if (strlen(implode('', $phrase)) < self::MIN_PHRASE_LETTERS) {
                continue;
            }
            $key = implode(' ', $phrase);
            $seen[$key] = array_key_exists($key, $seen) ? null : $words[$i]['time'];
        }

        return array_filter($seen, static fn($time) => $time !== null);
    }

    /** @return string[] lowercase, letters and digits only */
    private static function tokens(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', strtolower($text)) ?: [] as $token) {
            $token = (string) preg_replace('/[^a-z0-9]/', '', $token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private static function monotonic(array $values): bool
    {
        $up = true;
        $down = true;
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            $up = $up && $values[$i] > $values[$i - 1];
            $down = $down && $values[$i] < $values[$i - 1];
        }

        return $up || $down;
    }

    private static function median(array $values): float
    {
        return self::quantile($values, 0.5);
    }

    private static function quantile(array $values, float $q): float
    {
        sort($values);
        $position = (count($values) - 1) * $q;
        $low = (int) floor($position);
        $high = (int) ceil($position);

        return $values[$low] + ($values[$high] - $values[$low]) * ($position - $low);
    }
}
