<?php

declare(strict_types=1);

/*
 * The only PHP file the web server may serve (API V1.3.1 §2.12). Everything else —
 * source, configuration, vendor, storage and the private .env — lives outside public/.
 */

use Ehf\Api\Bootstrap\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

header_remove('X-Powered-By');

Kernel::run(dirname(__DIR__));
