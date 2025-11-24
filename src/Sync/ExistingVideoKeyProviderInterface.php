<?php

namespace RichmondSunlight\VideoProcessor\Sync;

interface ExistingVideoKeyProviderInterface
{
    /**
     * @return array<string, bool> Array keyed by composite video identifiers.
     */
    public function fetchKeys(): array;
}
