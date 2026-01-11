<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use PDO;

class LegislatorDirectory
{
    private array $entries = [];

    public function __construct(private PDO $pdo)
    {
        $this->load();
    }

    private function load(): void
    {
        $sql = 'SELECT id, name, name_formal FROM people';
        foreach ($this->pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            $fullName = trim((string) ($row['name'] ?? ''));
            $parts = explode(' ', $fullName, 2);
            $this->entries[] = [
                'id' => (int) $row['id'],
                'first' => $parts[0] ?? '',
                'last' => $parts[1] ?? '',
                'full' => $fullName,
                'formal' => trim((string) ($row['name_formal'] ?? '')),
            ];
        }
    }

    public function matchId(string $name): ?int
    {
        $normalized = strtoupper(trim($name));
        foreach ($this->entries as $entry) {
            $full = strtoupper(trim($entry['first'] . ' ' . $entry['last']));
            if ($full === $normalized) {
                return $entry['id'];
            }
        }
        // fallback fuzzy
        $best = null;
        $bestScore = 0;
        foreach ($this->entries as $entry) {
            $full = strtoupper(trim($entry['first'] . ' ' . $entry['last']));
            similar_text($full, $normalized, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $entry['id'];
            }
        }
        return $bestScore > 60 ? $best : null;
    }
}
