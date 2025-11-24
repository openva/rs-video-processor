<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;
use PDOException;

class ExistingFilesRepository implements ExistingVideoKeyProviderInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function fetchKeys(): array
    {
        $keys = [];

        try {
            $stmt = $this->pdo->query('SELECT chamber, date, TIME_TO_SEC(length) AS duration_seconds FROM files');
        } catch (PDOException $e) {
            throw new \RuntimeException('Unable to query files table: ' . $e->getMessage(), 0, $e);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chamber = $row['chamber'] ?? null;
            $date = $row['date'] ?? null;
            $duration = $row['duration_seconds'] ?? null;

            if ($chamber === null || $date === null || $duration === null) {
                continue;
            }

            $durationInt = (int) $duration;
            $keys[$this->buildKey($chamber, $date, $durationInt)] = true;
        }

        return $keys;
    }

    private function buildKey(string $chamber, string $date, int $durationSeconds): string
    {
        return strtolower($chamber) . '|' . $date . '|' . $durationSeconds;
    }
}
