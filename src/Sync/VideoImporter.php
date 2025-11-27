<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use DateInterval;
use DateTimeImmutable;
use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RuntimeException;

class VideoImporter
{
    public function __construct(
        private PDO $pdo,
        private CommitteeDirectory $committees,
        private ?Log $logger = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function import(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO files (
                chamber, committee_id, title, description, type, length, date, sponsor,
                width, height, fps, capture_rate, capture_directory, path,
                author_name, license, date_created, date_modified, video_index_cache
            ) VALUES (
                :chamber, :committee_id, :title, :description, :type, :length, :date, :sponsor,
                :width, :height, :fps, :capture_rate, :capture_directory, :path,
                :author_name, :license, :date_created, :date_modified, :video_index_cache
            )'
        );

        $count = 0;

        foreach ($records as $record) {
            $payload = $this->buildPayload($record);
            if ($payload === null) {
                $this->logger?->put('Skipping record with insufficient metadata for insertion', 4);
                continue;
            }

            try {
                if ($insert->execute($payload) === false) {
                    throw new RuntimeException('Failed to insert video record into files table.');
                }
            } catch (\Throwable $e) {
                $path = $payload['path'] ?? '';
                $title = $payload['title'] ?? '';
                $this->logger?->put(
                    sprintf(
                        'Insert failed. Title length=%d Title sample=%s | Path length=%d Path sample=%s',
                        strlen((string) $title),
                        substr((string) $title, 0, 200),
                        strlen((string) $path),
                        substr((string) $path, 0, 200)
                    ),
                    6
                );
                throw $e;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildPayload(array $record): ?array
    {
        $chamber = isset($record['chamber']) ? strtolower((string) $record['chamber']) : null;
        $title = $record['title'] ?? 'Video';
        $date = VideoRecordNormalizer::deriveMeetingDate($record);
        $duration = VideoRecordNormalizer::deriveDurationSeconds($record);
        if (!$chamber || !$date || empty($record['video_url'])) {
            $this->logger?->put(
                sprintf(
                    'Skipping record: chamber=%s date=%s duration=%s path=%s title=%s',
                    var_export($chamber, true),
                    var_export($date, true),
                    var_export($duration, true),
                    isset($record['video_url']) ? substr((string) $record['video_url'], 0, 120) : 'NULL',
                    isset($record['title']) ? substr((string) $record['title'], 0, 120) : ''
                ),
                5
            );
            return null;
        }

        $now = new DateTimeImmutable('now');
        $eventType = strtolower($record['event_type'] ?? 'floor');
        $committeeName = $record['committee_name'] ?? null;
        $committeeEntry = $committeeName ? $this->committees->matchEntry($committeeName, $chamber, $eventType === 'subcommittee' ? 'subcommittee' : 'committee') : null;
        $committeeId = $committeeEntry['id'] ?? null;

        return [
            'chamber' => $chamber,
            'committee_id' => $committeeId,
            'title' => $title,
            'description' => $record['description'] ?? '',
            'type' => 'video',
            'length' => $duration !== null ? $this->formatDuration($duration) : null,
            'date' => $date,
            'sponsor' => $committeeName,
            'width' => $record['width'] ?? null,
            'height' => $record['height'] ?? null,
            'fps' => $record['fps'] ?? null,
            'capture_rate' => $record['capture_rate'] ?? null,
            'capture_directory' => $record['capture_directory'] ?? null,
            'path' => $record['video_url'] ?? null,
            'author_name' => $record['speaker'] ?? null,
            'license' => $record['license'] ?? 'public-domain',
            'date_created' => $now->format('Y-m-d H:i:s'),
            'date_modified' => $now->format('Y-m-d H:i:s'),
            'video_index_cache' => json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max($seconds, 0);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
