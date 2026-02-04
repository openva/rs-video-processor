<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RichmondSunlight\VideoProcessor\Analysis\ChyronRegionConfig;

class ChamberConfig
{
    private ChyronRegionConfig $regionConfig;

    public function __construct(?ChyronRegionConfig $regionConfig = null)
    {
        $this->regionConfig = $regionConfig ?? new ChyronRegionConfig();
    }

    public function getCrop(string $chamber, string $eventType, string $date = '2020-01-01'): ?CropConfig
    {
        return $this->regionConfig->getBillCrop($chamber, $eventType, $date);
    }
}
