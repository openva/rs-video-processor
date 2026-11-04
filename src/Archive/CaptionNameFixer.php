<?php

namespace RichmondSunlight\VideoProcessor\Archive;

/**
 * What to rename in an Internet Archive item so that the archive's player
 * shows its captions. The player pairs captions with a video by name
 * ("myvid.mp4" with "myvid.en.vtt"), and uploads before November 2026 gave
 * the captions a random temporary name instead (ia_vtt_….vtt).
 *
 * Used by bin/fix_archive_caption_names.php.
 */
class CaptionNameFixer
{
    /** The longest gap seen between a video and its captions was 15 seconds. */
    private const SAME_UPLOAD_SECONDS = 120;

    /**
     * The item a files.path on archive.org belongs to: a download URL
     * (https://archive.org/download/{identifier}/{file}) or an older details URL.
     */
    public static function identifierFromPath(string $path): ?string
    {
        if (!preg_match('#^https://archive\.org/(?:download|details)/([^/?\#]+)#', $path, $m)) {
            return null;
        }

        return rawurldecode($m[1]);
    }

    /**
     * The file a download URL names within its item (decoded), or null for a
     * details URL or anything else.
     */
    public static function fileFromPath(string $path): ?string
    {
        if (!preg_match('#^https://archive\.org/download/[^/]+/(.+)$#', $path, $m)) {
            return null;
        }

        return rawurldecode($m[1]);
    }

    /**
     * @param string $path the video's files.path
     * @param array<string,mixed> $item the archive's metadata API reply for its item
     * @return array{action:string, reason:string, from?:string, to?:string}
     *         action is "rename" (from and to are file names in the item),
     *         "ok" (already matched), "none" (no captions) or "skip" (see reason)
     */
    public static function plan(string $path, array $item): array
    {
        $videos = [];
        $captions = [];
        foreach ($item['files'] ?? [] as $file) {
            $name = $file['name'] ?? null;
            if (($file['source'] ?? '') !== 'original' || !is_string($name)) {
                continue;
            }
            $mtime = isset($file['mtime']) ? (int) $file['mtime'] : null;
            if (str_ends_with(strtolower($name), '.mp4')) {
                $videos[$name] = $mtime;
            } elseif (preg_match('/\.(vtt|srt)$/i', $name)) {
                $captions[$name] = $mtime;
            }
        }

        $video = self::videoName($path, array_keys($videos));
        if ($video === null) {
            return ['action' => 'skip', 'reason' => sprintf('could not tell which of the item\'s %d video(s) is this one', count($videos))];
        }

        // Captions already named after one of the item's videos are that video's.
        foreach (array_keys($captions) as $name) {
            foreach (array_keys($videos) as $other) {
                if (self::matches($name, $other)) {
                    if ($other === $video) {
                        return ['action' => 'ok', 'reason' => $name . ' already matches ' . $video];
                    }
                    unset($captions[$name]);
                }
            }
        }
        if ($captions === []) {
            return ['action' => 'none', 'reason' => 'the item has no captions for ' . $video];
        }

        $from = count($videos) === 1 && count($captions) === 1
            ? array_key_first($captions)
            : self::uploadedWith($videos[$video], $captions);
        if ($from === null) {
            return ['action' => 'skip', 'reason' => sprintf(
                '%d caption files (%s), and not one stored just after %s',
                count($captions),
                implode(', ', array_keys($captions)),
                $video
            )];
        }

        $to = substr($video, 0, -4) . '.en.' . strtolower(pathinfo($from, PATHINFO_EXTENSION));

        return ['action' => 'rename', 'reason' => '', 'from' => $from, 'to' => $to];
    }

    /**
     * The captions sent in the same ia upload as the video: ia sends them
     * right after it, so the archive stored them seconds later (1 to 15 in
     * the items looked at). Null unless exactly one fits.
     *
     * @param array<string,?int> $captions name => mtime
     */
    private static function uploadedWith(?int $videoTime, array $captions): ?string
    {
        if ($videoTime === null) {
            return null;
        }
        $fits = array_filter(
            $captions,
            static fn(?int $t) => $t !== null && $t >= $videoTime && $t - $videoTime <= self::SAME_UPLOAD_SECONDS
        );

        return count($fits) === 1 ? (string) array_key_first($fits) : null;
    }

    /** "myvid.vtt" or "myvid.en.vtt" for "myvid.mp4". */
    private static function matches(string $caption, string $video): bool
    {
        return preg_match('/^' . preg_quote(substr($video, 0, -4), '/') . '(\.[A-Za-z-]+)?\.(vtt|srt)$/i', $caption) === 1;
    }

    /**
     * The video's file name in the item: the one files.path names, or, for a
     * details URL, the item's only original MP4.
     *
     * @param string[] $videos the item's original MP4s
     */
    private static function videoName(string $path, array $videos): ?string
    {
        $name = self::fileFromPath($path);
        if ($name !== null) {
            return in_array($name, $videos, true) ? $name : null;
        }

        return count($videos) === 1 ? $videos[0] : null;
    }
}
