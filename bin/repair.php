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
  --stage=NAME    Stage to repair (screenshots, transcripts, metadata)

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

Examples:
  php bin/repair.php --stage=screenshots --limit=5
  php bin/repair.php --stage=transcripts --id=12345 --reset
  php bin/repair.php --stage=metadata --dry-run

HELP;
    exit($help ? 0 : 1);
}

$validStages = ['screenshots', 'transcripts', 'metadata'];
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
