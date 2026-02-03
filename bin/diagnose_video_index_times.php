#!/usr/bin/env php
<?php

/**
 * Diagnostic script to find video_index entries with incorrect times.
 *
 * The bug: Times were being calculated from midnight instead of video start,
 * resulting in times like 08:57:57 when they should be 00:08:58.
 *
 * This script:
 * 1. Identifies files with suspiciously high time values in video_index
 * 2. Shows examples of the incorrect data
 * 3. Offers to delete and re-index the affected records
 */

declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';

$pdo = $app->pdo ?? null;

if (!$pdo) {
    echo "ERROR: Unable to connect to database\n";
    echo "Make sure the database configuration is correct in includes/settings.inc.php\n";
    exit(1);
}

echo "=== Video Index Time Diagnostic ===\n\n";

// Find video_index entries with times that suggest they're from-midnight instead of from-video-start
// Most legislative videos are < 3 hours, so anything with time >= 05:00:00 is suspicious
$sql = "SELECT vi.id, vi.file_id, vi.time, vi.screenshot, vi.raw_text, vi.type, f.date, f.chamber
        FROM video_index vi
        JOIN files f ON f.id = vi.file_id
        WHERE vi.time >= '05:00:00'
        ORDER BY f.date DESC, vi.file_id, vi.time";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No suspicious time values found. ✓\n";
    exit(0);
}

$totalEntries = count($rows);
echo "Found " . number_format($totalEntries) . " video_index entries with suspicious times (>= 05:00:00)\n\n";

// Group by file_id to show examples
$byFile = [];
foreach ($rows as $row) {
    $byFile[$row['file_id']][] = $row;
}

echo "Affected files: " . number_format(count($byFile)) . "\n";
echo "Total affected entries: " . number_format($totalEntries) . "\n\n";

echo "Sample entries (showing first 20):\n";
echo str_repeat('-', 120) . "\n";
printf(
    "%-8s %-10s %-12s %-10s %-20s %-10s %-12s\n",
    'Index ID',
    'File ID',
    'Time',
    'Screenshot',
    'Name',
    'Type',
    'Date'
);
echo str_repeat('-', 120) . "\n";

$sampleCount = 0;
foreach ($byFile as $fileId => $entries) {
    foreach ($entries as $entry) {
        printf(
            "%-8s %-10s %-12s %-10s %-20s %-10s %-12s\n",
            $entry['id'],
            $entry['file_id'],
            $entry['time'],
            $entry['screenshot'],
            substr($entry['raw_text'], 0, 20),
            $entry['type'],
            $entry['date']
        );
        $sampleCount++;
        if ($sampleCount >= 20) {
            break 2;
        }
    }
}
echo str_repeat('-', 120) . "\n\n";

// Check which files have metadata cache available for re-indexing
$fileIds = array_keys($byFile);
$placeholders = implode(',', array_fill(0, count($fileIds), '?'));
$cacheCheckSql = "SELECT id, video_index_cache IS NOT NULL AND video_index_cache != '' as has_cache
                  FROM files
                  WHERE id IN ($placeholders)";
$stmt = $pdo->prepare($cacheCheckSql);
$stmt->execute($fileIds);
$cacheStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$filesWithCache = array_filter($cacheStatus);
$filesWithoutCache = array_diff_key($byFile, $filesWithCache);

echo "\nRe-indexing options:\n";
echo "  Files with metadata cache (can be re-indexed): " . count($filesWithCache) . "\n";
echo "  Files without metadata cache (cannot be re-indexed): " . count($filesWithoutCache) . "\n\n";

if (empty($filesWithCache)) {
    echo "No files have metadata cache available. Manual cleanup required.\n";
    exit(0);
}

// Offer to delete and re-index
echo "To fix these entries:\n";
echo "  1. Delete the incorrect video_index entries for files with metadata cache\n";
echo "  2. Re-run bin/index_metadata.php to regenerate them with correct times\n\n";

echo "Delete incorrect entries and re-index? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'y' && $line !== 'Y') {
    echo "Aborted.\n";
    exit(0);
}

// Delete entries for files with cache
$deleteIds = array_keys($filesWithCache);
echo "\nDeleting " . number_format(count($deleteIds)) . " file(s) worth of incorrect entries...\n";

$deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
$deleteSql = "DELETE FROM video_index WHERE file_id IN ($deletePlaceholders) AND type = 'legislator'";
$deleteStmt = $pdo->prepare($deleteSql);
$deleteStmt->execute($deleteIds);
$deletedCount = $deleteStmt->rowCount();

echo "Deleted " . number_format($deletedCount) . " video_index entries. ✓\n";

// Re-index using MetadataIndexer
echo "\nRe-indexing metadata for " . number_format(count($deleteIds)) . " files...\n";

use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;

$indexer = new MetadataIndexer($pdo);
$reindexed = 0;
$totalFiles = count($deleteIds);

foreach ($deleteIds as $i => $fileId) {
    $stmt = $pdo->prepare("SELECT video_index_cache FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $cache = $stmt->fetchColumn();

    if (!$cache) {
        continue;
    }

    $metadata = json_decode($cache, true);
    if (!is_array($metadata)) {
        continue;
    }

    $indexer->index($fileId, $metadata);
    $reindexed++;

    // Show progress every 10 files or on last file
    if ($reindexed % 10 === 0 || $reindexed === $totalFiles) {
        echo "  Progress: $reindexed / $totalFiles files\r";
    }
}

echo "\nRe-indexed " . number_format($reindexed) . " files. ✓\n";
echo "\nDone!\n";
