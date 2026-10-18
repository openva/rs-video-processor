<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAlignment;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionShift;

/**
 * Measures and corrects the caption offset of transcripts stored before the
 * transcript stage aligned captions (see CaptionAligner).
 *
 * Whether a stored transcript came from captions or from Whisper is not
 * recorded, and need not be: a Whisper transcript measures about zero and is
 * left alone. So does a file already corrected, which makes this safe to rerun.
 */
class TranscriptRealigner
{
    private CopyDurationCheck $copyCheck;

    public function __construct(private PDO $pdo, private CaptionAligner $aligner, ?CopyDurationCheck $copyCheck = null)
    {
        $this->copyCheck = $copyCheck ?? new CopyDurationCheck();
    }

    /**
     * Videos with a transcript or captions whose alignment has not been
     * recorded. Naming one ID ($fileId) selects it whatever is recorded.
     *
     * @return list<array{id:int,chamber:string,date:string,path:string,length:?string,video_index_cache:?string}>
     */
    public function candidates(int $limit, ?int $fileId = null): array
    {
        $sql = "SELECT f.id, f.chamber, f.date, f.path, f.length, f.video_index_cache FROM files f
                WHERE f.type = 'video' AND f.path LIKE 'http%'
                  AND (EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
                       OR (f.webvtt IS NOT NULL AND f.webvtt != ''))";
        $params = [];
        if ($fileId !== null) {
            $sql .= ' AND f.id = :id';
            $params[':id'] = $fileId;
        } else {
            $sql .= ' AND f.caption_alignment IS NULL';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY f.date DESC, f.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($params);

        return array_map(static fn(array $row) => [
            'id' => (int) $row['id'],
            'chamber' => (string) $row['chamber'],
            'date' => (string) $row['date'],
            'path' => (string) $row['path'],
            'length' => $row['length'],
            'video_index_cache' => $row['video_index_cache'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** How many videos candidates() would go through with no limit. */
    public function countCandidates(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM files f
             WHERE f.type = 'video' AND f.path LIKE 'http%' AND f.caption_alignment IS NULL
               AND (EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
                    OR (f.webvtt IS NOT NULL AND f.webvtt != ''))"
        )->fetchColumn();
    }

    /**
     * The captions to measure: the transcript rows, or, for a video with none,
     * its WebVTT.
     *
     * @param string $source 'rows', 'webvtt', or 'auto'
     * @return array{source:string,segments:array<int,array{start:float,end:float,text:string}>}
     */
    public function captions(int $fileId, string $source = 'auto'): array
    {
        if ($source !== 'webvtt') {
            $stmt = $this->pdo->prepare('SELECT time_start, time_end, text FROM video_transcript WHERE file_id = :id ORDER BY time_start, id');
            $stmt->execute([':id' => $fileId]);
            $segments = array_map(static fn(array $row) => [
                'start' => CaptionShift::toSeconds((string) $row['time_start']),
                'end' => CaptionShift::toSeconds((string) $row['time_end']),
                'text' => (string) $row['text'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
            if (!empty($segments) || $source === 'rows') {
                return ['source' => 'rows', 'segments' => $segments];
            }
        }

        $stmt = $this->pdo->prepare('SELECT webvtt FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);

        return ['source' => 'webvtt', 'segments' => (new CaptionParser())->parseWebVtt((string) $stmt->fetchColumn())];
    }

    /**
     * A copy that is another meeting's video (see CopyDurationCheck) is not
     * measured: its captions belong to a recording it does not hold.
     *
     * @param array<int,array{start:float,end:float,text:string}> $segments
     * @param array{path:string,length:?string,video_index_cache:?string} $video a row from candidates()
     */
    public function measure(array $segments, array $video): CaptionAlignment
    {
        $copy = $this->copyCheck->check($video);
        if ($copy['status'] === 'mismatch') {
            return CaptionAlignment::failed('wrong video: ' . $copy['reason']);
        }

        try {
            return $this->aligner->align($segments, $video['path']);
        } catch (\Throwable $e) {
            return CaptionAlignment::failed('could not measure: ' . $e->getMessage());
        }
    }

    /**
     * Record the measurement, and when it calls for a shift, move the transcript
     * rows and the WebVTT cues, all in one transaction. Speaker matches
     * (video_transcript.legislator_id, new_speaker) stay on their rows.
     *
     * A file whose alignment is already recorded keeps it unless this shifts it
     * again, in which case the offsets add up.
     */
    public function apply(int $fileId, CaptionAlignment $alignment): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT caption_alignment, caption_offset, webvtt FROM files WHERE id = :id');
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($alignment->shouldShift()) {
                $offset = (float) $alignment->offset;
                $rows = $this->pdo->prepare('SELECT id, time_start, time_end FROM video_transcript WHERE file_id = :id');
                $rows->execute([':id' => $fileId]);
                $update = $this->pdo->prepare('UPDATE video_transcript SET time_start = :start, time_end = :end WHERE id = :id');
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $update->execute([
                        ':start' => CaptionShift::time((string) $row['time_start'], $offset),
                        ':end' => CaptionShift::time((string) $row['time_end'], $offset),
                        ':id' => $row['id'],
                    ]);
                }
                $this->pdo->prepare('UPDATE files SET webvtt = :webvtt, caption_alignment = :status, caption_offset = :offset WHERE id = :id')
                    ->execute([
                        ':webvtt' => $file['webvtt'] === null ? null : CaptionShift::webVtt((string) $file['webvtt'], $offset),
                        ':status' => 'aligned',
                        ':offset' => round((float) ($file['caption_offset'] ?? 0) + $offset, 2),
                        ':id' => $fileId,
                    ]);
            } elseif ($file['caption_alignment'] === null) {
                $this->pdo->prepare('UPDATE files SET caption_alignment = :status, caption_offset = :offset WHERE id = :id')
                    ->execute([
                        ':status' => $alignment->status(),
                        ':offset' => $alignment->aligned ? 0.0 : null,
                        ':id' => $fileId,
                    ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
