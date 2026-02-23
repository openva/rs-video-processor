<?php

namespace RichmondSunlight\VideoProcessor\Tests\Support;

class TestLogger extends \Log
{
    public array $entries = [];

    public function put($message, $level = 3)
    {
        $this->entries[] = ['message' => $message, 'level' => $level];
        return true;
    }
}
