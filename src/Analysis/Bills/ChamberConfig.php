<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class ChamberConfig
{
    /** @var array<string,CropConfig> */
    private array $configs;

    public function __construct()
    {
        $this->configs = [
            'senate_floor' => new CropConfig(0.75, 0.11, 0.14, 0.05),
            'senate_committee' => new CropConfig(0.75, 0.11, 0.14, 0.06),
            'house_floor' => new CropConfig(0.74, 0.11, 0.15, 0.08),
            'house_committee' => new CropConfig(0.00, 0.00, 0.2, 0.08),
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
