#!/usr/bin/env php
<?php

/**
 * Repair script for Senate YouTube videos that were misclassified
 * because the scraper couldn't parse "Senate of Virginia: ..." titles.
 *
 * Re-parses the original YouTube title from video_index_cache,
 * re-classifies event type, looks up committee_id, and updates the row.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateYouTubeScraper;

$app = require __DIR__ . '/bootstrap.php';

$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['limit::', 'dry-run']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 100;
$dryRun = isset($options['dry-run']);

// Find Senate YouTube videos with NULL committee_id that have video_index_cache
$sql = "SELECT id, title, committee_id, video_index_cache
    FROM files
    WHERE chamber = 'senate'
      AND video_index_cache IS NOT NULL
      AND video_index_cache LIKE '%senate-youtube%'
    ORDER BY date DESC
    LIMIT :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No Senate YouTube videos found.\n";
    exit(0);
}

echo sprintf("Checking %d Senate YouTube videos...\n", count($rows));

// classifyTitle() doesn't use HTTP, but the constructor requires a client
$nullClient = new class implements \RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface {
    public function get(string $url): string
    {
        throw new \RuntimeException('Not implemented');
    }
};
$scraper = new SenateYouTubeScraper($nullClient, 'unused');
$committees = new CommitteeDirectory($pdo);

$updateStmt = $pdo->prepare(
    "UPDATE files
     SET title = :title,
         committee_id = :committee_id,
         video_index_cache = :cache,
         date_modified = CURRENT_TIMESTAMP
     WHERE id = :id"
);

$fixed = 0;
$unchanged = 0;
$failed = 0;

foreach ($rows as $row) {
    $fileId = (int) $row['id'];
    $cache = json_decode($row['video_index_cache'], true);
    if (!is_array($cache) || empty($cache['title'])) {
        echo sprintf("  File #%d: No original title in cache, skipping.\n", $fileId);
        $unchanged++;
        continue;
    }

    $originalTitle = $cache['title'];
    $classification = $scraper->classifyTitle($originalTitle);

    // Build the display title (same logic as VideoImporter)
    $committeeName = $classification['committee_name'];
    if ($committeeName) {
        $displayTitle = 'Senate ' . $committeeName;
    } else {
        $displayTitle = 'Senate Session';
    }

    // Look up committee_id
    $eventType = $classification['event_type'];
    $committeeEntry = $committeeName
        ? $committees->matchEntry($committeeName, 'senate', $eventType === 'subcommittee' ? 'subcommittee' : 'committee')
        : null;
    $committeeId = $committeeEntry['id'] ?? null;

    // Update cache with corrected classification
    $cache['event_type'] = $eventType;
    $cache['committee_name'] = $committeeName;
    $cache['committee'] = $classification['committee'];
    $cache['subcommittee'] = $classification['subcommittee'];

    // Check if anything changed
    $oldTitle = $row['title'];
    $oldCommitteeId = $row['committee_id'] !== null ? (int) $row['committee_id'] : null;
    if ($displayTitle === $oldTitle && $committeeId === $oldCommitteeId) {
        $unchanged++;
        continue;
    }

    $committeeLabel = $committeeId !== null ? "committee_id={$committeeId}" : 'committee_id=NULL';
    echo sprintf(
        "  File #%d: %s → %s [%s, %s]\n",
        $fileId,
        $oldTitle,
        $displayTitle,
        $eventType,
        $committeeLabel
    );

    if ($dryRun) {
        $fixed++;
        continue;
    }

    try {
        $updateStmt->execute([
            ':title' => $displayTitle,
            ':committee_id' => $committeeId,
            ':cache' => json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ':id' => $fileId,
        ]);
        $fixed++;
    } catch (\Throwable $e) {
        $failed++;
        echo sprintf("  File #%d: FAILED — %s\n", $fileId, $e->getMessage());
        $log->put(sprintf('Senate classification repair failed for file #%d: %s', $fileId, $e->getMessage()), 5);
    }
}

$mode = $dryRun ? ' (dry run)' : '';
echo sprintf("\nDone%s. Fixed: %d, Unchanged: %d, Failed: %d\n", $mode, $fixed, $unchanged, $failed);
