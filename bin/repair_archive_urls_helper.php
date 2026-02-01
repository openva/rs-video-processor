<?php
/**
 * Shared helper functions for Archive.org URL repair.
 * Used by upload_archive.php, pipeline.php, and repair scripts.
 */

/**
 * Repair Archive.org details URLs by resolving them to direct MP4 download URLs.
 *
 * @param PDO $pdo Database connection
 * @param Log|null $log Logger instance
 * @param int &$pendingCount Output parameter: number of URLs still pending (not yet available)
 * @return int Number of URLs successfully repaired
 */
function repairArchiveUrls(\PDO $pdo, ?\Log $log, int &$pendingCount = 0): int
{
    $http = new \GuzzleHttp\Client(['timeout' => 20]);

    $sql = "SELECT id, path FROM files
        WHERE path LIKE 'https://archive.org/details/%'
        ORDER BY id DESC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $pendingCount = 0;
        return 0;
    }

    $update = $pdo->prepare('UPDATE files SET path = :path WHERE id = :id');
    $updated = 0;
    $pending = 0;

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
            $pending++;
            continue;
        }

        $mp4Url = buildDownloadUrl($identifier, $file['name']);
        $update->execute([':path' => $mp4Url, ':id' => $id]);
        $updated++;
        $log?->put("File #$id: Resolved to $mp4Url", 3);
    }

    $pendingCount = $pending;
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
