#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;

$app = require __DIR__ . '/bootstrap.php';

$pdo = $app->pdo;
$indexer = new MetadataIndexer($pdo);

$stmt = $pdo->query("SELECT id, video_index_cache FROM files WHERE video_index_cache IS NOT NULL AND video_index_cache != '' AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = files.id AND vi.type IN ('agenda','speaker'))");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $metadata = json_decode($row['video_index_cache'], true);
    if (!is_array($metadata)) {
        continue;
    }
    $indexer->index((int) $row['id'], $metadata);
    $app->log?->put('Indexed agenda/speakers for file #' . $row['id'], 3);
}
