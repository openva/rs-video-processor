<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

use PDO;

class ClassificationCorrectionWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function correct(int $fileId, ?int $committeeId, string $title, string $eventType, ?array $existingCache): void
    {
        $cache = $existingCache ?? [];
        $cache['event_type'] = $eventType;
        $cache['classification_verified'] = true;

        $stmt = $this->pdo->prepare(
            'UPDATE files SET committee_id = :committee_id, title = :title, video_index_cache = :cache WHERE id = :id'
        );
        $stmt->execute([
            ':committee_id' => $committeeId,
            ':title' => $title,
            ':cache' => json_encode($cache),
            ':id' => $fileId,
        ]);
    }

    public function markVerified(int $fileId, ?array $existingCache): void
    {
        $cache = $existingCache ?? [];
        $cache['classification_verified'] = true;

        $stmt = $this->pdo->prepare(
            'UPDATE files SET video_index_cache = :cache WHERE id = :id'
        );
        $stmt->execute([
            ':cache' => json_encode($cache),
            ':id' => $fileId,
        ]);
    }
}
