#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Repair tool for backfilling incomplete video records.
 *
 * This script can re-queue videos for specific pipeline stages.
 *
 * Usage:
 *   php bin/repair.php --stage=screenshots              # Process all videos missing screenshots
 *   php bin/repair.php --stage=transcripts --limit=10   # Process 10 videos missing transcripts
 *   php bin/repair.php --stage=screenshots --id=123     # Re-process specific file ID
 *   php bin/repair.php --stage=transcripts --reset --id=123  # Delete existing data and re-process
 */

use Aws\S3\S3Client;
use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptGenerator;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['stage:', 'limit::', 'id::', 'reset', 'dry-run', 'help']);
$stage = $options['stage'] ?? null;
$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
$specificId = isset($options['id']) ? (int) $options['id'] : null;
$reset = isset($options['reset']);
$dryRun = isset($options['dry-run']);
$help = isset($options['help']);

if ($help || !$stage) {
    echo <<<HELP
Pipeline Repair Tool

Usage:
  php bin/repair.php --stage=NAME [options]

Required:
  --stage=NAME    Stage to repair (screenshots, transcripts, metadata, duplicates)

Options:
  --limit=N       Maximum number of records to process (default: 10)
  --id=N          Process only a specific file ID
  --reset         Delete existing data before re-processing (use with --id)
  --dry-run       Show what would be done without making changes
  --help          Show this help message

Stages:
  screenshots   Re-generate screenshots for videos missing capture_directory
  transcripts   Re-generate transcripts for videos missing transcript data
  metadata      Re-extract video metadata (length, dimensions, fps) from S3 videos
  duplicates    Remove duplicate video records (keeps most complete copy)

Examples:
  php bin/repair.php --stage=screenshots --limit=5
  php bin/repair.php --stage=transcripts --id=12345 --reset
  php bin/repair.php --stage=metadata --dry-run
  php bin/repair.php --stage=duplicates --dry-run
  php bin/repair.php --stage=duplicates --limit=100

HELP;
    exit($help ? 0 : 1);
}

$validStages = ['screenshots', 'transcripts', 'metadata', 'duplicates'];
if (!in_array($stage, $validStages, true)) {
    echo "Unknown stage: $stage\n";
    echo "Valid stages: " . implode(', ', $validStages) . "\n";
    exit(1);
}

// Initialize common dependencies
$s3Client = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => AWS_REGION,
    'version' => '2006-03-01',
]);
$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$committeeDirectory = new CommitteeDirectory($pdo);
$keyBuilder = new S3KeyBuilder();

switch ($stage) {
    case 'screenshots':
        repairScreenshots($pdo, $storage, $committeeDirectory, $keyBuilder, $log, $limit, $specificId, $reset, $dryRun);
        break;

    case 'transcripts':
        repairTranscripts($pdo, $log, $limit, $specificId, $reset, $dryRun);
        break;

    case 'metadata':
        repairMetadata($pdo, $log, $limit, $specificId, $dryRun);
        break;

    case 'duplicates':
        removeDuplicates($pdo, $log, $limit, $dryRun);
        break;
}

function repairScreenshots(
    PDO $pdo,
    S3Storage $storage,
    CommitteeDirectory $committeeDirectory,
    S3KeyBuilder $keyBuilder,
    Log $log,
    int $limit,
    ?int $specificId,
    bool $reset,
    bool $dryRun
): void {
    if ($specificId !== null) {
        $sql = "SELECT id, chamber, committee_id, title, date, path, capture_directory
            FROM files WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $specificId]);
    } else {
        $sql = "SELECT id, chamber, committee_id, title, date, path, capture_directory
            FROM files
            WHERE path LIKE 'https://video.richmondsunlight.com/%'
            AND (capture_directory IS NULL OR capture_directory = ''
                 OR (capture_directory NOT LIKE '/%' AND capture_directory NOT LIKE 'https://%'))
            ORDER BY date_created DESC
            LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No videos found needing screenshot repair.\n";
        return;
    }

    echo sprintf("Found %d video(s) for screenshot repair.\n", count($rows));

    if ($dryRun) {
        foreach ($rows as $row) {
            echo sprintf("  [DRY-RUN] Would process file #%d: %s\n", $row['id'], $row['title'] ?? 'Untitled');
        }
        return;
    }

    $generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log);

    foreach ($rows as $row) {
        if ($reset && $specificId !== null) {
            // Reset capture_directory to trigger re-processing
            $pdo->prepare('UPDATE files SET capture_directory = NULL, capture_rate = NULL WHERE id = :id')
                ->execute([':id' => $row['id']]);
            echo sprintf("Reset screenshot data for file #%d\n", $row['id']);
        }

        $job = new ScreenshotJob(
            (int) $row['id'],
            (string) $row['chamber'],
            $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
            (string) $row['date'],
            (string) $row['path'],
            null,
            $row['title'] ?? null
        );

        try {
            echo sprintf("Processing screenshots for file #%d...\n", $job->id);
            $generator->process($job);
            echo sprintf("  Completed file #%d\n", $job->id);
        } catch (Throwable $e) {
            echo sprintf("  Failed file #%d: %s\n", $job->id, $e->getMessage());
        }
    }
}

function repairTranscripts(
    PDO $pdo,
    Log $log,
    int $limit,
    ?int $specificId,
    bool $reset,
    bool $dryRun
): void {
    if ($specificId !== null) {
        $sql = "SELECT id, chamber, path, webvtt, srt, title FROM files WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $specificId]);
    } else {
        $sql = "SELECT f.id, f.chamber, f.path, f.webvtt, f.srt, f.title
            FROM files f
            WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            ORDER BY f.date_created DESC
            LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No videos found needing transcript repair.\n";
        return;
    }

    echo sprintf("Found %d video(s) for transcript repair.\n", count($rows));

    if ($dryRun) {
        foreach ($rows as $row) {
            echo sprintf("  [DRY-RUN] Would process file #%d: %s\n", $row['id'], $row['title'] ?? 'Untitled');
        }
        return;
    }

    // Initialize transcript dependencies
    $httpClient = new Client(['timeout' => 1800]);
    $writer = new TranscriptWriter($pdo);
    $generator = new TranscriptGenerator($httpClient, $writer, $log);

    foreach ($rows as $row) {
        if ($reset && $specificId !== null) {
            // Delete existing transcript rows
            $pdo->prepare('DELETE FROM video_transcript WHERE file_id = :id')
                ->execute([':id' => $row['id']]);
            $pdo->prepare('UPDATE files SET transcript = NULL, webvtt = NULL WHERE id = :id')
                ->execute([':id' => $row['id']]);
            echo sprintf("Reset transcript data for file #%d\n", $row['id']);
        }

        $job = new TranscriptJob(
            (int) $row['id'],
            (string) $row['chamber'],
            (string) $row['path'],
            $row['webvtt'] ?? null,
            $row['srt'] ?? null,
            $row['title'] ?? null
        );

        try {
            echo sprintf("Processing transcript for file #%d...\n", $job->id);
            $generator->process($job);
            echo sprintf("  Completed file #%d\n", $job->id);
        } catch (Throwable $e) {
            echo sprintf("  Failed file #%d: %s\n", $job->id, $e->getMessage());
        }
    }
}

function repairMetadata(
    PDO $pdo,
    Log $log,
    int $limit,
    ?int $specificId,
    bool $dryRun
): void {
    if ($specificId !== null) {
        $sql = "SELECT id, path, title FROM files WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $specificId]);
    } else {
        $sql = "SELECT id, path, title FROM files
            WHERE path LIKE 'https://video.richmondsunlight.com/%'
            AND (length IS NULL OR length = '' OR width IS NULL OR height IS NULL)
            ORDER BY date_created DESC
            LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No videos found needing metadata repair.\n";
        return;
    }

    echo sprintf("Found %d video(s) for metadata repair.\n", count($rows));

    if ($dryRun) {
        foreach ($rows as $row) {
            echo sprintf("  [DRY-RUN] Would process file #%d: %s\n", $row['id'], $row['title'] ?? 'Untitled');
        }
        return;
    }

    $extractor = new VideoMetadataExtractor();

    foreach ($rows as $row) {
        try {
            echo sprintf("Extracting metadata for file #%d...\n", $row['id']);

            // Download video to temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'meta_') . '.mp4';
            $cmd = sprintf('curl -sL %s -o %s', escapeshellarg($row['path']), escapeshellarg($tempFile));
            exec($cmd, $output, $status);

            if ($status !== 0) {
                throw new RuntimeException('Failed to download video for metadata extraction');
            }

            $meta = $extractor->extract($tempFile);
            @unlink($tempFile);

            $updateSql = 'UPDATE files SET length = :length, width = :width, height = :height, fps = :fps, date_modified = CURRENT_TIMESTAMP WHERE id = :id';
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                ':length' => $meta['length'] ?? null,
                ':width' => $meta['width'] ?? null,
                ':height' => $meta['height'] ?? null,
                ':fps' => $meta['fps'] ?? null,
                ':id' => $row['id'],
            ]);

            echo sprintf(
                "  Updated file #%d: %s, %dx%d, %.2f fps\n",
                $row['id'],
                $meta['length'] ?? 'unknown',
                $meta['width'] ?? 0,
                $meta['height'] ?? 0,
                $meta['fps'] ?? 0
            );
        } catch (Throwable $e) {
            echo sprintf("  Failed file #%d: %s\n", $row['id'], $e->getMessage());
        }
    }
}

function removeDuplicates(
    PDO $pdo,
    Log $log,
    int $limit,
    bool $dryRun
): void {
    // Find duplicate groups
    $duplicateSql = "
        SELECT chamber, date, committee_id, COUNT(*) as count
        FROM files
        WHERE chamber IS NOT NULL AND date IS NOT NULL
        GROUP BY chamber, date, committee_id
        HAVING COUNT(*) > 1
        ORDER BY count DESC, date DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($duplicateSql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $duplicateGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($duplicateGroups)) {
        echo "No duplicate groups found.\n";
        return;
    }

    echo sprintf("Found %d duplicate group(s) to process.\n\n", count($duplicateGroups));

    $totalRemoved = 0;

    foreach ($duplicateGroups as $group) {
        $chamber = $group['chamber'];
        $date = $group['date'];
        $committeeId = $group['committee_id'];
        $count = (int) $group['count'];

        $committeeLabel = $committeeId ? "committee $committeeId" : 'floor';
        echo sprintf("Processing: %s %s (%s) - %d copies\n", $chamber, $date, $committeeLabel, $count);

        // Get all records in this group
        $groupSql = "
            SELECT id, path, capture_directory, length, width, height, fps, transcript, webvtt,
                   date_created, date_modified,
                   (SELECT COUNT(*) FROM video_transcript WHERE file_id = files.id) as transcript_count,
                   (SELECT COUNT(*) FROM video_index WHERE file_id = files.id) as index_count
            FROM files
            WHERE chamber = :chamber AND date = :date
              AND " . ($committeeId ? "committee_id = :committee_id" : "(committee_id IS NULL OR committee_id = '')") . "
            ORDER BY date_created ASC
        ";

        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute([
            ':chamber' => $chamber,
            ':date' => $date,
            ':committee_id' => $committeeId,
        ]);
        $records = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        // Score each record by completeness
        $scored = [];
        foreach ($records as $record) {
            $score = 0;
            $score += !empty($record['path']) && str_contains($record['path'], 'video.richmondsunlight.com') ? 10 : 0;
            $score += !empty($record['capture_directory']) ? 5 : 0;
            $score += !empty($record['length']) ? 2 : 0;
            $score += !empty($record['width']) && !empty($record['height']) ? 2 : 0;
            $score += !empty($record['transcript']) || !empty($record['webvtt']) ? 3 : 0;
            $score += (int) $record['transcript_count'];
            $score += (int) $record['index_count'];

            $scored[] = [
                'id' => $record['id'],
                'score' => $score,
                'date_modified' => $record['date_modified'],
                'path' => $record['path'] ?? 'none',
            ];
        }

        // Sort by score (desc), then by date_modified (desc)
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $b['date_modified'] <=> $a['date_modified'];
        });

        // Keep the first (best) record, delete the rest
        $keep = array_shift($scored);
        $toDelete = $scored;

        echo sprintf("  Keeping file #%d (score: %d, path: %s)\n", $keep['id'], $keep['score'], substr($keep['path'], 0, 60));

        foreach ($toDelete as $record) {
            echo sprintf("  Deleting file #%d (score: %d)\n", $record['id'], $record['score']);

            if (!$dryRun) {
                try {
                    // Delete related records first
                    $pdo->prepare('DELETE FROM video_transcript WHERE file_id = :id')->execute([':id' => $record['id']]);
                    $pdo->prepare('DELETE FROM video_index WHERE file_id = :id')->execute([':id' => $record['id']]);
                    $pdo->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $record['id']]);
                    $totalRemoved++;
                } catch (Throwable $e) {
                    echo sprintf("    ERROR: Failed to delete file #%d: %s\n", $record['id'], $e->getMessage());
                }
            }
        }

        echo "\n";
    }

    if ($dryRun) {
        echo "[DRY-RUN] No changes made.\n";
    } else {
        echo sprintf("Removed %d duplicate record(s).\n", $totalRemoved);
    }
}
