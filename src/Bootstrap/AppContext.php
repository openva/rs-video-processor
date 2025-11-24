<?php

namespace RichmondSunlight\VideoProcessor\Bootstrap;

use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class AppContext
{
    public function __construct(
        public Log $log,
        public PDO $pdo,
        public JobDispatcher $dispatcher
    ) {
    }
}
