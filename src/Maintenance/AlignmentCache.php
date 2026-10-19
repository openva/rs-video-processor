<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use RichmondSunlight\VideoProcessor\Transcripts\CaptionAlignment;

/**
 * Measurements bin/align_transcripts.php has paid for, kept in a local JSON
 * file so a dry run's measurements are reused by later runs, --apply included.
 * The database is not touched.
 *
 * Each entry is keyed by file ID and a fingerprint of the captions measured
 * and the copy they were measured against, so a measurement is reused only
 * while both are unchanged. Shifting a transcript changes its fingerprint.
 */
class AlignmentCache
{
    /** @var array<string,array{fingerprint:string,alignment:array<string,mixed>}> */
    private array $entries = [];

    public function __construct(private string $path)
    {
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            $this->entries = is_array($data) ? $data : [];
        }
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    public static function fingerprint(array $segments, string $videoUrl): string
    {
        return sha1($videoUrl . "\n" . json_encode($segments));
    }

    public function get(int $fileId, string $fingerprint): ?CaptionAlignment
    {
        $entry = $this->entries[(string) $fileId] ?? null;
        if ($entry === null || $entry['fingerprint'] !== $fingerprint) {
            return null;
        }

        return CaptionAlignment::fromArray($entry['alignment']);
    }

    public function put(int $fileId, string $fingerprint, CaptionAlignment $alignment): void
    {
        $this->entries[(string) $fileId] = ['fingerprint' => $fingerprint, 'alignment' => $alignment->toArray()];

        // Written after every measurement, so an interrupted run loses nothing.
        $temporary = $this->path . '.tmp';
        file_put_contents($temporary, json_encode($this->entries, JSON_PRETTY_PRINT));
        rename($temporary, $this->path);
    }
}
