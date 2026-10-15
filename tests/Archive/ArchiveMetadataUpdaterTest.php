<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveMetadataUpdater;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class ArchiveMetadataUpdaterTest extends TestCase
{
    private PDO $pdo;
    /** @var string[] */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
        $this->pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (43, 'Civil Law', 'civil', 'house', 5)");
    }

    private function updater(int $exitStatus = 0): ArchiveMetadataUpdater
    {
        $runner = function (string $command, array &$output) use ($exitStatus): int {
            $this->commands[] = $command;
            $output = $exitStatus === 0 ? ['success'] : ['error: item not found'];
            return $exitStatus;
        };

        return new ArchiveMetadataUpdater($this->pdo, new MetadataBuilder(), $runner);
    }

    private function seed(string $path, ?int $committeeId = 43, string $title = 'House Civil Subcommittee'): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (chamber, committee_id, title, date, path, date_created)
             VALUES ('house', :committee, :title, '2026-01-28', :path, '2026-01-28 00:00:00')"
        )->execute([':committee' => $committeeId, ':title' => $title, ':path' => $path]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testRewritesTitleSubjectAndDescriptionOfTheArchivedItem(): void
    {
        $id = $this->seed('https://archive.org/download/rs-house-20260128-house-session/ia_video_abc.mp4');

        $this->assertTrue($this->updater()->update($id));

        $this->assertCount(1, $this->commands);
        $command = $this->commands[0];
        $this->assertStringStartsWith("ia metadata 'rs-house-20260128-house-session'", $command);
        $this->assertStringContainsString("--modify='title:Virginia General Assembly House Civil Law Meeting, January 28, 2026'", $command);
        $this->assertStringContainsString("--modify='description:Virginia General Assembly House Civil Law meeting from 2026-01-28'", $command);
        $this->assertStringContainsString("--modify='subject:Virginia General Assembly; House chamber; Civil Law'", $command);
        $this->assertStringNotContainsString('collection', $command, 'Only the descriptive fields change.');
    }

    public function testFindsTheIdentifierInADetailsUrl(): void
    {
        $id = $this->seed('https://archive.org/details/rs-house-20260128-house-session');
        $this->updater()->update($id);

        $this->assertStringStartsWith("ia metadata 'rs-house-20260128-house-session'", $this->commands[0]);
    }

    public function testLeavesVideosThatAreNotArchivedAlone(): void
    {
        $id = $this->seed('https://video.richmondsunlight.com/house/floor/20260128-1.mp4');

        $this->assertFalse($this->updater()->update($id));
        $this->assertSame([], $this->commands);
    }

    public function testReportsAFailedUpdate(): void
    {
        $id = $this->seed('https://archive.org/download/rs-house-20260128-house-session/a.mp4');

        try {
            $this->updater(1)->update($id);
            $this->fail('A failed ia command must be reported.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('item not found', $e->getMessage());
        }
    }
}
