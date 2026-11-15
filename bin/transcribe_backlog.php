#!/usr/bin/env php
<?php

/**
 * Transcribe the old videos that have no captions on Waldo's Mac, with
 * mlx-whisper, for free, instead of with OpenAI. The transcript stage leaves
 * them alone: see TranscriptJobQueue::OPENAI_WHISPER_FROM. The server and the
 * Mac trade through the S3 bucket (TranscriptBacklog), so neither needs the
 * other to be running.
 *
 * On the server, every pipeline pass (pipeline_parallel.sh):
 *   php bin/transcribe_backlog.php --sync [--dry-run]
 * stores the transcripts the Mac has sent, then publishes the list of videos
 * still waiting.
 *
 * On the Mac, whenever you like (scripts/transcribe_backlog.sh runs this):
 *   php bin/transcribe_backlog.php --local [--limit=N] [--model=<mlx model>] [--dir=~/rs-transcribe-backlog]
 * reads that list, transcribes each video, and sends each transcript as soon
 * as it is made. It can be stopped at any point and run again: a transcript
 * made but not sent is sent, and one sent is not made again. No database is
 * used. Videos are read only from archive.org, which is free; reading our S3
 * copy from outside AWS is not, so videos are listed only once archived.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\BacklogImporter;
use RichmondSunlight\VideoProcessor\Transcripts\ChunkedTranscription;
use RichmondSunlight\VideoProcessor\Transcripts\LocalWhisperTranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptBacklog;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$options = getopt('', ['sync', 'dry-run', 'local', 'limit:', 'model:', 'dir:']);

if (isset($options['sync'])) {
    $app = require __DIR__ . '/bootstrap.php';
    $apply = !isset($options['dry-run']);
    $pdoFactory = AppBootstrap::connectionFactory();
    $backlog = new TranscriptBacklog(S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION));

    $pdo = $pdoFactory();
    $tally = $backlog->importAll(
        new BacklogImporter($pdo, new TranscriptWriter($pdo), new JobLedger($pdo, $pdoFactory)),
        $apply,
        $app->log
    );
    if ($tally !== []) {
        $app->log->put('Transcripts made elsewhere: ' . http_build_query($tally, '', ', ') . ($apply ? '' : ' (dry run)'), 3);
    }

    $videos = TranscriptJobQueue::backlog($pdoFactory())->listAll();
    if ($apply) {
        $backlog->publishList($videos);
    }
    printf("%d video(s) wait to be transcribed elsewhere.%s\n", count($videos), $apply ? '' : ' (dry run: the list was not published)');
    exit(0);
}

if (isset($options['local'])) {
    // No database or settings file here: only the class autoloader.
    require_once __DIR__ . '/../includes/vendor/autoload.php';

    $home = getenv('HOME') ?: '.';
    $dir = $options['dir'] ?? $home . '/rs-transcribe-backlog';
    $dir = str_starts_with($dir, '~/') ? $home . substr($dir, 1) : $dir;
    $sentDir = $dir . '/sent';
    if (!is_dir($sentDir) && !mkdir($sentDir, 0775, true)) {
        fwrite(STDERR, "Unable to create {$sentDir}.\n");
        exit(1);
    }

    $backlog = new TranscriptBacklog(S3ClientFactory::withDefaultCredentials());
    try {
        $videos = $backlog->fetchList();
    } catch (\Aws\S3\Exception\S3Exception $e) {
        if ($e->getAwsErrorCode() === 'NoSuchKey') {
            echo "There is no list of videos yet: the server publishes it on its next pipeline pass.\n";
            exit(0);
        }
        fwrite(STDERR, 'Could not read the list of videos from S3: ' . $e->getAwsErrorMessage() . "\n");
        exit(1);
    }
    $limit = isset($options['limit']) ? (int) $options['limit'] : PHP_INT_MAX;
    $model = $options['model'] ?? LocalWhisperTranscriber::MLX_TRANSCRIPT_MODEL;
    $transcriber = LocalWhisperTranscriber::mlx($model);
    $audio = new AudioExtractor();
    printf("%d video(s) are waiting to be transcribed.\n\n", count($videos));

    // A sent transcript whose video is no longer listed has been stored.
    $listed = array_fill_keys(array_map(static fn(array $v): int => (int) $v['id'], $videos), true);
    foreach (glob($sentDir . '/*.json') ?: [] as $sent) {
        if (!isset($listed[(int) basename($sent, '.json')])) {
            unlink($sent);
        }
    }

    $send = static function (int $fileId, string $path) use ($backlog, $sentDir): void {
        $backlog->send($fileId, (string) file_get_contents($path));
        rename($path, $sentDir . '/' . basename($path));
    };

    $made = 0;
    foreach ($videos as $video) {
        $fileId = (int) $video['id'];
        $path = $dir . '/' . $fileId . '.json';
        if (is_file($sentDir . '/' . $fileId . '.json')) {
            // Sent already; the server has not stored it yet.
            continue;
        }
        if (is_file($path)) {
            // Made on an earlier run, which stopped before sending it.
            $send($fileId, $path);
            printf("#%d: sent the transcript made earlier\n", $fileId);
            continue;
        }
        if ($made >= $limit) {
            break;
        }
        if (!str_starts_with((string) $video['path'], 'https://archive.org/download/')) {
            // Read from anywhere else, it would be paid for.
            printf("#%d: not on archive.org (%s); skipped\n", $fileId, $video['path']);
            continue;
        }

        $started = microtime(true);
        try {
            $segments = ChunkedTranscription::run($audio, $transcriber, (string) $video['path']);
        } catch (Throwable $e) {
            printf("#%d %s %s: FAILED, will be tried again next time: %s\n", $fileId, $video['chamber'], $video['date'], $e->getMessage());
            continue;
        }
        if (empty($segments)) {
            printf("#%d %s %s: nothing was heard; skipped\n", $fileId, $video['chamber'], $video['date']);
            continue;
        }

        // Written whole and then renamed, so a run stopped partway leaves no half file.
        file_put_contents($path . '.tmp', json_encode([
            'file_id' => $fileId,
            'path' => $video['path'],
            'model' => $model,
            'transcribed_at' => gmdate('c'),
            'segments' => $segments,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($path . '.tmp', $path);
        $made++;
        $send($fileId, $path);

        $seconds = microtime(true) - $started;
        $heard = end($segments)['end'];
        printf(
            "#%d %s %s: %d segments, %s of video in %s (%.0fx); sent\n",
            $fileId,
            $video['chamber'],
            $video['date'],
            count($segments),
            gmdate('H:i:s', (int) $heard),
            gmdate('H:i:s', (int) $seconds),
            $heard / max(1.0, $seconds)
        );
    }
    printf("\nTranscribed and sent %d video(s). The server stores them on its next pipeline pass.\n", $made);
    exit(0);
}

fwrite(STDERR, "Give --sync (on the server) or --local (on the Mac). See the top of this file.\n");
exit(1);
