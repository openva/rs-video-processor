<?php

namespace RichmondSunlight\VideoProcessor\Tests\Resolution;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Resolution\BillResolver;
use RichmondSunlight\VideoProcessor\Resolution\HistoricalRawTextLookup;
use RichmondSunlight\VideoProcessor\Resolution\LegislatorResolver;
use RichmondSunlight\VideoProcessor\Resolution\RawTextResolver;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class RawTextResolverHistoricalFallbackTest extends TestCase
{
    public function testHistoricalFallbackIsUsedWhenPrimaryFails(): void
    {
        $pdo = TestDatabaseFactory::create();

        $pdo->exec("INSERT INTO files (id, chamber, date, video_index_cache, date_created, date_modified)
                    VALUES (1, 'house', '2025-01-15', NULL, '2025-01-15', '2025-01-15')");
        $pdo->exec('CREATE TABLE sessions (id INTEGER PRIMARY KEY, date_started TEXT, date_ended TEXT)');
        $pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (10, '2025-01-08', '2025-03-15')");
        $pdo->exec('CREATE TABLE terms (person_id INTEGER, session_id INTEGER, date_started TEXT, date_ended TEXT)');
        $pdo->exec("INSERT INTO people (id, name) VALUES (99, 'Reid')");
        $pdo->exec("INSERT INTO terms (person_id, session_id, date_started, date_ended) VALUES (99, 10, '2020-01-01', '2025-03-15')");

        // Unresolved entry for the file
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:01:00', '00060', 'Delegate Reid', 'legislator', NULL, 'n', '2025-01-15')");

        // Historical matches pointing to person 99
        for ($i = 0; $i < 4; $i++) {
            $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                        VALUES (2, '00:01:00', '00060', 'Delegate Reid', 'legislator', 99, 'n', '2025-01-10')");
        }

        $legislatorResolver = $this->createMock(LegislatorResolver::class);
        $legislatorResolver->method('resolve')->willReturn(null);

        $billResolver = $this->createMock(BillResolver::class);

        $historicalLookup = new HistoricalRawTextLookup($pdo);

        $resolver = new RawTextResolver($pdo, $legislatorResolver, $billResolver, null, $historicalLookup);

        $stats = $resolver->resolveFile(1);

        $this->assertSame(1, $stats['resolved']);
        $this->assertSame(0, $stats['unresolved']);

        $stmt = $pdo->query("SELECT linked_id FROM video_index WHERE file_id = 1 AND raw_text = 'Delegate Reid'");
        $this->assertSame(99, (int) $stmt->fetchColumn());
    }

    public function testHistoricalFallbackNotUsedWhenPrimarySucceeds(): void
    {
        $pdo = TestDatabaseFactory::create();

        $pdo->exec("INSERT INTO files (id, chamber, date, video_index_cache, date_created, date_modified)
                    VALUES (1, 'house', '2025-01-15', NULL, '2025-01-15', '2025-01-15')");
        $pdo->exec('CREATE TABLE sessions (id INTEGER PRIMARY KEY, date_started TEXT, date_ended TEXT)');
        $pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (10, '2025-01-08', '2025-03-15')");
        $pdo->exec('CREATE TABLE terms (person_id INTEGER, session_id INTEGER, date_started TEXT, date_ended TEXT)');
        $pdo->exec("INSERT INTO people (id, name) VALUES (99, 'Reid')");
        $pdo->exec("INSERT INTO terms (person_id, session_id, date_started, date_ended) VALUES (99, 10, '2020-01-01', '2025-03-15')");

        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:01:00', '00060', 'Delegate Reid', 'legislator', NULL, 'n', '2025-01-15')");

        $legislatorResolver = $this->createMock(LegislatorResolver::class);
        $legislatorResolver->method('resolve')->willReturn(['id' => 77, 'name' => 'Other', 'confidence' => 90.0]);

        $billResolver = $this->createMock(BillResolver::class);

        $historicalLookup = $this->createMock(HistoricalRawTextLookup::class);
        $historicalLookup->expects($this->never())->method('lookup');

        $resolver = new RawTextResolver($pdo, $legislatorResolver, $billResolver, null, $historicalLookup);
        $stats = $resolver->resolveFile(1);

        $this->assertSame(1, $stats['resolved']);
        $stmt = $pdo->query("SELECT linked_id FROM video_index WHERE file_id = 1");
        $this->assertSame(77, (int) $stmt->fetchColumn());
    }
}
