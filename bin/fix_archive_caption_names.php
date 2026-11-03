#!/usr/bin/env php
<?php

/**
 * Rename the captions in our Internet Archive items so the archive's player
 * shows them. The player pairs captions with a video by name ("myvid.mp4"
 * with "myvid.en.vtt"); uploads before November 2026 gave the captions a
 * random temporary name (ia_vtt_….vtt), so none of them showed.
 *
 * Usage:
 *   php bin/fix_archive_caption_names.php                 # list what would be renamed
 *   php bin/fix_archive_caption_names.php --apply         # rename it
 *   php bin/fix_archive_caption_names.php --limit=200     # look at 200 videos (default 50)
 *   php bin/fix_archive_caption_names.php --all           # look at every archived video
 *   php bin/fix_archive_caption_names.php --id=18362      # look at one video
 *
 * Newest videos come first. Each rename is an `ia move` within the item,
 * which copies the file to its new name and deletes the old one; nothing is
 * uploaded again, and nothing in the database changes. An item can hold
 * several videos (identifiers once ended in the title, and uploads were
 * retried); each video's captions are the ones stored just after it, since
 * both went up in one ia upload. When that does not settle it, the video is
 * listed and left alone. Running it again skips items already fixed. If the archive turns
 * requests away for the rate, it stops; run it again later.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Archive\CaptionNameFixer;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$apply = in_array('--apply', $argv, true);
$limit = in_array('--all', $argv, true) ? null : 50;
$onlyId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $onlyId = (int) $m[1];
    }
}

$iaConfig = getenv('HOME') . '/.config/internetarchive/ia.ini';
if ($apply && !file_exists($iaConfig)) {
    fwrite(STDERR, 'Internet Archive configuration missing at ' . $iaConfig . ". Run `ia configure`.\n");
    exit(1);
}

$sql = "SELECT id, date, path FROM files WHERE type = 'video' AND path LIKE 'https://archive.org/%'"
    . ($onlyId !== null ? ' AND id = ' . $onlyId : '')
    . ' ORDER BY date DESC, id DESC'
    . ($limit !== null ? ' LIMIT ' . $limit : '');
$rows = $app->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$http = new Client(['timeout' => 30, 'http_errors' => false]);
$counts = ['rename' => 0, 'renamed' => 0, 'ok' => 0, 'none' => 0, 'skip' => 0, 'failed' => 0];
$seen = [];

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $identifier = CaptionNameFixer::identifierFromPath((string) $row['path']);
    if ($identifier === null) {
        printf("#%d  skipped: no item identifier in %s\n", $id, $row['path']);
        $counts['skip']++;
        continue;
    }
    $seen[$identifier] = true;

    $response = $http->get('https://archive.org/metadata/' . rawurlencode($identifier));
    $item = json_decode((string) $response->getBody(), true);
    if ($response->getStatusCode() >= 400 || !is_array($item) || empty($item['files'])) {
        printf(
            "#%d  %s  skipped: %s\n",
            $id,
            $identifier,
            $response->getStatusCode() >= 400 ? 'the archive answered HTTP ' . $response->getStatusCode() : 'the archive has no item by that name'
        );
        $counts['skip']++;
        continue;
    }

    $plan = CaptionNameFixer::plan((string) $row['path'], $item);
    if ($plan['action'] !== 'rename') {
        $counts[$plan['action']]++;
        if ($plan['action'] === 'skip') {
            printf("#%d  %s  skipped: %s\n", $id, $identifier, $plan['reason']);
        }
        continue;
    }

    printf("#%d  %s  %s %s -> %s\n", $id, $identifier, $apply ? 'renaming' : 'would rename', $plan['from'], $plan['to']);
    $counts['rename']++;
    if (!$apply) {
        continue;
    }

    $command = sprintf(
        'TQDM_DISABLE=1 ia move %s %s 2>&1',
        escapeshellarg($identifier . '/' . $plan['from']),
        escapeshellarg($identifier . '/' . $plan['to'])
    );
    $output = [];
    exec($command, $output, $status);
    $message = trim(implode('; ', $output));
    if ($status === 0) {
        $counts['renamed']++;
        $log?->put(sprintf('Renamed the captions of file #%d on the Internet Archive to %s/%s.', $id, $identifier, $plan['to']), 3);
        sleep(1);
        continue;
    }

    $counts['failed']++;
    printf("    failed: %s\n", $message !== '' ? $message : 'no output from ia');
    // The archive is overloaded: every request would get the same answer.
    if (preg_match('/reduce your request rate|slow ?down|exceeds global_limit/i', $message)) {
        echo "The Internet Archive is turning requests away for now. Stopping; run this again later.\n";
        break;
    }
}

printf(
    "\nLooked at %d video(s) in %d item(s): %d %s, %d already matched, %d without captions, %d skipped%s.\n",
    count($rows),
    count($seen),
    $apply ? $counts['renamed'] : $counts['rename'],
    $apply ? 'renamed' : 'to rename',
    $counts['ok'],
    $counts['none'],
    $counts['skip'],
    $apply && $counts['failed'] > 0 ? ', ' . $counts['failed'] . ' failed' : ''
);
if (!$apply && $counts['rename'] > 0) {
    echo "Nothing was changed. Add --apply to rename them.\n";
}
if ($limit !== null && count($rows) === $limit) {
    echo "Only the newest {$limit} archived video(s) were looked at; use --limit=N or --all for more.\n";
}
