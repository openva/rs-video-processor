<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

/**
 * Stores a transcript made on another machine by bin/transcribe_backlog.php
 * (see TranscriptBacklog), as the transcript stage would have stored it.
 */
final class BacklogImporter
{
    public const STORED = 'stored';
    public const WOULD_STORE = 'would_store';
    /** The video has a transcript already: from an earlier import, or the transcript stage. */
    public const HAS_TRANSCRIPT = 'has_transcript';
    public const INVALID = 'invalid';

    public function __construct(
        private PDO $pdo,
        private TranscriptWriter $writer,
        private JobLedger $ledger
    ) {
    }

    /**
     * @param mixed $made the decoded file: file_id, model, segments
     * @return string one of the constants above
     */
    public function import(mixed $made, bool $apply): string
    {
        if (!is_array($made) || !is_numeric($made['file_id'] ?? null) || !is_array($made['segments'] ?? null) || $made['segments'] === []) {
            return self::INVALID;
        }
        $segments = [];
        foreach ($made['segments'] as $segment) {
            if (!is_numeric($segment['start'] ?? null) || !is_numeric($segment['end'] ?? null) || !is_string($segment['text'] ?? null)) {
                return self::INVALID;
            }
            $segments[] = ['start' => (float) $segment['start'], 'end' => (float) $segment['end'], 'text' => $segment['text']];
        }
        $fileId = (int) $made['file_id'];

        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE id = :id');
        $exists->execute([':id' => $fileId]);
        if ((int) $exists->fetchColumn() === 0) {
            return self::INVALID;
        }
        $has = $this->pdo->prepare('SELECT COUNT(*) FROM video_transcript WHERE file_id = :id');
        $has->execute([':id' => $fileId]);
        if ((int) $has->fetchColumn() > 0) {
            return self::HAS_TRANSCRIPT;
        }
        if (!$apply) {
            return self::WOULD_STORE;
        }

        // Whisper times its output from the audio; there is nothing to align.
        // The writer also asks for the captions to be sent to the archive.
        $this->writer->write($fileId, $segments, 'whisper');
        $this->ledger->recordSuccess($fileId, JobLedger::STAGE_TRANSCRIPT);

        return self::STORED;
    }
}
