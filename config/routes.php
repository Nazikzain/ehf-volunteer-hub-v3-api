<?php

declare(strict_types=1);

/**
 * Routes layer (API §2.11): maps method + path to a controller. Every route is under
 * /api/v3 (API §2.2) and must appear in openapi/openapi.yaml with its x-permission
 * annotation (checked by tests/Contract).
 */

use Ehf\Api\Http\Controllers\HealthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->group('/api/v3', function (RouteCollectorProxy $v3): void { // not static: Slim binds group closures
        // Public (API Appendix A.1).
        $v3->get('/health', HealthController::class)->setName('health');
    });
};
