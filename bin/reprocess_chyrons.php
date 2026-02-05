#!/usr/bin/env php
<?php

/**
 * Manually reprocess chyron extraction (bills and speakers) for specific video files.
 *
 * This script allows forcing re-extraction of OCR data for files that should have
 * chyrons but don't, or for testing new crop configurations.
 *
 * Usage:
 *   php bin/reprocess_chyrons.php --file-id=14913 [--bills] [--speakers] [--clear]
 *   php bin/reprocess_chyrons.php --file-id=14913 --bills --clear  # Clear and re-extract bills only
 *   php bin/reprocess_chyrons.php --file-id=14913 --speakers       # Re-extract speakers without clearing
 *
 * Options:
 *   --file-id=N     File ID to process (required)
 *   --bills         Process bill detection
 *   --speakers      Process speaker detection
 *   --clear         Clear existing records before processing (recommended)
 *   --help          Show this help
 *
 * If neither --bills nor --speakers is specified, both will be processed.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\StubDiarizer;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

if (!$pdo) {
    echo "ERROR: Unable to connect to database\n";
    exit(1);
}

$options = getopt('', ['file-id:', 'bills', 'speakers', 'clear', 'help']);

if (isset($options['help']) || !isset($options['file-id'])) {
    echo <<<HELP
Manually reprocess chyron extraction for specific video files.

Usage:
  php bin/reprocess_chyrons.php --file-id=N [options]

Options:
  --file-id=N     File ID to process (required)
  --bills         Process bill detection
  --speakers      Process speaker detection
  --clear         Clear existing records before processing
  --help          Show this help

Examples:
  # Reprocess both bills and speakers, clearing old data first
  php bin/reprocess_chyrons.php --file-id=14913 --clear

  # Reprocess only bills
  php bin/reprocess_chyrons.php --file-id=14913 --bills --clear

  # Reprocess only speakers without clearing
  php bin/reprocess_chyrons.php --file-id=14913 --speakers

HELP;
    exit(0);
}

$fileId = (int) $options['file-id'];
$processBills = isset($options['bills']);
$processSpeakers = isset($options['speakers']);
$clearExisting = isset($options['clear']);

// If neither specified, do both
if (!$processBills && !$processSpeakers) {
    $processBills = true;
    $processSpeakers = true;
}

// Fetch file metadata
$stmt = $pdo->prepare('
    SELECT id, chamber, committee_id, capture_directory, video_index_cache, date, path
    FROM files
    WHERE id = :id
');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    echo "ERROR: File #$fileId not found\n";
    exit(1);
}

echo "Processing file #$fileId\n";
echo "  Chamber: {$file['chamber']}\n";
echo "  Date: {$file['date']}\n";
echo "  Capture directory: {$file['capture_directory']}\n";
echo "\n";

// Extract metadata from cache
$metadata = null;
$eventType = 'floor';
if (!empty($file['video_index_cache'])) {
    $decoded = json_decode($file['video_index_cache'], true);
    if (is_array($decoded)) {
        $metadata = $decoded;
        if (!empty($decoded['event_type'])) {
            $eventType = $decoded['event_type'];
        }
    }
}

// Determine event type from capture directory if not in metadata
if (str_contains($file['capture_directory'] ?? '', '/committee/')) {
    $eventType = 'committee';
}

echo "  Event type: $eventType\n";

// Build manifest URL
function buildManifestUrl(string $captureDirectory): ?string
{
    if ($captureDirectory === '') {
        return null;
    }

    if (str_starts_with($captureDirectory, 'https://')) {
        $base = rtrim($captureDirectory, '/');
        if (str_ends_with($base, '/full')) {
            $base = substr($base, 0, -strlen('/full'));
        }
        return $base . '/manifest.json';
    }

    $path = preg_replace('#^/video/#', '/', $captureDirectory);
    $path = trim($path, '/');
    if (str_ends_with($path, '/full')) {
        $path = substr($path, 0, -strlen('/full'));
    }
    return sprintf(
        'https://video.richmondsunlight.com/%s/manifest.json',
        $path
    );
}

$manifestUrl = buildManifestUrl($file['capture_directory'] ?? '');
echo "  Manifest: $manifestUrl\n";
echo "\n";

// Clear existing records if requested
if ($clearExisting) {
    if ($processBills) {
        $deleteStmt = $pdo->prepare('DELETE FROM video_index WHERE file_id = :id AND type = \'bill\'');
        $deleteStmt->execute([':id' => $fileId]);
        $deleted = $deleteStmt->rowCount();
        echo "Cleared $deleted existing bill record(s)\n";
    }

    if ($processSpeakers) {
        $deleteStmt = $pdo->prepare('DELETE FROM video_index WHERE file_id = :id AND type = \'legislator\'');
        $deleteStmt->execute([':id' => $fileId]);
        $deleted = $deleteStmt->rowCount();
        echo "Cleared $deleted existing speaker record(s)\n";
    }

    echo "\n";
}

// Process bills
if ($processBills) {
    echo "Processing bill detection...\n";

    if (!$manifestUrl) {
        echo "  ERROR: No manifest URL available\n";
    } else {
        $processor = new BillDetectionProcessor(
            new ScreenshotManifestLoader(),
            new ScreenshotFetcher(),
            new BillTextExtractor(new TesseractOcrEngine()),
            new BillParser(),
            new BillResultWriter($pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            $log
        );

        $job = new BillDetectionJob(
            $fileId,
            $file['chamber'],
            $file['committee_id'] !== null ? (int) $file['committee_id'] : null,
            $eventType,
            $file['capture_directory'],
            $manifestUrl,
            $metadata,
            $file['date']
        );

        try {
            $processor->process($job);
            echo "  ✓ Bill detection complete\n";
        } catch (Throwable $e) {
            echo "  ✗ Bill detection failed: " . $e->getMessage() . "\n";
            echo "  Stack trace:\n";
            echo "  " . str_replace("\n", "\n  ", $e->getTraceAsString()) . "\n";
        }
    }

    echo "\n";
}

// Process speakers
if ($processSpeakers) {
    echo "Processing speaker detection...\n";

    if (!$manifestUrl) {
        echo "  ERROR: No manifest URL available\n";
    } else {
        $processor = new SpeakerDetectionProcessor(
            new SpeakerMetadataExtractor(),
            new StubDiarizer(), // Don't run expensive diarization for manual processing
            new OcrSpeakerExtractor(
                new ScreenshotManifestLoader(),
                new ScreenshotFetcher(),
                new SpeakerTextExtractor(new TesseractOcrEngine()),
                new SpeakerNameParser(),
                new SpeakerChamberConfig(),
                3,
                $log
            ),
            new LegislatorDirectory($pdo),
            new SpeakerResultWriter($pdo),
            $log
        );

        $job = new SpeakerJob(
            $fileId,
            $file['chamber'],
            $file['path'],
            $metadata,
            $eventType,
            $file['capture_directory'],
            $manifestUrl,
            $file['date']
        );

        try {
            $processor->process($job);
            echo "  ✓ Speaker detection complete\n";
        } catch (Throwable $e) {
            echo "  ✗ Speaker detection failed: " . $e->getMessage() . "\n";
            echo "  Stack trace:\n";
            echo "  " . str_replace("\n", "\n  ", $e->getTraceAsString()) . "\n";
        }
    }

    echo "\n";
}

// Show results
echo "Results:\n";

if ($processBills) {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as count, COUNT(DISTINCT screenshot) as screenshots
        FROM video_index
        WHERE file_id = :id AND type = \'bill\'
    ');
    $stmt->execute([':id' => $fileId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Bills: {$result['count']} record(s) across {$result['screenshots']} screenshot(s)\n";
}

if ($processSpeakers) {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as count, COUNT(DISTINCT screenshot) as screenshots
        FROM video_index
        WHERE file_id = :id AND type = \'legislator\'
    ');
    $stmt->execute([':id' => $fileId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Speakers: {$result['count']} record(s) across {$result['screenshots']} screenshot(s)\n";
}

echo "\nDone.\n";
