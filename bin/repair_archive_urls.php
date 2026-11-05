#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Archive\ArchiveMetadata;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log ?? null;

$options = getopt('', ['limit::', 'dry-run', 'help']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$dryRun = isset($options['dry-run']);
$help = isset($options['help']);

if ($help) {
    echo <<<HELP
Archive URL Repair

Usage:
  php bin/repair_archive_urls.php [options]

Options:
  --limit=N   Process at most N records (default: all)
  --dry-run   Show updates without writing to DB
  --help      Show this help

HELP;
    exit(0);
}

$sql = "SELECT id, path FROM files
    WHERE path LIKE 'https://archive.org/details/%'
    ORDER BY id ASC";
if ($limit > 0) {
    $sql .= " LIMIT " . (int) $limit;
}

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    $log?->put('No archive.org details URLs found to repair.', 3);
    exit(0);
}

$http = new Client(['timeout' => 20]);
$update = $pdo->prepare('UPDATE files SET path = :path WHERE id = :id');

$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $detailsUrl = (string) $row['path'];
    $identifier = extractIdentifier($detailsUrl);
    if (!$identifier) {
        $log?->put('Skipping file #' . $id . ' (unable to parse identifier).', 4);
        $skipped++;
        continue;
    }

    try {
        $metadata = fetchArchiveMetadata($http, $identifier);
    } catch (NotReadyYet $e) {
        // Every other item would get the same answer.
        $log?->put($e->getMessage() . ' Stopping; run it again later.', 4);
        break;
    }
    $file = selectMp4File($metadata['files'] ?? []);
    if (!$file) {
        $log?->put('Skipping file #' . $id . ' (no MP4 found for ' . $identifier . ').', 4);
        $skipped++;
        continue;
    }

    $mp4Url = buildDownloadUrl($identifier, $file['name']);
    if ($dryRun) {
        $log?->put('DRY-RUN: would update file #' . $id . ' to ' . $mp4Url, 3);
        $updated++;
        continue;
    }

    $update->execute([
        ':path' => $mp4Url,
        ':id' => $id,
    ]);
    $updated++;
    $log?->put('Updated file #' . $id . ' to ' . $mp4Url, 3);
}

$log?->put(sprintf('Archive URL repair complete. Updated: %d, Skipped: %d.', $updated, $skipped), 3);

function extractIdentifier(string $detailsUrl): ?string
{
    $parts = parse_url($detailsUrl);
    if (!isset($parts['path'])) {
        return null;
    }
    $path = trim((string) $parts['path'], '/');
    if (!str_starts_with($path, 'details/')) {
        return null;
    }
    $identifier = substr($path, strlen('details/'));
    return $identifier !== '' ? $identifier : null;
}

/**
 * @throws NotReadyYet when the archive is down
 */
function fetchArchiveMetadata(Client $http, string $identifier): array
{
    return ArchiveMetadata::fetch($http, $identifier) ?? [];
}

/**
 * @param array<int,array<string,mixed>> $files
 * @return array<string,mixed>|null
 */
function selectMp4File(array $files): ?array
{
    $best = null;
    $bestSize = 0;
    foreach ($files as $file) {
        $name = $file['name'] ?? '';
        if (!is_string($name) || $name === '' || !str_ends_with(strtolower($name), '.mp4')) {
            continue;
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size >= $bestSize) {
            $best = $file;
            $bestSize = $size;
        }
    }
    return $best;
}

function buildDownloadUrl(string $identifier, string $filename): string
{
    $encoded = str_replace('%2F', '/', rawurlencode($filename));
    return sprintf('https://archive.org/download/%s/%s', rawurlencode($identifier), $encoded);
}
