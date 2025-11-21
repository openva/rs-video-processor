<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class ChamberConfig
{
    /** @var array<string,CropConfig> */
    private array $configs;

    public function __construct()
    {
        $this->configs = [
            'senate_floor' => new CropConfig(0.70, 0.08, 0.28, 0.14),
            'senate_committee' => new CropConfig(0.70, 0.08, 0.28, 0.14),
            'house_floor' => new CropConfig(0.0, 0.0, 1.0, 0.18),
            'house_committee' => new CropConfig(0.02, 0.05, 0.96, 0.25),
        ];
    }

    public function getCrop(string $chamber, string $eventType): ?CropConfig
    {
        $type = $eventType === 'subcommittee' ? 'subcommittee' : ($eventType === 'committee' ? 'committee' : 'floor');
        $key = strtolower($chamber) . '_' . $type;
        if ($type === 'floor' && !isset($this->configs[$key])) {
            $key = strtolower($chamber) . '_committee';
        }
        return $this->configs[$key] ?? null;
    }
}
