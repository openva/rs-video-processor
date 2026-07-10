#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\StaleClaimCleaner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

// Threshold is configurable so ops can tune recovery speed vs. safety against
// resetting a still-active claim. Default 3h: longer than the slowest single
// job, shorter than a drain session so orphans recover mid-session.
$maxAgeHours = getenv('STALE_CLAIM_MAX_AGE_HOURS');
$maxAgeHours = is_numeric($maxAgeHours) ? max(1, (int) $maxAgeHours) : 3;

$cleaner = new StaleClaimCleaner($pdo);
$counts = $cleaner->clean($maxAgeHours);

if ($counts['screenshot_claims'] > 0) {
    $log->put(sprintf('Released %d stale screenshot claim(s) for retry.', $counts['screenshot_claims']), 3);
}
if ($counts['index_placeholders'] > 0) {
    $log->put(sprintf('Released %d stale bill/speaker claim placeholder(s) for retry.', $counts['index_placeholders']), 3);
}
if ($counts['screenshot_claims'] === 0 && $counts['index_placeholders'] === 0) {
    $log->put('No stale claims found.', 2);
}
