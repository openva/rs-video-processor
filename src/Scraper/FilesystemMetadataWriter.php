<?php

namespace RichmondSunlight\VideoProcessor\Scraper;

use DateTimeImmutable;
use RuntimeException;

class FilesystemMetadataWriter
{
    public function __construct(
        private string $directory
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function write(array $records): string
    {
        if (!is_dir($this->directory) && !mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Failed to create directory %s', $this->directory));
        }

        $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sprintf('videos-%s.json', $timestamp);

        $payload = [
            'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'record_count' => count($records),
            'records' => $records,
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
