<?php

namespace RichmondSunlight\VideoProcessor\Bootstrap;

use Log;
use RuntimeException;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class AppBootstrap
{
    public static function boot(): AppContext
    {
        $log = new Log();
        $database = new \Database();
        $pdo = $database->connect();
        if (!$pdo) {
            throw new RuntimeException('Unable to connect to database.');
        }

        $dispatcher = JobDispatcher::fromEnvironment($log);

        return new AppContext($log, $pdo, $dispatcher);
    }
}
