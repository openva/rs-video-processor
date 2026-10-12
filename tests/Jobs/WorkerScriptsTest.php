<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;

class WorkerScriptsTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function scripts(): array
    {
        return [
            'download' => ['fetch_videos.php', 'JobLedger::STAGE_DOWNLOAD'],
            'screenshots' => ['generate_screenshots.php', 'JobLedger::STAGE_SCREENSHOTS'],
            'transcript' => ['generate_transcripts.php', 'JobLedger::STAGE_TRANSCRIPT'],
            'bills' => ['detect_bills.php', 'JobLedger::STAGE_BILLS'],
            'speakers' => ['detect_speakers.php', 'JobLedger::STAGE_SPEAKERS'],
            'archive' => ['upload_archive.php', 'JobLedger::STAGE_ARCHIVE'],
        ];
    }

    /**
     * @dataProvider scripts
     */
    public function testScriptUsesTheSharedWorkerLoop(string $script, string $stage): void
    {
        $path = __DIR__ . '/../../bin/' . $script;
        $source = file_get_contents($path);

        $this->assertStringContainsString('new StageRunner(', $source);
        $this->assertStringContainsString($stage, $source);
        $this->assertStringContainsString('->fetch(1)', $source, 'Jobs must be claimed one at a time.');
        $this->assertStringContainsString('AppBootstrap::connectionFactory()', $source);

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testConnectionFactoryIsAClosure(): void
    {
        $this->assertInstanceOf(\Closure::class, AppBootstrap::connectionFactory());
    }
}
