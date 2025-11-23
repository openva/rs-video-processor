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
            'house_floor' => new CropConfig(0.02, 0.75, 0.55, 0.2),
            'house_committee' => new CropConfig(0, 0.05, 0.15, 0.1),
            'senate_floor' => new CropConfig(0.0, 0.78, 1.0, 0.22),
            'senate_committee' => new CropConfig(0.07, 0.85, 0.93, 0.1),
        ];
    }

    public function getCrop(string $chamber, string $eventType): ?CropConfig
    {
        $type = $eventType === 'subcommittee' ? 'committee' : $eventType;
        $key = strtolower($chamber) . '_' . strtolower($type);
        return $this->configs[$key] ?? null;
    }
}
