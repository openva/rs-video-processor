<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Analysis\Bills\OcrEngineInterface;
use RuntimeException;

class SpeakerNameOcrEngine implements OcrEngineInterface
{
    private const CHAR_WHITELIST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz .\'-';

    public function __construct(private string $binary = 'tesseract')
    {
    }

    public function extractText(string $imagePath): string
    {
        $cmd = sprintf(
            '%s %s stdout --psm 7 --oem 1 -c preserve_interword_spaces=1 -c tessedit_char_whitelist=%s',
            escapeshellcmd($this->binary),
            escapeshellarg($imagePath),
            escapeshellarg(self::CHAR_WHITELIST)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute tesseract.');
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = proc_close($process);
        if ($status !== 0) {
            $message = trim($errorOutput) ?: 'Tesseract failed to produce output.';
            throw new RuntimeException($message);
        }

        return trim($output);
    }
}
