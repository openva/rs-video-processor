<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;

class SpeakerChamberConfig
{
    /** @var array<string,CropConfig> */
    private array $configs;

    public function __construct()
    {
        $this->configs = [
            'house_floor' => new CropConfig(0.3, 0.77, 0.55, 0.12),
            'house_committee' => new CropConfig(0, 0.05, 0.15, 0.1),
            'senate_floor' => new CropConfig(0.14, 0.82, 1.0, 0.08),
            'senate_committee' => new CropConfig(0.14, 0.82, 0.86, 0.08),
        ];
    }

    public function getCrop(string $chamber, string $eventType): ?CropConfig
    {
        $type = $eventType === 'subcommittee' ? 'committee' : $eventType;
        $key = strtolower($chamber) . '_' . strtolower($type);
        return $this->configs[$key] ?? null;
    }
}
