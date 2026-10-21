#!/usr/bin/env php
<?php

/**
 * Checks every file with capture_directory set against S3: that its
 * manifest.json exists, and that the first screenshot the manifest lists is
 * there. Reports the files whose screenshots are missing and, with --fix,
 * resets them so the screenshot stage makes them again.
 *
 * Some manifests made by older code list screenshots under another directory
 * than the one they are in (file #14755: its manifest is in
 * house/committee/public-safety/20260116/ but lists house/floor/20260116/...),
 * so bill and speaker detection asked for thousands of frames that are not
 * there.
 *
 * Usage:
 *   php bin/audit_screenshots.php              # report
 *   php bin/audit_screenshots.php --limit=100  # the newest 100 only
 *   php bin/audit_screenshots.php --fix        # reset the broken ones
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$options = getopt('', ['fix', 'limit::']);
$fix = isset($options['fix']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;

$s3 = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$bucket = 'video.richmondsunlight.com';
$publicPrefix = 'https://' . $bucket . '/';

$sql = "SELECT id, capture_directory, date
    FROM files
    WHERE capture_directory IS NOT NULL AND capture_directory != ''
      AND (capture_directory LIKE '/%/%' OR capture_directory LIKE 'https://%')
    ORDER BY date DESC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("Checking %d files with capture_directory set...\n", count($rows));

/** Whether a key exists; null when S3 gave some other error. */
$exists = static function (string $key) use ($s3, $bucket): ?bool {
    try {
        $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
        return true;
    } catch (\Aws\S3\Exception\S3Exception $e) {
        return $e->getStatusCode() === 404 ? false : null;
    }
};

$broken = [];
$ok = 0;

foreach ($rows as $row) {
    // Some older rows hold the directory as a full URL. '/deleted-source' (a
    // video whose source is gone) is not a directory and is not selected.
    $dir = (string) $row['capture_directory'];
    if (str_starts_with($dir, $publicPrefix)) {
        $dir = substr($dir, strlen($publicPrefix));
    }
    $dir = trim($dir, '/');
    $key = $dir . '/manifest.json';

    try {
        $manifest = json_decode((string) $s3->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'], true);
    } catch (\Aws\S3\Exception\S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            $broken[] = $row;
            echo sprintf("  MISSING MANIFEST: file #%d — %s (date: %s)\n", $row['id'], $key, $row['date']);
        } else {
            echo sprintf("  ERROR: file #%d — %s: %s\n", $row['id'], $key, $e->getMessage());
        }
        continue;
    }

    $first = is_array($manifest) ? (string) ($manifest[0]['full'] ?? '') : '';
    if ($first === '') {
        // An empty manifest is a video with no frames; nothing to ask for.
        $ok++;
        continue;
    }
    $firstKey = str_starts_with($first, $publicPrefix) ? substr($first, strlen($publicPrefix)) : ltrim($first, '/');
    $found = $exists($firstKey);
    if ($found === false) {
        $broken[] = $row;
        echo sprintf("  MISSING SCREENSHOTS: file #%d — %s lists %s, which is not there (date: %s)\n", $row['id'], $key, $first, $row['date']);
    } elseif ($found === null) {
        echo sprintf("  ERROR: file #%d — could not check %s\n", $row['id'], $first);
    } else {
        $ok++;
    }
}

echo sprintf("\nResults: %d OK, %d with missing screenshots.\n", $ok, count($broken));

if (empty($broken)) {
    echo "All screenshots accounted for.\n";
    exit(0);
}

echo 'IDs: ' . implode(', ', array_map(static fn($row) => $row['id'], $broken)) . "\n";

if (!$fix) {
    echo "Run with --fix to reset these files so their screenshots are made again.\n";
    exit(0);
}

// The screenshot stage runs for a file with no capture_directory. Its job
// records, and those of the stages that read the screenshots, are cleared so
// a given-up bill or speaker job runs again on the new screenshots.
$ledger = new JobLedger($pdo);
$resetStmt = $pdo->prepare("UPDATE files SET capture_directory = NULL, capture_rate = NULL WHERE id = :id");
foreach ($broken as $row) {
    $resetStmt->execute([':id' => $row['id']]);
    foreach ([JobLedger::STAGE_SCREENSHOTS, JobLedger::STAGE_BILLS, JobLedger::STAGE_SPEAKERS] as $stage) {
        $ledger->reset((int) $row['id'], $stage);
    }
}
echo sprintf("Reset %d files. The pipeline will make their screenshots again on its next run.\n", count($broken));
