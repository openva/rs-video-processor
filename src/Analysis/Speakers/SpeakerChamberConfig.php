<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;
use RichmondSunlight\VideoProcessor\Analysis\ChyronRegionConfig;

class SpeakerChamberConfig
{
    private ChyronRegionConfig $regionConfig;

    public function __construct(?ChyronRegionConfig $regionConfig = null)
    {
        $this->regionConfig = $regionConfig ?? new ChyronRegionConfig();
    }

    public function getCrop(string $chamber, string $eventType, string $date = '2020-01-01'): ?CropConfig
    {
        return $this->regionConfig->getSpeakerCrop($chamber, $eventType, $date);
    }
}
