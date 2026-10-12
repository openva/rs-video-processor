#!/usr/bin/env php
<?php

/**
 * Shows what the pipeline has pending and what it has given up on.
 *
 * Usage:
 *   php bin/job_status.php                      # pending counts and given-up jobs
 *   php bin/job_status.php --reset=18797        # forget all ledger state for one file
 *   php bin/job_status.php --reset-stage=archive  # retry every given-up job in one stage
 *   php bin/job_status.php --reset-failed       # retry every given-up job
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$ledger = new JobLedger($pdo);

$options = getopt('', ['reset:', 'reset-stage:', 'reset-failed']);

if (isset($options['reset'])) {
    $removed = $ledger->reset((int) $options['reset']);
    echo "Cleared {$removed} ledger row(s) for file #" . (int) $options['reset'] . ".\n";
    exit(0);
}

if (isset($options['reset-stage'])) {
    $stage = (string) $options['reset-stage'];
    if (!in_array($stage, JobLedger::STAGES, true)) {
        fwrite(STDERR, 'Unknown stage. Choose one of: ' . implode(', ', JobLedger::STAGES) . "\n");
        exit(1);
    }
    $removed = $ledger->reset(null, $stage, true);
    echo "Cleared {$removed} given-up {$stage} job(s).\n";
    exit(0);
}

if (isset($options['reset-failed'])) {
    $removed = $ledger->reset(null, null, true);
    echo "Cleared {$removed} given-up job(s).\n";
    exit(0);
}

echo "\nPending now:\n";
foreach ((new PendingWork($pdo))->counts() as $stage => $count) {
    echo sprintf("  %-12s %d\n", $stage, $count);
}

$failed = $ledger->failedJobs();
echo sprintf("\nGiven up on (%d):\n", count($failed));
foreach ($failed as $job) {
    echo sprintf(
        "  file #%-6d %-12s %s UTC  %s\n",
        (int) $job['file_id'],
        $job['stage'],
        $job['date_modified'],
        substr(str_replace("\n", ' ', (string) $job['last_error']), 0, 140)
    );
}
echo "\n";
