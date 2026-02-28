#!/usr/bin/env php
<?php

/**
 * Repairs Senate YouTube records misclassified by the old title parser.
 *
 * The pre-fix scraper could not parse "Senate of Virginia: {name} on {date}"
 * titles, leaving committee_id = NULL and storing the full YouTube title as
 * committee_name. This script reads the raw YouTube title from
 * video_index_cache and re-classifies each record using the fixed parser.
 *
 * Options:
 *   --dry-run   Print changes without writing to the database.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateYouTubeScraper;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    $log->put('Running in dry-run mode — no changes will be written.', 3);
}

$directory = new CommitteeDirectory($pdo);
$scraper   = new SenateYouTubeScraper(
    new class implements HttpClientInterface {
        public function get(string $url): string
        {
            return '';
        }
    },
    ''
);

$stmt = $pdo->query("
    SELECT id, video_index_cache
    FROM files
    WHERE chamber = 'senate'
      AND committee_id IS NULL
      AND video_index_cache IS NOT NULL
      AND video_index_cache LIKE '%senate-youtube%'
    ORDER BY id DESC
");

$updated = 0;
$skipped = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $fileId = (int) $row['id'];
    $cache  = json_decode($row['video_index_cache'], true);

    if (!is_array($cache)) {
        $skipped++;
        continue;
    }

    $rawTitle = $cache['title'] ?? null;
    if (!$rawTitle) {
        $skipped++;
        continue;
    }

    $classification = $scraper->classifyTitle($rawTitle);
    $committeeName  = $classification['committee_name'];
    $eventType      = $classification['event_type'];

    // If re-classification still says floor, the record is correctly typed (no committee_id needed)
    if ($eventType === 'floor') {
        $skipped++;
        continue;
    }

    // Look up the committee in the directory
    $committeeEntry = $committeeName
        ? $directory->matchEntry($committeeName, 'senate', $eventType === 'subcommittee' ? 'subcommittee' : 'committee')
        : null;
    $committeeId = $committeeEntry['id'] ?? 0;

    // Build a clean stored title
    $storedTitle = $committeeName
        ? sprintf('Senate %s', $committeeName)
        : 'Senate Session';

    // Patch video_index_cache so downstream processes (e.g. process_uploads.php) see correct data
    $cache['committee_name'] = $committeeName;
    $cache['event_type']     = $eventType;
    $cache['committee']      = $classification['committee'];
    $cache['subcommittee']   = $classification['subcommittee'];

    $log->put(sprintf(
        'File #%d: event_type=%s committee_name=%s committee_id=%s title="%s"%s',
        $fileId,
        $eventType,
        $committeeName ?? 'null',
        $committeeId !== null ? (string) $committeeId : 'null',
        $storedTitle,
        $dryRun ? ' [dry-run]' : ''
    ), 3);

    if (!$dryRun) {
        $update = $pdo->prepare('
            UPDATE files
            SET committee_id = :committee_id, title = :title,
                video_index_cache = :cache, date_modified = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $update->execute([
            ':committee_id' => $committeeId,
            ':title'        => $storedTitle,
            ':cache'        => json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ':id'           => $fileId,
        ]);
    }

    $updated++;
}

$log->put(sprintf('Repair complete: %d record(s) updated, %d skipped.', $updated, $skipped), 3);
