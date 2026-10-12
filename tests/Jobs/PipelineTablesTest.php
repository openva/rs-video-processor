<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class PipelineTablesTest extends TestCase
{
    public function testHelperCreatesBothTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        TestDatabaseFactory::addPipelineTables($pdo);

        $pdo->exec("INSERT INTO video_sources (source, source_id, file_id, date_created)
            VALUES ('house', '1', 1, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO video_jobs (file_id, stage, status, attempts, date_modified)
            VALUES (1, 'download', 'running', 0, '2026-01-01 00:00:00')");

        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM video_sources')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM video_jobs')->fetchColumn());
    }

    public function testFactoryDatabaseIncludesPipelineTables(): void
    {
        $pdo = TestDatabaseFactory::create();
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM video_jobs')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM video_sources')->fetchColumn());
    }

    /**
     * The real tables are defined in the shared schema dump, which is copied
     * from the richmondsunlight.com repository. The SQLite helper above must
     * have the same columns.
     */
    public function testSchemaDumpDefinesTheSameColumns(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../deploy/mysql/structure.sql');
        $expected = [
            'video_sources' => ['source', 'source_id', 'file_id', 'date_created'],
            'video_jobs' => [
                'file_id', 'stage', 'status', 'attempts', 'claimed_at',
                'next_attempt_at', 'last_error', 'date_modified',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $found = preg_match('/CREATE TABLE `' . $table . '` \((.*?)\n\) ENGINE/s', $sql, $match);
            $this->assertSame(1, $found, "structure.sql must define {$table}.");
            foreach ($columns as $column) {
                $this->assertStringContainsString("\n  `" . $column . '` ', $match[1], "{$table}.{$column}");
            }
        }
    }
}
