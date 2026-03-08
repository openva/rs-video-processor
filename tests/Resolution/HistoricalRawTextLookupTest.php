<?php

namespace RichmondSunlight\VideoProcessor\Tests\Resolution;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Resolution\HistoricalRawTextLookup;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class HistoricalRawTextLookupTest extends TestCase
{
    private PDO $pdo;
    private HistoricalRawTextLookup $lookup;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();

        // Additional tables needed for session validation
        $this->pdo->exec('CREATE TABLE sessions (
            id INTEGER PRIMARY KEY,
            date_started TEXT,
            date_ended TEXT
        )');
        $this->pdo->exec('CREATE TABLE terms (
            person_id INTEGER,
            session_id INTEGER,
            date_started TEXT,
            date_ended TEXT
        )');
        $this->pdo->exec('CREATE TABLE bills (
            id INTEGER PRIMARY KEY,
            session_id INTEGER,
            number TEXT
        )');

        // Session 1: 2025 regular session
        $this->pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (1, '2025-01-08', '2025-03-15')");
        // Person 99 exists and has a term in session 1
        $this->pdo->exec("INSERT INTO people (id, name) VALUES (99, 'Reid')");
        $this->pdo->exec("INSERT INTO terms (person_id, session_id, date_started, date_ended) VALUES (99, 1, '2020-01-01', '2025-03-15')");

        // Bill 55 exists in session 1
        $this->pdo->exec("INSERT INTO bills (id, session_id, number) VALUES (55, 1, 'HB1234')");

        $this->lookup = new HistoricalRawTextLookup($this->pdo);
    }

    // --- consensus logic ---

    public function testReturnsNullWhenNoHistory(): void
    {
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenBelowMinimumOccurrences(): void
    {
        // 2 prior matches — below the minimum of 3
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 2);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenHistoryIsAmbiguous(): void
    {
        // 3 matches for person 99, 3 for person 100 — too ambiguous
        $this->pdo->exec("INSERT INTO people (id, name) VALUES (100, 'Reid Jr.')");
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 3);
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 100, 3);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNull($result);
    }

    public function testReturnsClearLegislatorConsensus(): void
    {
        // 4 matches, all person 99 — clear consensus, passes session validation
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 4);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNotNull($result);
        $this->assertSame(99, $result['id']);
        $this->assertSame('Reid', $result['name']);
        $this->assertSame(65.0, $result['confidence']);
    }

    public function testMajorityAbove80PercentPasses(): void
    {
        // 5 matches for 99, 1 for 100 → 83% majority — passes
        $this->pdo->exec("INSERT INTO people (id, name) VALUES (100, 'Reid Jr.')");
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 5);
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 100, 1);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNotNull($result);
        $this->assertSame(99, $result['id']);
    }

    public function testMajorityAt80PercentPasses(): void
    {
        // 4 matches for 99, 1 for 100 → 80% majority — passes (boundary)
        $this->pdo->exec("INSERT INTO people (id, name) VALUES (100, 'Reid Jr.')");
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 4);
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 100, 1);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', 1);
        $this->assertNotNull($result);
        $this->assertSame(99, $result['id']);
    }

    // --- session validation ---

    public function testReturnsNullWhenLegislatorNotInSession(): void
    {
        // Person 98 has no term in session 1
        $this->pdo->exec("INSERT INTO people (id, name) VALUES (98, 'Old Delegate')");
        $this->insertHistoricalMatch('Old Delegate', 'legislator', 98, 5);
        $result = $this->lookup->lookup('Old Delegate', 'legislator', 1);
        $this->assertNull($result);
    }

    public function testReturnsClearBillConsensus(): void
    {
        // 3 matches all pointing to bill 55 in session 1
        $this->insertHistoricalMatch('HB 1234', 'bill', 55, 3);
        $result = $this->lookup->lookup('HB 1234', 'bill', 1);
        $this->assertNotNull($result);
        $this->assertSame(55, $result['id']);
        $this->assertSame('HB1234', $result['number']);
        $this->assertSame(65.0, $result['confidence']);
    }

    public function testReturnsNullWhenBillNotInCurrentSession(): void
    {
        // Bill 55 exists in session 1, but we're resolving against session 2
        $this->pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (2, '2026-01-07', '2099-12-31')");
        $this->insertHistoricalMatch('HB 1234', 'bill', 55, 3);
        $result = $this->lookup->lookup('HB 1234', 'bill', 2);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenSessionIdIsNull(): void
    {
        $this->insertHistoricalMatch('Delegate Reid', 'legislator', 99, 5);
        $result = $this->lookup->lookup('Delegate Reid', 'legislator', null);
        $this->assertNull($result);
    }

    // --- helper ---

    private function insertHistoricalMatch(string $rawText, string $type, int $linkedId, int $count): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
             VALUES (1, "00:01:00", "00060", :raw_text, :type, :linked_id, "n", "2025-01-01 00:00:00")'
        );
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([':raw_text' => $rawText, ':type' => $type, ':linked_id' => $linkedId]);
        }
    }
}
