#!/usr/bin/env php
<?php

/**
 * Repair committee classification for existing videos.
 *
 * Re-applies the committee detection heuristics to videos in the database
 * that were incorrectly classified as floor sessions.
 *
 * Usage:
 *   php bin/repair_committee_classification.php [--limit=N] [--dry-run]
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

if (!$pdo) {
    echo "ERROR: Unable to connect to database\n";
    exit(1);
}

$options = getopt('', ['limit::', 'dry-run', 'help']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$dryRun = isset($options['dry-run']);

if (isset($options['help'])) {
    echo <<<HELP
Repair committee classification for existing videos.

Usage:
  php bin/repair_committee_classification.php [options]

Options:
  --limit=N   Process at most N files (default: all)
  --dry-run   Show what would be changed without updating database
  --help      Show this help

HELP;
    exit(0);
}

// Find files with video_index_cache that might need reclassification
$sql = "SELECT id, chamber, committee_id, title, video_index_cache
        FROM files
        WHERE video_index_cache IS NOT NULL
          AND video_index_cache != ''
        ORDER BY id DESC";

if ($limit > 0) {
    $sql .= " LIMIT " . (int) $limit;
}

$stmt = $pdo->query($sql);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($files)) {
    echo "No files with cached metadata found\n";
    exit(0);
}

echo sprintf("Checking %d file(s) for misclassification...\n\n", count($files));

$committees = new CommitteeDirectory($pdo);
$updateStmt = $pdo->prepare('UPDATE files SET committee_id = :committee_id, title = :title WHERE id = :id');

$checked = 0;
$reclassified = 0;
$alreadyCorrect = 0;
$errors = 0;

foreach ($files as $file) {
    $fileId = (int) $file['id'];
    $chamber = $file['chamber'];
    $currentCommitteeId = $file['committee_id'];
    $currentTitle = $file['title'];

    // Parse cached metadata
    $cache = json_decode($file['video_index_cache'], true);
    if (!is_array($cache)) {
        echo sprintf("File #%d: ERROR - Invalid or missing video_index_cache\n", $fileId);
        $log?->put(sprintf('File #%d: Invalid or missing video_index_cache', $fileId), 5);
        $errors++;
        continue;
    }

    $checked++;

    // Apply committee detection heuristics
    $title = isset($cache['title']) ? trim($cache['title']) : '';
    $description = $cache['description'] ?? '';

    // Floor sessions are specifically labeled as such or have "Regular Session" in description
    $hasRegularSessionInDesc = stripos($description, 'Regular Session') !== false;
    $isFloorSession = stripos($title, 'House Session') !== false ||
                      stripos($title, 'Senate Session') !== false ||
                      stripos($title, 'Floor Session') !== false ||
                      $hasRegularSessionInDesc;

    // Committee meetings have "Committee" or "Subcommittee" in title or "Committee Room" in description
    $hasCommitteeInTitle = stripos($title, 'Committee') !== false ||
                           stripos($title, 'Subcommittee') !== false;
    $hasCommitteeInDesc = stripos($description, 'Committee Room') !== false;

    $isCommittee = !$isFloorSession && ($hasCommitteeInTitle || $hasCommitteeInDesc);

    if ($isCommittee) {
        // Extract committee name from title
        $committeeName = $title;
        $isSubcommittee = stripos($committeeName, 'subcommittee') !== false;
        $eventType = $isSubcommittee ? 'subcommittee' : 'committee';

        // Look up committee ID
        $committeeEntry = $committees->matchEntry(
            $committeeName,
            $chamber,
            $eventType
        );
        $newCommitteeId = $committeeEntry['id'] ?? null;

        // Build new title
        $chamberName = ucfirst($chamber);
        $newTitle = sprintf('%s %s', $chamberName, $committeeName);
        $videoKey = preg_replace('/\.mp4$/', '', $videoKey); // Strip .mp4 extension
    } else {
        // Floor session
        $newCommitteeId = null;
        $chamberName = ucfirst($chamber);
        $newTitle = sprintf('%s Session', $chamberName);
        $videoKey = preg_replace('/\.mp4$/', '', $videoKey); // Strip .mp4 extension
    }

    // Check if anything changed
    $committeeIdChanged = $currentCommitteeId != $newCommitteeId; // Intentional != for NULL comparison
    $titleChanged = $currentTitle !== $newTitle;

    if (!$committeeIdChanged && !$titleChanged) {
        $alreadyCorrect++;
        continue;
    }

    // Show what's changing
    echo sprintf("File #%d:\n", $fileId);
    echo sprintf("  Title (cache): %s\n", $title);
    echo sprintf("  Description: %s\n", substr($description, 0, 80));
    echo sprintf("  Classification: %s\n", $isCommittee ? 'committee' : 'floor');

    if ($committeeIdChanged) {
        echo sprintf(
            "  Committee ID: %s → %s\n",
            $currentCommitteeId ?? 'NULL',
            $newCommitteeId ?? 'NULL'
        );
    }

    if ($titleChanged) {
        echo sprintf("  Title: %s → %s\n", $currentTitle, $newTitle);
    }

    if ($dryRun) {
        echo "  [DRY-RUN] Would update\n";
    } else {
        $updateStmt->execute([
            ':committee_id' => $newCommitteeId,
            ':title' => $newTitle,
            ':id' => $fileId,
        ]);
        $reclassified++;
    }

    echo "\n";
}

$summary = sprintf(
    'Committee classification repair complete. Checked: %d, Already correct: %d, Reclassified: %d, Errors: %d',
    $checked,
    $alreadyCorrect,
    $reclassified,
    $errors
);

$log?->put($summary, 3);
echo "\n$summary\n";

if ($dryRun && $reclassified > 0) {
    echo "\nRun without --dry-run to apply the changes.\n";
}
