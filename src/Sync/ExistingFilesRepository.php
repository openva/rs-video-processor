<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;
use PDOException;

class ExistingFilesRepository implements ExistingVideoKeyProviderInterface
{
    public function __construct(
        private PDO $pdo,
        private $pdoFactory = null
    ) {
    }

    public function fetchKeys(): array
    {
        $keys = [];

        $stmt = $this->queryWithReconnect('SELECT chamber, date, path FROM files');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chamber = $row['chamber'] ?? null;
            $date = $row['date'] ?? null;
            $path = $row['path'] ?? null;

            if ($chamber === null || $date === null || $path === null) {
                continue;
            }

            $keys[$this->buildKey($chamber, $date, $path)] = true;
        }

        return $keys;
    }

    private function buildKey(string $chamber, string $date, string $path): string
    {
        return strtolower($chamber) . '|' . $date . '|' . $path;
    }

    private function queryWithReconnect(string $sql)
    {
        try {
            return $this->pdo->query($sql);
        } catch (PDOException $e) {
            if ($this->isConnectionGone($e) && is_callable($this->pdoFactory)) {
                $new = call_user_func($this->pdoFactory);
                if ($new instanceof PDO) {
                    $this->pdo = $new;
                    return $this->pdo->query($sql);
                }
            }
            throw new \RuntimeException('Unable to query files table: ' . $e->getMessage(), 0, $e);
        }
    }

    private function isConnectionGone(PDOException $e): bool
    {
        $code = (int) $e->getCode();
        if (in_array($code, [2006, 2013], true)) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'server has gone away') || str_contains($msg, 'lost connection');
    }
}
