<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use PDO;

class CommitteeDirectory
{
    private array $byId = [];
    private array $byChamberType = [];

    public function __construct(private PDO $pdo)
    {
        $this->load();
    }

    private function load(): void
    {
        $sql = "SELECT
            id,
            name,
            shortname,
            chamber,
            CASE WHEN parent_id IS NULL THEN 'committee' ELSE 'subcommittee' END AS type
        FROM committees
        ORDER BY chamber ASC";

        foreach ($this->pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            $entry = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'shortname' => $row['shortname'],
                'chamber' => $row['chamber'],
                'type' => $row['type'],
            ];
            $this->byId[$entry['id']] = $entry;
            $chamber = strtolower($entry['chamber']);
            $type = strtolower($entry['type']);
            $this->byChamberType[$chamber][$type][] = $entry;
        }
    }

    public function getShortnameById(?int $id): ?string
    {
        if (!$id || !isset($this->byId[$id])) {
            return null;
        }
        return strtolower((string) $this->byId[$id]['shortname']);
    }

    public function matchId(?string $name, string $chamber, string $type): ?int
    {
        $entry = $this->matchEntry($name, $chamber, $type);
        return $entry['id'] ?? null;
    }

    public function matchEntry(?string $name, string $chamber, string $type): ?array
    {
        if (!$name) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $chamberKey = strtolower($chamber);
        $typeKey = strtolower($type);
        $candidates = $this->byChamberType[$chamberKey][$typeKey] ?? [];
        if (empty($candidates)) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            if (strcasecmp($candidateName, $name) === 0) {
                return $candidate;
            }
            similar_text(strtoupper($candidateName), strtoupper($name), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }
        // Only accept matches with at least 70% similarity
        if ($bestScore < 70) {
            return null;
        }
        return $best;
    }
}
