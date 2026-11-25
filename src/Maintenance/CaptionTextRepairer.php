<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;
use RichmondSunlight\VideoProcessor\Archive\ArchiveCaptions;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

/**
 * Repairs transcripts stored before CaptionParser cleaned caption text (November 2026):
 * YouTube's rolling captions stored with every line three times, and "&gt;&gt;" (an escaped
 * speaker mark) stored as text, which the website escaped again. See CaptionParser::unroll().
 *
 * TranscriptWriter wrote one video_transcript row per WebVTT cue, in order, so each row is
 * matched with its cue in files.webvtt: the rows of repeated lines are deleted and the others
 * get their cleaned text, in place, so the website's speaker matches (legislator_id,
 * new_speaker) stay on the rows that remain. A video whose rows don't match its cues is left
 * alone and reported. A repaired transcript parses to itself, so this is safe to rerun.
 */
class CaptionTextRepairer
{
    private CaptionParser $parser;

    public function __construct(private PDO $pdo, ?CaptionParser $parser = null)
    {
        $this->parser = $parser ?? new CaptionParser();
    }

    /**
     * Videos with a stored transcript and WebVTT, after $afterId, by ID.
     *
     * @return list<int>
     */
    public function candidates(int $afterId, int $batchSize): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.id FROM files f
             WHERE f.id > :after AND f.webvtt IS NOT NULL AND f.webvtt != ''
               AND EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
             ORDER BY f.id LIMIT " . max(1, $batchSize)
        );
        $stmt->execute([':after' => $afterId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * What repair() would do to this video, without doing it.
     *
     * @return array{status:string,rows:int,deleted:int,changed:int,reason?:string} status is
     *         'clean' (nothing to repair), 'repairable', or 'mismatch' (rows and cues differ)
     */
    public function inspect(int $fileId): array
    {
        return $this->plan($fileId)['summary'];
    }

    /**
     * Repair this video's transcript rows, files.webvtt and files.transcript in one
     * transaction, and ask for its captions to be sent to the Internet Archive again if it's
     * there. Does nothing unless inspect() says 'repairable'.
     *
     * @return array{status:string,rows:int,deleted:int,changed:int,reason?:string}
     */
    public function repair(int $fileId): array
    {
        $this->pdo->beginTransaction();
        try {
            $plan = $this->plan($fileId);
            if ($plan['summary']['status'] !== 'repairable') {
                $this->pdo->commit();
                return $plan['summary'];
            }

            $delete = $this->pdo->prepare('DELETE FROM video_transcript WHERE id = :id');
            $update = $this->pdo->prepare('UPDATE video_transcript SET text = :text WHERE id = :id');
            foreach ($plan['rows'] as $i => $row) {
                $segment = $plan['unrolled'][$i];
                if ($segment === null) {
                    $delete->execute([':id' => $row['id']]);
                } elseif ($this->rowText($segment['text']) !== $row['text']) {
                    $update->execute([':text' => $this->rowText($segment['text']), ':id' => $row['id']]);
                }
            }

            $segments = array_values(array_filter($plan['unrolled']));
            foreach ($segments as $i => $segment) {
                $segments[$i]['text'] = TranscriptWriter::sanitizeForUtf8mb3($segment['text']);
            }
            $this->pdo->prepare('UPDATE files SET transcript = :transcript, webvtt = :webvtt, date_modified = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([
                    ':transcript' => TranscriptWriter::generatePlainText($segments),
                    ':webvtt' => TranscriptWriter::generateWebVtt($segments),
                    ':id' => $fileId,
                ]);
            ArchiveCaptions::requestIfArchived($this->pdo, $fileId);

            $this->pdo->commit();
            return $plan['summary'];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{
     *     summary: array{status:string,rows:int,deleted:int,changed:int,reason?:string},
     *     rows: list<array{id:int,text:string}>,
     *     unrolled: list<array{start:float,end:float,text:string}|null>
     * }
     */
    private function plan(int $fileId): array
    {
        $stmt = $this->pdo->prepare('SELECT webvtt FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);
        $cues = $this->parser->webVttCues((string) $stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT id, text FROM video_transcript WHERE file_id = :id ORDER BY id');
        $stmt->execute([':id' => $fileId]);
        $rows = array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'text' => (string) $row['text']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $summary = ['status' => 'clean', 'rows' => count($rows), 'deleted' => 0, 'changed' => 0];
        $plan = ['summary' => $summary, 'rows' => $rows, 'unrolled' => []];

        $reason = $this->mismatch($rows, $cues);
        if ($reason !== null) {
            $plan['summary'] = ['status' => 'mismatch', 'reason' => $reason] + $summary;
            return $plan;
        }

        $plan['unrolled'] = $this->parser->unroll($cues);
        foreach ($rows as $i => $row) {
            $segment = $plan['unrolled'][$i];
            if ($segment === null) {
                $summary['deleted']++;
            } elseif ($this->rowText($segment['text']) !== $row['text']) {
                $summary['changed']++;
            }
        }
        if ($summary['deleted'] > 0 || $summary['changed'] > 0) {
            $summary['status'] = 'repairable';
        }
        $plan['summary'] = $summary;

        return $plan;
    }

    /**
     * Why these rows can't be matched with these cues one for one, or null if they can.
     *
     * @param list<array{id:int,text:string}> $rows
     * @param list<array{start:float,end:float,text:string}> $cues
     */
    private function mismatch(array $rows, array $cues): ?string
    {
        if (count($rows) !== count($cues)) {
            return sprintf('%d transcript rows, %d WebVTT cues', count($rows), count($cues));
        }
        foreach ($rows as $i => $row) {
            // A row holds at most 255 bytes, so compare its beginning only.
            $start = mb_substr($this->parser->cleanText($row['text']), 0, 20);
            if (!str_starts_with($cues[$i]['text'], $start)) {
                return sprintf('row %d says "%s", cue %d says "%s"', $row['id'], $row['text'], $i + 1, $cues[$i]['text']);
            }
        }
        return null;
    }

    /** As TranscriptWriter stores it: video_transcript.text is TINYTEXT. */
    private function rowText(string $text): string
    {
        return mb_strcut(TranscriptWriter::sanitizeForUtf8mb3($text), 0, 255, 'UTF-8');
    }
}
