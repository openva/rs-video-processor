#!/usr/bin/env php
<?php

/**
 * Recompute the times of House speaker markers taken from the House site's
 * speaker list.
 *
 * Until 2026-10, those times were measured from the first speaker rather than
 * from the start of the recording, so every speaker in a video that opened with
 * a recess or "at ease" card was placed too early by the length of that card.
 * Names already matched to legislators keep their match.
 *
 * Usage:
 *   php bin/reindex_speaker_times.php                 # show how far each video's markers would move
 *   php bin/reindex_speaker_times.php --apply         # re-index them
 *   php bin/reindex_speaker_times.php --id=18897      # one video only (with or without --apply)
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\SpeakerTimeline;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$options = getopt('', ['apply', 'id:']);
$apply = isset($options['apply']);

$sql = "SELECT id, date, title, video_index_cache FROM files
        WHERE chamber = 'house' AND video_index_cache LIKE '%\"speakers\": [%'";
$params = [];
if (isset($options['id'])) {
    $sql .= ' AND id = :id';
    $params[':id'] = (int) $options['id'];
}
$stmt = $pdo->prepare($sql . ' ORDER BY date DESC');
$stmt->execute($params);

$indexer = new MetadataIndexer($pdo);
$moved = 0;
$unchanged = 0;
$unusable = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $metadata = json_decode((string) $row['video_index_cache'], true);
    if (!is_array($metadata) || empty($metadata['speakers'])) {
        continue;
    }

    $starts = array_map(static fn($s) => (string) ($s['start_time'] ?? ''), $metadata['speakers']);
    $firstSpeaker = null;
    foreach ($starts as $start) {
        $seconds = SpeakerTimeline::isTimestamp($start) ? SpeakerTimeline::toSeconds($start) : null;
        if ($seconds !== null && ($firstSpeaker === null || $seconds < $firstSpeaker)) {
            $firstSpeaker = $seconds;
        }
    }
    $recordingStart = SpeakerTimeline::recordingStart($metadata, $starts);
    if ($firstSpeaker === null || $recordingStart === null || $firstSpeaker === $recordingStart) {
        // A stored start that was not used is one that would have put speakers
        // before the start or past the end of the video. Say so, rather than
        // counting the video as right.
        if (!empty($metadata['media_start']) || !empty($metadata['actual_start'])) {
            $unusable[] = sprintf('#%-6d %s  %s', (int) $row['id'], $row['date'], $row['title']);
        } else {
            $unchanged++;
        }
        continue;
    }

    $shift = $firstSpeaker - $recordingStart;
    echo sprintf(
        "#%-6d %s  markers move %d min %02d s later  %s\n",
        (int) $row['id'],
        $row['date'],
        intdiv($shift, 60),
        $shift % 60,
        $row['title']
    );
    $moved++;

    if ($apply) {
        $indexer->index((int) $row['id'], $metadata);
    }
}

if ($unusable) {
    echo "\nStored start time not usable (it would put speakers outside the video), so left as they are:\n";
    echo '  ' . implode("\n  ", $unusable) . "\n";
}

echo sprintf(
    "\n%d video(s) %s, %d already right, %d with an unusable start time.%s\n",
    $moved,
    $apply ? 're-indexed' : 'would be re-indexed',
    $unchanged,
    count($unusable),
    $apply ? '' : ' Nothing was changed; add --apply to re-index.'
);
