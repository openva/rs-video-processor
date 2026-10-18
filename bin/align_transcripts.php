#!/usr/bin/env php
<?php

/**
 * Measure, and with --apply correct, how far stored transcripts are from the
 * audio. Captions from the legislature run early or late by an amount that
 * differs from file to file; see CaptionAligner. New transcripts are aligned
 * by the transcript stage; this is for the ones stored before it did.
 *
 * Each video costs five two-minute Whisper requests, about $0.06, unless
 * --local-whisper is given.
 *
 * Usage:
 *   php bin/align_transcripts.php --count                # how many videos, and the cost
 *   php bin/align_transcripts.php --limit=20             # dry run: report only (the default)
 *   php bin/align_transcripts.php --file-id=5175         # one video, whatever is recorded for it
 *   php bin/align_transcripts.php --limit=20 --apply     # shift and record
 *   php bin/align_transcripts.php --local-whisper[=base.en]   # transcribe with the whisper CLI here
 *   php bin/align_transcripts.php --source=webvtt        # measure files.webvtt, not the transcript rows
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Maintenance\TranscriptRealigner;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\LocalWhisperTranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['limit:', 'file-id:', 'dry-run', 'apply', 'count', 'local-whisper::', 'source:']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
$fileId = isset($options['file-id']) ? (int) $options['file-id'] : null;
$apply = isset($options['apply']);
$source = $options['source'] ?? 'auto';

/** Five two-minute windows at $0.006 a minute. */
const COST_PER_VIDEO = 5 * 2 * 0.006;

if (isset($options['local-whisper'])) {
    $model = is_string($options['local-whisper']) && $options['local-whisper'] !== '' ? $options['local-whisper'] : 'base.en';
    $transcriber = new LocalWhisperTranscriber($model);
} else {
    if (!defined('OPENAI_KEY') || OPENAI_KEY === '') {
        fwrite(STDERR, "OPENAI_KEY is not defined. Use --local-whisper to transcribe on this machine.\n");
        exit(1);
    }
    $transcriber = new OpenAITranscriber(new Client(['timeout' => 300]), OPENAI_KEY);
}

$realigner = new TranscriptRealigner($pdo, new CaptionAligner($transcriber, new AudioExtractor()));

if (isset($options['count'])) {
    $count = $realigner->countCandidates();
    printf("%d video(s) have a transcript or captions with no alignment recorded. Measuring them all with the OpenAI API would cost about $%.2f.\n", $count, $count * COST_PER_VIDEO);
    exit(0);
}

$videos = $realigner->candidates($limit, $fileId);
if (empty($videos)) {
    echo "Nothing to align.\n";
    exit(0);
}
printf("%s %d video(s).\n\n", $apply ? 'Aligning' : 'Dry run (nothing will be changed) for', count($videos));

$tally = [];
foreach ($videos as $video) {
    $captions = $realigner->captions($video['id'], $source);
    if (empty($captions['segments'])) {
        printf("#%d %s %s: no %s to measure\n", $video['id'], $video['chamber'], $video['date'], $captions['source']);
        continue;
    }
    $result = $realigner->measure($captions['segments'], $video);
    $status = $result->status();
    $tally[$status] = ($tally[$status] ?? 0) + 1;

    printf(
        "#%d %s %s (%d %s): %s\n",
        $video['id'],
        $video['chamber'],
        $video['date'],
        count($captions['segments']),
        $captions['source'] === 'rows' ? 'transcript rows' : 'WebVTT cues',
        $result->summary()
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
if (!$apply) {
    echo "\nDry run: nothing was changed. 'aligned' videos would be shifted; rerun with --apply.\n";
}
