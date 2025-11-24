<?php

namespace RichmondSunlight\VideoProcessor\Sync;

class MissingVideoFilter
{
    public function __construct(
        private ExistingVideoKeyProviderInterface $keyProvider
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $records): array
    {
        $existingKeys = $this->keyProvider->fetchKeys();

        $missing = [];
        foreach ($records as $record) {
            $key = $this->buildKeyFromRecord($record);
            if ($key === null || !isset($existingKeys[$key])) {
                $missing[] = $record;
            }
        }

        return $missing;
    }

    private function buildKeyFromRecord(array $record): ?string
    {
        $chamber = isset($record['chamber']) ? strtolower((string) $record['chamber']) : null;
        $date = VideoRecordNormalizer::deriveMeetingDate($record);
        $duration = VideoRecordNormalizer::deriveDurationSeconds($record);

        if (!$chamber || !$date || $duration === null) {
            return null;
        }

        return sprintf('%s|%s|%d', $chamber, $date, $duration);
    }
}
