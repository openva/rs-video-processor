<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RuntimeException;

class TesseractOcrEngine implements OcrEngineInterface
{
    public function __construct(private string $binary = 'tesseract')
    {
    }

    public function extractText(string $imagePath): string
    {
        $cmd = sprintf(
            '%s %s stdout --psm 7 2>/dev/null',
            escapeshellcmd($this->binary),
            escapeshellarg($imagePath)
        );
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new RuntimeException('Tesseract failed to produce output.');
        }
        return trim($output);
    }
}
