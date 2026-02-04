<?php

namespace RichmondSunlight\VideoProcessor\Analysis;

use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads chyron region configurations from YAML file.
 *
 * Supports different crop regions for different chambers, event types, and eras.
 */
class ChyronRegionConfig
{
    private array $config;

    public function __construct(?string $configPath = null)
    {
        $configPath = $configPath ?? __DIR__ . '/../../config/chyron_regions.yaml';

        if (!file_exists($configPath)) {
            throw new \RuntimeException("Chyron regions config file not found: $configPath");
        }

        $this->config = Yaml::parseFile($configPath);
    }

    /**
     * Get crop configuration for bill detection.
     *
     * @param string $chamber Chamber name (house/senate)
     * @param string $eventType Event type (floor/committee/subcommittee)
     * @param string $date Video date in Y-m-d format
     * @return CropConfig|null
     */
    public function getBillCrop(string $chamber, string $eventType, string $date): ?CropConfig
    {
        return $this->getCrop('bill_detection', $chamber, $eventType, $date);
    }

    /**
     * Get crop configuration for speaker detection.
     *
     * @param string $chamber Chamber name (house/senate)
     * @param string $eventType Event type (floor/committee/subcommittee)
     * @param string $date Video date in Y-m-d format
     * @return CropConfig|null
     */
    public function getSpeakerCrop(string $chamber, string $eventType, string $date): ?CropConfig
    {
        return $this->getCrop('speaker_detection', $chamber, $eventType, $date);
    }

    /**
     * Get crop configuration for a specific detection type.
     *
     * @param string $detectionType 'bill_detection' or 'speaker_detection'
     * @param string $chamber Chamber name (house/senate)
     * @param string $eventType Event type (floor/committee/subcommittee)
     * @param string $date Video date in Y-m-d format
     * @return CropConfig|null
     */
    private function getCrop(string $detectionType, string $chamber, string $eventType, string $date): ?CropConfig
    {
        if (!isset($this->config[$detectionType])) {
            return null;
        }

        // Normalize event type (subcommittee -> committee for most purposes)
        $type = $eventType === 'subcommittee' ? 'committee' : $eventType;
        $key = strtolower($chamber) . '_' . strtolower($type);

        // Find the appropriate era based on date
        $era = $this->findEra($this->config[$detectionType], $date);

        if (!isset($era['regions'][$key])) {
            // Try committee fallback
            $fallbackKey = strtolower($chamber) . '_committee';
            if (isset($era['regions'][$fallbackKey])) {
                $key = $fallbackKey;
            } else {
                return null;
            }
        }

        $coords = $era['regions'][$key];

        if (!is_array($coords) || count($coords) !== 4) {
            return null;
        }

        return new CropConfig(
            (float) $coords[0],
            (float) $coords[1],
            (float) $coords[2],
            (float) $coords[3]
        );
    }

    /**
     * Find the appropriate era configuration for a given date.
     * Returns the era with the latest min_date that is <= the given date.
     *
     * @param array $detectionConfig Configuration for bill_detection or speaker_detection
     * @param string $date Video date in Y-m-d format
     * @return array Era configuration
     */
    private function findEra(array $detectionConfig, string $date): array
    {
        $matchingEras = [];

        foreach ($detectionConfig as $eraName => $eraConfig) {
            if (!isset($eraConfig['min_date']) || !isset($eraConfig['regions'])) {
                continue;
            }

            // Check if this era applies to the given date
            if ($date >= $eraConfig['min_date']) {
                $matchingEras[$eraName] = $eraConfig;
            }
        }

        if (empty($matchingEras)) {
            // Fall back to 'default' era if available
            return $detectionConfig['default'] ?? ['regions' => []];
        }

        // Return the era with the latest min_date
        uasort($matchingEras, fn($a, $b) => strcmp($b['min_date'], $a['min_date']));
        return reset($matchingEras);
    }
}
