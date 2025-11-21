<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

interface StorageInterface
{
    public function upload(string $localPath, string $key): string;
}
