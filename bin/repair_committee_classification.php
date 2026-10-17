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

use RichmondSunlight\VideoProcessor\Archive\ArchiveMetadataUpdater;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Sync\MeetingClassifier;
use RichmondSunlight\VideoProcessor\Sync\TitleCleaner;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

if (!$pdo) {
    echo "ERROR: Unable to connect to database\n";
    exit(1);
}

$options = getopt('', ['limit::', 'dry-run', 'help', 'sync-archive', 'list-titles']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$dryRun = isset($options['dry-run']);
// Also rewrite the Internet Archive metadata of archived videos whose rows are
// already correct. Archived committee videos with no matched committee were
// titled as floor sessions there before this option existed.
$syncArchive = isset($options['sync-archive']);
// Only list the title changes this would make, grouped, and change nothing.
$listTitles = isset($options['list-titles']);
$titleChanges = [];

if (isset($options['help'])) {
    echo <<<HELP
Repair committee classification for existing videos.

Usage:
  php bin/repair_committee_classification.php [options]

Options:
  --limit=N   Process at most N files (default: all)
  --dry-run   Show what would be changed without updating database
  --list-titles   Only list the title changes this would make, and change nothing
  --sync-archive  Also rewrite Internet Archive metadata for archived videos
                  whose rows need no other change
  --help      Show this help

HELP;
    exit(0);
}

// Find files with video_index_cache that might need reclassification. Only
// JSON caches: the scrapers write JSON, and older rows hold caches in some
// other form that has nothing to classify (VideoDownloadQueue skips them the
// same way). Reporting each of those as an error flooded the log.
$sql = "SELECT id, chamber, committee_id, title, path, video_index_cache, capture_directory, capture_rate, date
        FROM files
        WHERE video_index_cache LIKE '{%'
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
$updateCacheStmt = $pdo->prepare('UPDATE files SET video_index_cache = :cache WHERE id = :id');
// Bill and speaker "nothing found" results were read from the wrong part of the
// frame when the event type was wrong. Removing them sends the video back to
// both stages. Real results are kept.
// A video already on the Internet Archive carries the old title there too.
$archiveUpdater = new ArchiveMetadataUpdater($pdo, new MetadataBuilder());
$archiveUpdated = 0;
$archiveFailed = 0;
$clearNoneStmt = $pdo->prepare("DELETE FROM video_index WHERE file_id = :id AND raw_text = '/none' AND ignored = 'y'");


$checked = 0;
$reclassified = 0;
$alreadyCorrect = 0;
$errors = 0;

foreach ($files as $file) {
    $fileId = (int) $file['id'];
    $chamber = $file['chamber'];
    $currentCommitteeId = $file['committee_id'];
    $currentTitle = $file['title'];
    $date = $file['date'];

    // Parse cached metadata
    $cache = json_decode($file['video_index_cache'], true);
    if (!is_array($cache)) {
        echo sprintf("File #%d: ERROR - video_index_cache is not valid JSON\n", $fileId);
        $log?->put(sprintf('File #%d: video_index_cache is not valid JSON', $fileId), 5);
        $errors++;
        continue;
    }

    $checked++;

    // Apply committee detection heuristics
    $title = isset($cache['title']) ? trim($cache['title']) : '';
    $description = $cache['description'] ?? '';

    // Floor sessions are the exception: only explicitly labeled sessions are floor.
    // Everything else is a committee or subcommittee meeting.
    $eventType = $title === '' ? 'floor' : MeetingClassifier::classify($title, $description);
    $isCommittee = $eventType !== 'floor';

    // The event type stored with the scraped record decides where OCR looks.
    // Records scraped before 2026-02-10 say "floor" for nearly everything.
    $currentEventType = $cache['event_type'] ?? null;
    $eventTypeChanged = $title !== '' && $currentEventType !== $eventType;

    if ($isCommittee) {
        // Extract committee name from title
        $committeeName = $title;

        // Look up committee ID
        $committeeEntry = $committees->matchEntry(
            $committeeName,
            $chamber,
            $eventType
        );
        $newCommitteeId = $committeeEntry['id'] ?? null;

        // Build new title, without the dates, times and rooms listings add
        $newTitle = TitleCleaner::storedTitle($committeeName, $chamber, $eventType);
    } else {
        // Floor session
        $newCommitteeId = null;
        $chamberName = ucfirst($chamber);
        $newTitle = sprintf('%s Session', $chamberName);
    }

    if ($listTitles) {
        if ($currentTitle !== $newTitle) {
            $pair = $currentTitle . "\n      → " . $newTitle;
            $titleChanges[$pair] = ($titleChanges[$pair] ?? 0) + 1;
        }
        continue;
    }

    // Check if anything changed
    $committeeIdChanged = $currentCommitteeId != $newCommitteeId; // Intentional != for NULL comparison
    $titleChanged = $currentTitle !== $newTitle;


    $isArchived = ArchiveMetadataUpdater::identifierFromPath((string) ($file['path'] ?? '')) !== null;
    $archiveNeedsUpdate = $isArchived && ($syncArchive || $committeeIdChanged || $titleChanged);

    if (!$committeeIdChanged && !$titleChanged && !$eventTypeChanged && !$archiveNeedsUpdate) {
        $alreadyCorrect++;
        continue;
    }

    // Show what's changing
    echo sprintf("File #%d:\n", $fileId);
    echo sprintf("  Title (cache): %s\n", $title);
    echo sprintf("  Description: %s\n", substr($description, 0, 80));
    echo sprintf("  Classification: %s\n", $eventType);
    if ($eventTypeChanged) {
        echo sprintf("  Event type: %s → %s (bill and speaker \"none found\" results will be cleared)\n", $currentEventType ?? 'none', $eventType);
    }

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

    if ($archiveNeedsUpdate) {
        echo "  Internet Archive: title, subject and description will be updated\n";
    }


    if ($dryRun) {
        echo "  [DRY-RUN] Would update\n";
    } else {
        $updateStmt->execute([
            ':committee_id' => $newCommitteeId,
            ':title' => $newTitle,
            ':id' => $fileId,
        ]);
        if ($eventTypeChanged) {
            $cache['event_type'] = $eventType;
            $updateCacheStmt->execute([
                ':cache' => json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ':id' => $fileId,
            ]);
            $clearNoneStmt->execute([':id' => $fileId]);
        }
        if ($archiveNeedsUpdate) {
            try {
                $archiveUpdater->update($fileId);
                echo "  ✓ Internet Archive metadata updated\n";
                $archiveUpdated++;
            } catch (Throwable $e) {
                // The database is already correct; the archive copy can be retried.
                echo '  ✗ Internet Archive update failed: ' . $e->getMessage() . "\n";
                $log?->put(sprintf('File #%d: %s', $fileId, $e->getMessage()), 5);
                $archiveFailed++;
            }
        }
        echo "  ✓ Database updated\n";
        $reclassified++;
    }

    echo "\n";
}

if ($listTitles) {
    arsort($titleChanges);
    foreach ($titleChanges as $pair => $count) {
        echo sprintf("%4d × %s\n", $count, $pair);
    }
    echo sprintf("\n%d distinct title change(s) across %d file(s). Nothing was changed.\n", count($titleChanges), array_sum($titleChanges));
    exit(0);
}

$summary = sprintf(
    'Committee classification repair complete. Checked: %d, Already correct: %d, Reclassified: %d, Errors: %d, Archive items updated: %d, Archive updates failed: %d',
    $checked,
    $alreadyCorrect,
    $reclassified,
    $errors,
    $archiveUpdated,
    $archiveFailed
);

$log?->put($summary, 3);
echo "\n$summary\n";

if ($dryRun && $reclassified > 0) {
    echo "\nRun without --dry-run to apply the changes.\n";
}
