<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

interface DiarizerInterface
{
    /**
     * @return array<int,array{name:string,start:float}>
     */
    public function diarize(string $audioPath): array;
}
