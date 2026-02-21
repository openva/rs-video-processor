#!/usr/bin/env php
<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('RS_VIDEO_FIXTURE_BASE_URL') ?: 'https://video.richmondsunlight.com/tests', '/');
$targetDir = realpath(__DIR__ . '/../tests/fixtures') ?: __DIR__ . '/../tests/fixtures';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

$fixtures = [
    'senate-floor.mp4' => 'Sample Senate floor session',
    'house-floor.mp4' => 'Sample House floor session',
    'senate-committee.mp4' => 'Sample Senate committee hearing',
    'house-committee.mp4' => 'Sample House committee hearing',
];

$options = getopt('', ['force']);
$force = isset($options['force']);
$errors = 0;

foreach ($fixtures as $filename => $description) {
    $destination = $targetDir . '/' . $filename;
    if (!$force && file_exists($destination) && filesize($destination) > 0) {
        echo "✔ {$filename} already present\n";
        continue;
    }

    $url = $baseUrl . '/' . $filename;
    echo "↓ Downloading {$filename} ({$description}) from {$url}\n";
    try {
        download_fixture($url, $destination);
        echo "✔ Saved to {$destination}\n";
    } catch (RuntimeException $e) {
        $errors++;
        fwrite(STDERR, "✖ {$filename} failed: {$e->getMessage()}\n");
    }
}

if ($errors > 0) {
    exit(1);
}

function download_fixture(string $url, string $destination): void
{
    $temp = $destination . '.download';

    $command = sprintf(
        'curl -fsSL --retry 3 --retry-delay 2 -o %s %s 2>&1',
        escapeshellarg($temp),
        escapeshellarg($url)
    );
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        @unlink($temp);
        throw new RuntimeException('Download failed: ' . implode("\n", $output));
    }

    if (!file_exists($temp) || filesize($temp) === 0) {
        @unlink($temp);
        throw new RuntimeException('Download returned no data.');
    }

    if (!@rename($temp, $destination)) {
        @unlink($temp);
        throw new RuntimeException('Unable to move downloaded file into place.');
    }
}
