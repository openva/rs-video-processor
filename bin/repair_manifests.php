#!/usr/bin/env php
<?php

/**
 * Repair missing manifest.json files for screenshot directories.
 *
 * Scans S3 for screenshot directories that are missing manifest.json,
 * generates the manifest from existing JPG files, and uploads it.
 *
 * Usage:
 *   php bin/repair_manifests.php [--limit=N] [--dry-run]
 */

declare(strict_types=1);

use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;

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
Repair missing manifest.json files for screenshot directories.

Usage:
  php bin/repair_manifests.php [options]

Options:
  --limit=N   Process at most N files (default: all)
  --dry-run   Show what would be done without making changes
  --help      Show this help

HELP;
    exit(0);
}

// Find files with capture_directory but potentially missing manifest
$sql = "SELECT id, chamber, date, capture_directory
        FROM files
        WHERE capture_directory IS NOT NULL
          AND capture_directory != ''
        ORDER BY id DESC";

if ($limit > 0) {
    $sql .= " LIMIT " . (int) $limit;
}

$stmt = $pdo->query($sql);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($files)) {
    $log?->put('No files with capture directories found', 3);
    exit(0);
}

$log?->put(sprintf('Checking %d file(s) for missing manifests', count($files)), 3);

// Initialize S3 client
$s3Client = new S3Client([
    'version' => 'latest',
    'region' => AWS_REGION ?? 'us-east-1',
    'credentials' => [
        'key' => AWS_ACCESS_KEY,
        'secret' => AWS_SECRET_KEY,
    ],
]);

$storage = new S3Storage($s3Client, 'video.richmondsunlight.com');

$checked = 0;
$missing = 0;
$repaired = 0;
$failed = 0;

foreach ($files as $file) {
    $fileId = (int) $file['id'];

    // Strip legacy /video/ prefix if present (matches BillDetectionJobQueue logic)
    $captureDir = $file['capture_directory'];
    $captureDir = preg_replace('#^/video/#', '/', $captureDir);
    $captureDir = trim($captureDir, '/');

    // Build manifest URL
    $manifestKey = $captureDir . '/manifest.json';
    $manifestUrl = buildManifestUrl($file['capture_directory']);

    $checked++;

    // Check if manifest exists in S3
    if (manifestExists($s3Client, $manifestKey)) {
        continue;
    }

    $missing++;
    $log?->put(sprintf('File #%d: Missing manifest at %s', $fileId, $manifestUrl), 3);

    if ($dryRun) {
        echo "DRY-RUN: Would rebuild manifest for file #$fileId at $manifestKey\n";
        continue;
    }

    // List all screenshots in the directory
    try {
        $screenshots = listScreenshots($s3Client, $captureDir);

        if (empty($screenshots)) {
            $log?->put(sprintf('File #%d: No screenshots found in %s', $fileId, $captureDir), 5);
            $failed++;
            continue;
        }

        // Build manifest
        $manifest = buildManifest($screenshots, $captureDir);

        // Upload manifest to S3
        $s3Client->putObject([
            'Bucket' => 'video.richmondsunlight.com',
            'Key' => $manifestKey,
            'Body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ContentType' => 'application/json',
        ]);

        $repaired++;
        $log?->put(sprintf('File #%d: Created manifest with %d screenshots', $fileId, count($manifest)), 3);
    } catch (Exception $e) {
        $log?->put(sprintf('File #%d: Failed to repair manifest: %s', $fileId, $e->getMessage()), 6);
        $failed++;
    }
}

$summary = sprintf(
    'Manifest repair complete. Checked: %d, Missing: %d, Repaired: %d, Failed: %d',
    $checked,
    $missing,
    $repaired,
    $failed
);

$log?->put($summary, 3);
echo "\n$summary\n";

if ($dryRun && $missing > 0) {
    echo "\nRun without --dry-run to repair the missing manifests.\n";
}

// Helper functions

function manifestExists(S3Client $s3Client, string $key): bool
{
    try {
        $s3Client->headObject([
            'Bucket' => 'video.richmondsunlight.com',
            'Key' => $key,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function listScreenshots(S3Client $s3Client, string $prefix): array
{
    $screenshots = [];
    $prefix = trim($prefix, '/') . '/';

    $paginator = $s3Client->getPaginator('ListObjectsV2', [
        'Bucket' => 'video.richmondsunlight.com',
        'Prefix' => $prefix,
    ]);

    foreach ($paginator as $result) {
        foreach ($result['Contents'] ?? [] as $object) {
            $key = $object['Key'];

            // Only include full-size JPGs (not thumbnails, not manifest)
            if (preg_match('#/(\d{8})\.jpg$#', $key, $matches)) {
                $screenshots[] = [
                    'key' => $key,
                    'number' => $matches[1],
                ];
            }
        }
    }

    // Sort by screenshot number
    usort($screenshots, fn($a, $b) => strcmp($a['number'], $b['number']));

    return $screenshots;
}

function buildManifest(array $screenshots, string $captureDir): array
{
    $manifest = [];
    $baseUrl = 'https://video.richmondsunlight.com/' . trim($captureDir, '/');

    foreach ($screenshots as $index => $screenshot) {
        $number = $screenshot['number'];
        $basename = $number . '.jpg';
        $thumbBasename = $number . '-thumbnail.jpg';

        $manifest[] = [
            'timestamp' => $index,
            'full' => $baseUrl . '/' . $basename,
            'thumb' => $baseUrl . '/' . $thumbBasename,
        ];
    }

    return $manifest;
}

function buildManifestUrl(string $captureDirectory): string
{
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
    return sprintf('https://video.richmondsunlight.com/%s/manifest.json', $path);
}
