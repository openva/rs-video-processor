#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

$metadataBuilder = new MetadataBuilder();
$uploader = new InternetArchiveUploader($log);
$queue = new ArchiveJobQueue($pdo);
$processor = new ArchiveJobProcessor($queue, $metadataBuilder, $uploader, $pdo, $log);
$processor->run($limit);

// After uploads complete, automatically repair any unresolved URLs
// Archive.org may still be processing videos, so check if any need repair
$log?->put('Checking for Archive.org URLs that need repair...', 3);
$repairCount = repairArchiveUrls($pdo, $log);
if ($repairCount > 0) {
    $log?->put("Repaired $repairCount Archive.org URL(s)", 3);
}

/**
 * Repair Archive.org details URLs by resolving them to direct MP4 download URLs.
 * Returns the number of URLs successfully repaired.
 */
function repairArchiveUrls(\PDO $pdo, ?\Log $log): int
{
    $http = new \GuzzleHttp\Client(['timeout' => 20]);

    $sql = "SELECT id, path FROM files
        WHERE path LIKE 'https://archive.org/details/%'
        ORDER BY id DESC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return 0;
    }

    $update = $pdo->prepare('UPDATE files SET path = :path WHERE id = :id');
    $updated = 0;

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $detailsUrl = (string) $row['path'];
        $identifier = extractIdentifier($detailsUrl);

        if (!$identifier) {
            continue;
        }

        $metadata = fetchArchiveMetadata($http, $identifier);
        $file = selectMp4File($metadata['files'] ?? []);

        if (!$file) {
            $log?->put("File #$id: MP4 not yet available for $identifier (still processing)", 4);
            continue;
        }

        $mp4Url = buildDownloadUrl($identifier, $file['name']);
        $update->execute([':path' => $mp4Url, ':id' => $id]);
        $updated++;
        $log?->put("File #$id: Resolved to $mp4Url", 3);
    }

    return $updated;
}

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

function fetchArchiveMetadata(\GuzzleHttp\Client $http, string $identifier): array
{
    try {
        $url = sprintf('https://archive.org/metadata/%s', rawurlencode($identifier));
        $response = $http->get($url);
        if ($response->getStatusCode() >= 400) {
            return [];
        }
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : [];
    } catch (\Exception $e) {
        return [];
    }
}

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
