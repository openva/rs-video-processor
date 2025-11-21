<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

return AppBootstrap::boot();
