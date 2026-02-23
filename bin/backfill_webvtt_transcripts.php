#!/usr/bin/env php
<?php

/**
 * One-off script to backfill transcripts from existing WebVTT data.
 * Processes only files that have webvtt but no transcript rows.
 * Does NOT use SQS — processes directly from the database.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$app = require __DIR__ . '/bootstrap.php';

$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['limit::', 'dry-run']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 50;
$dryRun = isset($options['dry-run']);

$sql = "SELECT f.id, f.chamber, f.title, f.webvtt
    FROM files f
    WHERE f.webvtt IS NOT NULL AND f.webvtt != ''
      AND f.transcript IS NULL
    ORDER BY f.date DESC
    LIMIT :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "No files with WebVTT pending transcript generation.\n";
    exit(0);
}

echo sprintf("Found %d files with WebVTT but no transcript.\n", count($rows));

$parser = new CaptionParser();
$writer = new TranscriptWriter($pdo);
$processed = 0;
$failed = 0;
$empty = 0;

foreach ($rows as $row) {
    $fileId = (int) $row['id'];
    $segments = $parser->parseWebVtt($row['webvtt']);

    if (empty($segments)) {
        echo sprintf("  File #%d: WebVTT parsed to 0 segments, skipping.\n", $fileId);
        $empty++;
        continue;
    }

    if ($dryRun) {
        echo sprintf("  File #%d: Would write %d segments.\n", $fileId, count($segments));
        $processed++;
        continue;
    }

    try {
        $writer->write($fileId, $segments);
        $processed++;
        echo sprintf("  File #%d: Wrote %d transcript segments.\n", $fileId, count($segments));
    } catch (Throwable $e) {
        // Roll back any open transaction so subsequent files aren't poisoned
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        echo sprintf("  File #%d: FAILED — %s\n", $fileId, $e->getMessage());
        $log->put(sprintf('Backfill failed for file #%d: %s', $fileId, $e->getMessage()), 5);
    }
}

echo sprintf("\nDone. Processed: %d, Empty WebVTT: %d, Failed: %d\n", $processed, $empty, $failed);
