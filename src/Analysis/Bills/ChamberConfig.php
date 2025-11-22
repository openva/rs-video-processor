<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class ChamberConfig
{
    /** @var array<string,CropConfig> */
    private array $configs;

    public function __construct()
    {
        $this->configs = [
            'senate_floor' => new CropConfig(0.73, 0.10, 0.28, 0.08),
            'senate_committee' => new CropConfig(0.73, 0.10, 0.28, 0.08),
            'house_floor' => new CropConfig(0.73, 0.09, 0.3, 0.10),
            'house_committee' => new CropConfig(0.00, 0.00, 1, 0.05),
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
