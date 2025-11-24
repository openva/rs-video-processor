<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class CropConfig
{
    public function __construct(
        public float $xPercent,
        public float $yPercent,
        public float $widthPercent,
        public float $heightPercent
    ) {
    }
}
