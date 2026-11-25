<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

class CaptionParser
{
    /**
     * A cue this short (YouTube's are 10 ms) is never on screen long enough to read: in
     * YouTube's automatic captions it only repeats the line before. See unroll().
     */
    private const FLEETING_CUE_SECONDS = 0.05;

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function parseWebVtt(string $contents): array
    {
        return array_values(array_filter($this->unroll($this->webVttCues($contents))));
    }

    /**
     * Every cue in the file, in order, with its text cleaned (cleanText()); a cue may be left
     * with no text. parseWebVtt() is these, unrolled, without the empty ones.
     *
     * @return list<array{start:float,end:float,text:string}>
     */
    public function webVttCues(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents); // remove BOM
        $contents = str_replace("\r", '', $contents);
        $cues = [];
        $pattern = '/(\d{2}:\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3}).*?\n(.+?)(?=\n\n|$)/s';
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $cues[] = [
                    'start' => $this->timeToSeconds($match[1]),
                    'end' => $this->timeToSeconds($match[2]),
                    'text' => $this->cleanText($match[3]),
                ];
            }
        }
        return $cues;
    }

    /**
     * YouTube's automatic captions show two lines at a time and roll up: each line is a cue's
     * new bottom line, then a fleeting cue of its own, then the top line of the next cue. Read
     * as they are, every line appears three times. So a fleeting cue is dropped, and a cue that
     * begins with the line of the fleeting cue just before it keeps only what follows.
     *
     * Captions without fleeting cues (Granicus, House, Whisper) come through unchanged, even
     * where one cue repeats another ("Aye." "Aye.").
     *
     * Transcripts stored before November 2026 still hold the fleeting cues and the repeated
     * lines, one cue per stored line, so this repairs them too (bin/repair_caption_text.php).
     *
     * @param list<array{start:float,end:float,text:string}> $cues
     * @return list<array{start:float,end:float,text:string}|null> one per cue, in order; null
     *         where a cue is dropped or left with no text
     */
    public function unroll(array $cues): array
    {
        $unrolled = [];
        $fleeting = null;
        foreach ($cues as $cue) {
            if ($cue['end'] - $cue['start'] < self::FLEETING_CUE_SECONDS) {
                $unrolled[] = null;
                $fleeting = $cue['text'];
                continue;
            }

            $text = $cue['text'];
            if ($fleeting !== null && $fleeting !== '') {
                if ($text === $fleeting) {
                    $text = '';
                } elseif (str_starts_with($text, $fleeting . ' ')) {
                    $text = substr($text, strlen($fleeting) + 1);
                }
            }
            $fleeting = null;

            $unrolled[] = $text === '' ? null : ['start' => $cue['start'], 'end' => $cue['end'], 'text' => $text];
        }
        return $unrolled;
    }

    /**
     * A cue's text as a transcript line: no markup (YouTube times each word with tags), HTML
     * entities decoded (WebVTT writes ">" as "&gt;", and the website escapes the text again),
     * no ">>" (captioners mark a change of speaker with it), on one line.
     */
    public function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", '>>'], ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function parseSrt(string $contents): array
    {
        $contents = str_replace("\r", '', $contents);
        $chunks = preg_split('/\n\s*\n/', trim($contents));
        $segments = [];
        $timePattern = '/(\d{2}:\d{2}:\d{2},\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2},\d{3})/';
        foreach ($chunks as $chunk) {
            if (!preg_match($timePattern, $chunk, $match)) {
                continue;
            }
            $text = trim(preg_replace(['/^\d+\n/','/\n+/'], ['', ' '], $chunk));
            $text = preg_replace($timePattern, '', $text);
            $text = $this->cleanText($text);
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start' => $this->timeToSeconds(str_replace(',', '.', $match[1])),
                'end' => $this->timeToSeconds(str_replace(',', '.', $match[2])),
                'text' => $text,
            ];
        }
        return $segments;
    }

    private function timeToSeconds(string $timecode): float
    {
        [$h, $m, $s] = preg_split('/:/', $timecode);
        $seconds = (float) $s;
        return ((int) $h * 3600) + ((int) $m * 60) + $seconds;
    }
}
