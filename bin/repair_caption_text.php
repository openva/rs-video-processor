#!/usr/bin/env php
<?php

/**
 * Repair transcripts stored before November 2026 from YouTube's automatic captions, which
 * hold every line three times and show ">>" as "&gt;&gt;" (see CaptionTextRepairer). Other
 * transcripts lose only their ">>" marks.
 *
 * Reads every video with a transcript, a batch at a time. Without --apply, only reports.
 *
 * Usage:
 *   php bin/repair_caption_text.php                  # dry run: report what would change
 *   php bin/repair_caption_text.php --file-id=16123  # one video
 *   php bin/repair_caption_text.php --apply          # repair
 *   php bin/repair_caption_text.php --apply --limit=20   # repair at most 20 videos
 *   php bin/repair_caption_text.php --verbose        # list every video repaired, not only the totals
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\CaptionTextRepairer;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['apply', 'limit:', 'file-id:', 'verbose']);
$apply = isset($options['apply']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : PHP_INT_MAX;
$verbose = isset($options['verbose']) || isset($options['file-id']);

$repairer = new CaptionTextRepairer($pdo);
$totals = ['clean' => 0, 'repairable' => 0, 'mismatch' => 0, 'deleted' => 0, 'changed' => 0];

$report = static function (int $fileId, array $summary) use ($apply, $verbose, &$totals): void {
    $totals[$summary['status']]++;
    $totals['deleted'] += $summary['deleted'];
    $totals['changed'] += $summary['changed'];
    if ($summary['status'] === 'mismatch') {
        echo sprintf("  File #%d: left alone, rows don't match the WebVTT: %s\n", $fileId, $summary['reason']);
    } elseif ($summary['status'] === 'repairable' && $verbose) {
        echo sprintf(
            "  File #%d: %s %d of %d rows, %s %d\n",
            $fileId,
            $apply ? 'deleted' : 'would delete',
            $summary['deleted'],
            $summary['rows'],
            $apply ? 'cleaned' : 'would clean',
            $summary['changed']
        );
    }
};

$process = static function (int $fileId) use ($repairer, $apply, $report, $pdo, $log): void {
    try {
        $report($fileId, $apply ? $repairer->repair($fileId) : $repairer->inspect($fileId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo sprintf("  File #%d: FAILED: %s\n", $fileId, $e->getMessage());
        $log->put(sprintf('Caption text repair failed for file #%d: %s', $fileId, $e->getMessage()), 5);
    }
};

if (isset($options['file-id'])) {
    $process((int) $options['file-id']);
} else {
    $afterId = 0;
    while ($totals['repairable'] < $limit && ($ids = $repairer->candidates($afterId, 200)) !== []) {
        foreach ($ids as $fileId) {
            $process($fileId);
            $afterId = $fileId;
            if ($totals['repairable'] >= $limit) {
                break;
            }
        }
        echo sprintf("  ...through file #%d: %d to repair so far\n", $afterId, $totals['repairable']);
    }
}

echo sprintf(
    "\n%s %d videos (%d rows deleted, %d cleaned); %d needed nothing; %d left alone (rows don't match).\n",
    $apply ? 'Repaired' : 'Would repair',
    $totals['repairable'],
    $totals['deleted'],
    $totals['changed'],
    $totals['clean'],
    $totals['mismatch']
);
if (!$apply && $totals['repairable'] > 0) {
    echo "Dry run. Run again with --apply to repair.\n";
}
if ($apply && $totals['repairable'] > 0) {
    $log->put(sprintf('Repaired the caption text of %d videos (%d repeated rows deleted).', $totals['repairable'], $totals['deleted']), 3);
}
