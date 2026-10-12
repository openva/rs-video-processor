#!/usr/bin/env php
<?php

/**
 * Confirms the two tables this pipeline depends on exist.
 *
 * video_jobs and video_sources are defined with the rest of the shared schema,
 * in the richmondsunlight.com repository (deploy/mysql/structure.sql). This
 * repository never creates or alters tables. Every job queue reads video_jobs
 * and the importer reads video_sources, so deploy/run-pipeline.sh runs this
 * first and stops if either is missing.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

$log = new Log();

$pdo = AppBootstrap::createFreshConnection();
if (!$pdo instanceof PDO) {
    $log->put('Schema check could not connect to the database.', 7);
    exit(1);
}

foreach (['video_sources', 'video_jobs'] as $table) {
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (Throwable $e) {
        $log->put(
            "The {$table} table is missing, so the video pipeline cannot run. It is defined in the"
            . ' richmondsunlight.com repository (deploy/mysql/structure.sql) and must be created there first.',
            7
        );
        exit(1);
    }
}

echo "Pipeline tables are present.\n";
