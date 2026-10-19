#!/usr/bin/env php
<?php

/**
 * Measure, and with --apply correct, how far stored transcripts are from the
 * audio. Captions from the legislature run early or late by an amount that
 * differs from file to file; see CaptionAligner. New transcripts are aligned
 * by the transcript stage; this is for the ones stored before it did.
 *
 * Each video costs five two-minute Whisper requests, about $0.06, unless
 * --local-whisper is given. Every measurement is saved to a local file
 * (--cache, by default ~/align_transcripts_cache.json) and reused by later
 * runs, so a dry run of everything followed by --apply pays only once.
 *
 * Usage:
 *   php bin/align_transcripts.php --count                # how many videos, and the cost
 *   php bin/align_transcripts.php --limit=20             # dry run: report only (the default)
 *   php bin/align_transcripts.php --limit=100000         # dry run of everything
 *   php bin/align_transcripts.php --file-id=5175         # one video, whatever is recorded for it
 *   php bin/align_transcripts.php --limit=20 --apply     # shift and record
 *   php bin/align_transcripts.php --local-whisper[=base.en]   # transcribe with openai-whisper here (CPU)
 *   php bin/align_transcripts.php --mlx-whisper[=<model>]     # transcribe with mlx-whisper here (Apple GPU)
 *   php bin/align_transcripts.php --source=webvtt        # measure files.webvtt, not the transcript rows
 *   php bin/align_transcripts.php --cache=/path/to.json  # keep measurements somewhere else
 *   php bin/align_transcripts.php --apply --cached-only  # apply saved measurements only; never transcribe
 *   php bin/align_transcripts.php --recheck-unaligned    # measure again the ones recorded as unaligned
 *
 * To measure on another machine (say, with --local-whisper) and apply here,
 * measure there against a current copy of files and video_transcript, copy
 * the cache file here, and run with --apply --cached-only --cache=<that file>.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Maintenance\AlignmentCache;
use RichmondSunlight\VideoProcessor\Maintenance\TranscriptRealigner;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\LocalWhisperTranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['limit:', 'file-id:', 'dry-run', 'apply', 'count', 'local-whisper::', 'source:', 'cache:', 'cached-only', 'mlx-whisper::', 'recheck-unaligned']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
$fileId = isset($options['file-id']) ? (int) $options['file-id'] : null;
$apply = isset($options['apply']);
$source = $options['source'] ?? 'auto';
$cachedOnly = isset($options['cached-only']);
$recheck = isset($options['recheck-unaligned']);

/** Five two-minute windows at $0.006 a minute. */
const COST_PER_VIDEO = 5 * 2 * 0.006;

if ($cachedOnly) {
    // Never called: only saved measurements are used.
    $transcriber = new LocalWhisperTranscriber();
} elseif (isset($options['mlx-whisper'])) {
    $model = is_string($options['mlx-whisper']) && $options['mlx-whisper'] !== '' ? $options['mlx-whisper'] : null;
    $transcriber = LocalWhisperTranscriber::mlx($model);
} elseif (isset($options['local-whisper'])) {
    $model = is_string($options['local-whisper']) && $options['local-whisper'] !== '' ? $options['local-whisper'] : 'base.en';
    $transcriber = new LocalWhisperTranscriber($model);
} else {
    if (!defined('OPENAI_KEY') || OPENAI_KEY === '') {
        fwrite(STDERR, "OPENAI_KEY is not defined. Use --mlx-whisper or --local-whisper to transcribe on this machine.\n");
        exit(1);
    }
    $transcriber = new OpenAITranscriber(new Client(['timeout' => 300]), OPENAI_KEY);
}

$cachePath = $options['cache'] ?? (getenv('HOME') ?: sys_get_temp_dir()) . '/align_transcripts_cache.json';
$realigner = new TranscriptRealigner(
    $pdo,
    new CaptionAligner($transcriber, new AudioExtractor()),
    null,
    new AlignmentCache($cachePath),
    AppBootstrap::connectionFactory()
);

if (isset($options['count'])) {
    $count = $realigner->countCandidates($recheck);
    printf(($recheck ? "%d video(s) are recorded as unaligned." : "%d video(s) have a transcript or captions with no alignment recorded.") . " Measuring them all with the OpenAI API would cost about $%.2f.\n", $count, $count * COST_PER_VIDEO);
    exit(0);
}

$videos = $realigner->candidates($limit, $fileId, $recheck);
if (empty($videos)) {
    echo "Nothing to align.\n";
    exit(0);
}
printf("%s %d video(s).\n\n", $apply ? 'Aligning' : 'Dry run (nothing will be changed) for', count($videos));

$tally = [];
$paidFor = 0;
$unsaved = 0;
foreach ($videos as $video) {
    $result = null;
    $captions = $realigner->captions($video['id'], $source);
    if (empty($captions['segments'])) {
        printf("#%d %s %s: no %s to measure\n", $video['id'], $video['chamber'], $video['date'], $captions['source']);
        continue;
    }
    if ($cachedOnly) {
        $result = $realigner->saved($captions['segments'], $video);
        if ($result === null) {
            printf("#%d %s %s: no saved measurement for these captions; skipped\n", $video['id'], $video['chamber'], $video['date']);
            $unsaved++;
            continue;
        }
    }
    $result ??= $realigner->measure($captions['segments'], $video);
    if (!$cachedOnly && !$realigner->lastWasCached()) {
        $realigner->reconnect();
    }
    $status = $result->status();
    $tally[$status] = ($tally[$status] ?? 0) + 1;
    $fromSaved = $cachedOnly || $realigner->lastWasCached();
    $paidFor += $fromSaved ? 0 : 1;

    printf(
        "#%d %s %s (%d %s): %s%s\n",
        $video['id'],
        $video['chamber'],
        $video['date'],
        count($captions['segments']),
        $captions['source'] === 'rows' ? 'transcript rows' : 'WebVTT cues',
        $result->summary(),
        $fromSaved ? ' [saved measurement]' : ''
    );

    if ($apply) {
        try {
            $realigner->apply($video['id'], $result);
            if ($result->shouldShift()) {
                $log->put(sprintf('Shifted the transcript of file #%d by %+.1fs.', $video['id'], -$result->offset), 3);
            }
        } catch (Throwable $e) {
            printf("  FAILED to apply: %s\n", $e->getMessage());
            $log->put(sprintf('Aligning the transcript of file #%d failed: %s', $video['id'], $e->getMessage()), 5);
        }
    }
}

echo "\n";
foreach ($tally as $status => $n) {
    printf("%s: %d\n", $status, $n);
}
if ($unsaved > 0) {
    printf("no saved measurement: %d\n", $unsaved);
}
printf("\nMeasured %d video(s) anew; reused %d saved measurement(s) from %s.\n", $paidFor, array_sum($tally) - $paidFor, $cachePath);
if (!$apply) {
    echo "Dry run: nothing was changed. 'aligned' videos would be shifted; rerun with --apply, which reuses these measurements.\n";
}
