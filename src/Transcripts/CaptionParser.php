<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

class CaptionParser
{
    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function parseWebVtt(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents); // remove BOM
        $contents = str_replace("\r", '', $contents);
        $segments = [];
        $pattern = '/(\d{2}:\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3}).*?\n(.+?)(?=\n\n|$)/s';
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = trim(preg_replace('/\n+/', ' ', strip_tags($match[3])));
                if ($text === '') {
                    continue;
                }
                $segments[] = [
                    'start' => $this->timeToSeconds($match[1]),
                    'end' => $this->timeToSeconds($match[2]),
                    'text' => $text,
                ];
            }
        }
        return $segments;
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
            $text = trim($text);
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
