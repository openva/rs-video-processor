#!/usr/bin/env php
<?php

/**
 * Gives a slug to every video (files row) that doesn't have one, for video
 * pages' URLs on the website. Run this once, after files.slug is added, and
 * before it's made NOT NULL:
 *
 *   ALTER TABLE files ADD slug char(4) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id,
 *       ADD UNIQUE KEY slug (slug);
 *   php bin/backfill_slugs.php
 *   ALTER TABLE files MODIFY slug char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
 *
 * It's safe to run again: rows that have slugs are left alone.
 *
 * Usage:
 *   php bin/backfill_slugs.php
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Sync\VideoSlugBackfill;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$count = (new VideoSlugBackfill($pdo))->run();
$remaining = (int) $pdo->query('SELECT COUNT(*) FROM files WHERE slug IS NULL')->fetchColumn();

echo sprintf("Gave %d video(s) slugs. %d video(s) have none.\n", $count, $remaining);
if ($remaining === 0) {
    echo "Every video has a slug, so files.slug can be made NOT NULL:\n\n"
        . "  ALTER TABLE files MODIFY slug char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;\n";
}

exit($remaining === 0 ? 0 : 1);
