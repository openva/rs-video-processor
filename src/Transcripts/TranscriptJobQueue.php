<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use PDO;

class TranscriptJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return TranscriptJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = "SELECT f.id, f.chamber, f.path, f.webvtt, f.srt, f.title
            FROM files f
            WHERE f.path LIKE 'https://s3.amazonaws.com/video.richmondsunlight.com/%'
              AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            ORDER BY f.date_created ASC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new TranscriptJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['path'],
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $row['title'] ?? null
            );
        }

        return $jobs;
    }
}
