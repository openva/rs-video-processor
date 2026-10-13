<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

/**
 * Storage that can upload many small files at once. Screenshots are tens of
 * thousands of files per video; sending them one at a time is what made the
 * screenshot stage slow.
 */
interface BatchStorageInterface extends StorageInterface
{
    /**
     * @param array<string,string> $files storage key => local path
     * @return array<string,string> storage key => public URL, in the order given
     * @throws \RuntimeException if any upload fails
     */
    public function uploadBatch(array $files, int $concurrency = 20): array;
}
