#!/usr/bin/env php
<?php

/**
 * Processes videos uploaded to s3://video.richmondsunlight.com/uploads/.
 *
 * For each .mp4 file found in uploads/:
 *   1. Matches it to a files record via the YouTube ID in the filename.
 *   2. Downloads it temporarily for ffprobe metadata extraction.
 *   3. Moves it to its final S3 path (e.g. senate/floor/20260223.mp4).
 *   4. Picks up a matching .en.vtt captions file if present.
 *   5. Updates the files table (path, length, width, height, fps, webvtt).
 *   6. Deletes the originals from uploads/.
 */

declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$bucket = 'video.richmondsunlight.com';

$downloadDir = __DIR__ . '/../storage/downloads';
if (!is_dir($downloadDir)) {
    mkdir($downloadDir, 0775, true);
}

$s3Client = new S3Client([
    'key'     => AWS_ACCESS_KEY,
    'secret'  => AWS_SECRET_KEY,
    'region'  => AWS_REGION,
    'version' => '2006-03-01',
]);

$storage          = new S3Storage($s3Client, $bucket);
$directory        = new CommitteeDirectory($pdo);
$keyBuilder       = new S3KeyBuilder();
$metadataExtractor = new VideoMetadataExtractor();

// List .mp4 files in uploads/
$result  = $s3Client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => 'uploads/']);
$objects = $result['Contents'] ?? [];
$mp4s    = array_values(array_filter($objects, fn($o) => str_ends_with($o['Key'], '.mp4')));

if (empty($mp4s)) {
    $log->put('No uploaded videos found in uploads/.', 3);
    exit(0);
}

$log->put(sprintf('Found %d uploaded video(s) to process.', count($mp4s)), 3);

foreach ($mp4s as $object) {
    $uploadKey = $object['Key'];                    // uploads/dQw4w9WgXcQ.mp4
    $youtubeId = basename($uploadKey, '.mp4');
    $captionKey = 'uploads/' . $youtubeId . '.en.vtt';

    $localVideo    = $downloadDir . '/' . $youtubeId . '.mp4';
    $localCaptions = $downloadDir . '/' . $youtubeId . '.en.vtt';

    // Find matching file record by YouTube ID in video_index_cache
    $stmt = $pdo->prepare("
        SELECT id, chamber, committee_id, date, video_index_cache
        FROM files
        WHERE video_index_cache LIKE :pattern
          AND (path IS NULL OR path = '' OR path NOT LIKE 'https://video.richmondsunlight.com/%')
        LIMIT 1
    ");
    $stmt->execute([':pattern' => '%"youtube_id": "' . $youtubeId . '"%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $log->put("No pending file record found for YouTube ID: {$youtubeId} — skipping.", 4);
        continue;
    }

    $fileId  = (int) $row['id'];
    $chamber = $row['chamber'];
    $date    = $row['date'];

    // Re-derive committee from video_index_cache so misclassified videos
    // (committee_id = NULL) get the correct S3 path instead of the floor path.
    $cache         = json_decode($row['video_index_cache'], true) ?? [];
    $committeeName = $cache['committee_name'] ?? $cache['committee'] ?? null;
    $eventType     = $cache['event_type'] ?? 'committee';
    $committeeEntry = $committeeName
        ? $directory->matchEntry($committeeName, $chamber, $eventType === 'subcommittee' ? 'subcommittee' : 'committee')
        : null;
    $committeeId = $committeeEntry['id'] ?? ($row['committee_id'] !== null ? (int) $row['committee_id'] : null);

    $log->put("Processing upload for file #{$fileId} ({$youtubeId})", 3);

    try {
        // Download video from uploads/ to local temp
        $s3Client->getObject(['Bucket' => $bucket, 'Key' => $uploadKey, 'SaveAs' => $localVideo]);

        // Extract ffprobe metadata
        $meta = $metadataExtractor->extract($localVideo);

        // Build final S3 key and upload
        $shortname = $committeeId ? $directory->getShortnameById($committeeId) : null;
        $finalKey  = $keyBuilder->build($chamber, $date, $shortname);
        $s3Url     = $storage->upload($localVideo, $finalKey);

        // Fetch captions if present in uploads/
        $captionContents = null;
        try {
            $s3Client->getObject(['Bucket' => $bucket, 'Key' => $captionKey, 'SaveAs' => $localCaptions]);
            $captionContents = file_get_contents($localCaptions);
        } catch (S3Exception) {
            // No captions uploaded — that's fine
        }

        if ($captionContents !== null) {
            // Discard if the file doesn't look like a VTT (e.g. binary/corrupted download)
            if (!str_starts_with(ltrim($captionContents), 'WEBVTT')) {
                $log->put("Discarding caption for file #{$fileId}: not a valid VTT file.", 4);
                $captionContents = null;
            } else {
                // Strip 4-byte Unicode sequences (emoji etc.) that MySQL utf8mb3 columns reject
                $captionContents = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $captionContents);
            }
        }

        // Update files record, including committee_id in case it was previously NULL
        $update = $pdo->prepare('
            UPDATE files
            SET path = :path, committee_id = :committee_id, length = :length, width = :width,
                height = :height, fps = :fps, webvtt = :webvtt,
                date_modified = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $update->execute([
            ':path'         => $s3Url,
            ':committee_id' => $committeeId,
            ':length'       => $meta['length'] ?? null,
            ':width'        => $meta['width'] ?? null,
            ':height'       => $meta['height'] ?? null,
            ':fps'          => $meta['fps'] ?? null,
            ':webvtt'       => $captionContents,
            ':id'           => $fileId,
        ]);

        $log->put("File #{$fileId} → {$finalKey}", 3);

        // Remove from uploads/
        $s3Client->deleteObject(['Bucket' => $bucket, 'Key' => $uploadKey]);
        if ($captionContents !== null) {
            $s3Client->deleteObject(['Bucket' => $bucket, 'Key' => $captionKey]);
        }
    } catch (\Throwable $e) {
        $log->put("Failed to process upload for file #{$fileId}: " . $e->getMessage(), 5);
    } finally {
        @unlink($localVideo);
        @unlink($localCaptions);
    }
}
