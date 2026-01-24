#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnostic tool for the video processing pipeline.
 *
 * Shows the state of all records and identifies incomplete records at each stage.
 *
 * Usage:
 *   php bin/diagnose.php              # Show summary
 *   php bin/diagnose.php --verbose    # Show summary + list incomplete record IDs
 *   php bin/diagnose.php --stage=X    # Focus on specific stage
 */

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$options = getopt('', ['verbose', 'stage::', 'help']);
$verbose = isset($options['verbose']);
$focusStage = $options['stage'] ?? null;
$help = isset($options['help']);

if ($help) {
    echo <<<HELP
Pipeline Diagnostic Tool

Usage:
  php bin/diagnose.php [options]

Options:
  --verbose       Show list of file IDs at each stage
  --stage=NAME    Focus on specific stage (download, screenshots, transcripts, bills, speakers, archive)
  --help          Show this help message

Stages:
  download     Videos that need to be downloaded (no S3 path)
  screenshots  Videos with S3 path but no screenshots generated
  transcripts  Videos with S3 path but no transcript rows
  bills        Videos with screenshots but no bill detection
  speakers     Videos with S3 path but no speaker detection
  archive      Videos ready for archive.org upload

HELP;
    exit(0);
}

$stages = [
    'download' => [
        'label' => 'Pending Download',
        'description' => 'Videos with source URL but not yet downloaded to S3',
        'query' => "SELECT id FROM files
            WHERE (path IS NULL OR path = '' OR (
                path NOT LIKE 'https://video.richmondsunlight.com/%'
                AND path NOT LIKE 'https://archive.org/%'
            ))
            AND video_index_cache IS NOT NULL
            AND video_index_cache LIKE '{%'
            ORDER BY date_created DESC",
    ],
    'screenshots' => [
        'label' => 'Pending Screenshots',
        'description' => 'Videos with playable URLs but screenshots not generated',
        'query' => "SELECT id FROM files
            WHERE (path LIKE 'https://video.richmondsunlight.com/%'
              OR path LIKE 'https://archive.org/%')
            AND (capture_directory IS NULL OR capture_directory = ''
                 OR (capture_directory NOT LIKE '/%' AND capture_directory NOT LIKE 'https://%'))
            ORDER BY date_created DESC",
    ],
    'transcripts' => [
        'label' => 'Pending Transcripts',
        'description' => 'Videos on S3 but no transcript in video_transcript table',
        'query' => "SELECT f.id FROM files f
            WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            ORDER BY f.date_created DESC",
    ],
    'bills' => [
        'label' => 'Pending Bill Detection',
        'description' => 'Videos with screenshots but no bill detection in video_index',
        'query' => "SELECT f.id FROM files f
            WHERE f.capture_directory IS NOT NULL AND f.capture_directory != ''
            AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
            AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'bill')
            ORDER BY f.date_created DESC",
    ],
    'speakers' => [
        'label' => 'Pending Speaker Detection',
        'description' => 'Videos with playable URLs but no speaker detection in video_index',
        'query' => "SELECT f.id FROM files f
            WHERE (f.path LIKE 'https://video.richmondsunlight.com/%'
              OR f.path LIKE 'https://archive.org/%')
            AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'legislator')
            ORDER BY f.date_created DESC",
    ],
    'archive' => [
        'label' => 'Ready for Archive.org',
        'description' => 'Videos on S3 with transcripts, ready for archive.org upload',
        'query' => "SELECT f.id FROM files f
            WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.webvtt IS NOT NULL OR f.srt IS NOT NULL)
            ORDER BY f.date_created DESC",
    ],
];

// Get total counts
$totalFiles = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
$s3Files = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE path LIKE 'https://video.richmondsunlight.com/%'")->fetchColumn();
$archivedFiles = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE path LIKE 'https://archive.org/%'")->fetchColumn();

echo "\n=== Video Processing Pipeline Status ===\n\n";
echo sprintf("Total files in database: %d\n", $totalFiles);
echo sprintf("Files on S3: %d\n", $s3Files);
echo sprintf("Files archived to archive.org: %d\n", $archivedFiles);
echo "\n";

// If focusing on a specific stage
if ($focusStage !== null) {
    if (!isset($stages[$focusStage])) {
        echo "Unknown stage: $focusStage\n";
        echo "Valid stages: " . implode(', ', array_keys($stages)) . "\n";
        exit(1);
    }
    $stages = [$focusStage => $stages[$focusStage]];
}

foreach ($stages as $key => $stage) {
    $stmt = $pdo->query($stage['query']);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = count($ids);

    echo sprintf("%-25s %d\n", $stage['label'] . ':', $count);
    echo sprintf("  %s\n", $stage['description']);

    if ($verbose && $count > 0) {
        if ($count <= 20) {
            echo sprintf("  IDs: %s\n", implode(', ', $ids));
        } else {
            $sample = array_slice($ids, 0, 20);
            echo sprintf("  IDs (first 20): %s ...\n", implode(', ', $sample));
        }
    }
    echo "\n";
}

// Show files with missing metadata
echo "=== Data Quality Checks ===\n\n";

$qualityChecks = [
    'Missing length' => "SELECT COUNT(*) FROM files WHERE path LIKE 'https://video.richmondsunlight.com/%' AND (length IS NULL OR length = '')",
    'Missing dimensions' => "SELECT COUNT(*) FROM files WHERE path LIKE 'https://video.richmondsunlight.com/%' AND (width IS NULL OR height IS NULL)",
    'Missing title' => "SELECT COUNT(*) FROM files WHERE title IS NULL OR title = ''",
    'Missing chamber' => "SELECT COUNT(*) FROM files WHERE chamber IS NULL OR chamber = ''",
    'Missing date' => "SELECT COUNT(*) FROM files WHERE date IS NULL OR date = ''",
];

foreach ($qualityChecks as $label => $query) {
    $count = (int) $pdo->query($query)->fetchColumn();
    echo sprintf("%-25s %d\n", $label . ':', $count);
}

echo "\n";

// Detect duplicates
echo "=== Duplicate Detection ===\n\n";

// Find duplicates by chamber + date + committee_id
$duplicateSql = "
    SELECT chamber, date, committee_id, COUNT(*) as count
    FROM files
    WHERE chamber IS NOT NULL AND date IS NOT NULL
    GROUP BY chamber, date, committee_id
    HAVING COUNT(*) > 1
    ORDER BY count DESC, date DESC
";

$duplicateGroups = $pdo->query($duplicateSql)->fetchAll(PDO::FETCH_ASSOC);
$totalDuplicates = 0;
$duplicateRecords = 0;

foreach ($duplicateGroups as $group) {
    $totalDuplicates += (int) $group['count'];
    $duplicateRecords += ((int) $group['count'] - 1); // Subtract 1 because one should be kept
}

echo sprintf("Duplicate groups found: %d\n", count($duplicateGroups));
echo sprintf("Total records in duplicates: %d\n", $totalDuplicates);
echo sprintf("Excess duplicate records: %d\n", $duplicateRecords);

if ($verbose && !empty($duplicateGroups)) {
    echo "\nTop duplicate groups:\n";
    $sample = array_slice($duplicateGroups, 0, 10);
    foreach ($sample as $group) {
        $committeeLabel = $group['committee_id'] ? 'committee ' . $group['committee_id'] : 'floor';
        echo sprintf(
            "  %s %s (%s): %d copies\n",
            $group['chamber'],
            $group['date'],
            $committeeLabel,
            $group['count']
        );
    }
    if (count($duplicateGroups) > 10) {
        echo sprintf("  ... and %d more groups\n", count($duplicateGroups) - 10);
    }
}

echo "\n";
