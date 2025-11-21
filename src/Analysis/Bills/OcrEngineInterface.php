<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

interface OcrEngineInterface
{
    public function extractText(string $imagePath): string;
}
