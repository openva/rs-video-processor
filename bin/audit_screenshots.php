#!/usr/bin/env php
<?php

/**
 * Audit script that checks every file with capture_directory set
 * against S3 to verify the manifest.json actually exists.
 * Reports files where screenshots are missing and optionally resets them.
 */

declare(strict_types=1);

use Aws\S3\S3Client;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$options = getopt('', ['fix', 'limit::']);
$fix = isset($options['fix']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;

$s3 = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => 'us-east-1',
    'version' => '2006-03-01',
]);
$bucket = 'video.richmondsunlight.com';

$sql = "SELECT id, capture_directory, date
    FROM files
    WHERE capture_directory IS NOT NULL AND capture_directory != ''
    ORDER BY date DESC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("Checking %d files with capture_directory set...\n", count($rows));

$missing = [];
$ok = 0;

foreach ($rows as $row) {
    $dir = trim($row['capture_directory'], '/');
    $key = $dir . '/manifest.json';

    try {
        $s3->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $ok++;
    } catch (\Aws\S3\Exception\S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            $missing[] = $row;
            echo sprintf("  MISSING: file #%d — %s (date: %s)\n", $row['id'], $key, $row['date']);
        } else {
            echo sprintf("  ERROR: file #%d — %s: %s\n", $row['id'], $key, $e->getMessage());
        }
    }
}

echo sprintf("\nResults: %d OK, %d missing manifests.\n", $ok, count($missing));

if (empty($missing)) {
    echo "All screenshots accounted for.\n";
    exit(0);
}

if (!$fix) {
    echo "Run with --fix to reset capture_directory for missing files so they can be regenerated.\n";
    exit(0);
}

$resetStmt = $pdo->prepare("UPDATE files SET capture_directory = NULL, capture_rate = NULL WHERE id = :id");
foreach ($missing as $row) {
    $resetStmt->execute([':id' => $row['id']]);
}
echo sprintf("Reset %d files. Run generate_screenshots.php to regenerate.\n", count($missing));
