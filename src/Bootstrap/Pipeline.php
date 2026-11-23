<?php

declare(strict_types=1);

namespace Ehf\Api\Bootstrap;

/**
 * Markers used in config/middleware.php for the two framework-provided pipeline steps.
 */
final class Pipeline
{
    public const string ERROR_HANDLING = 'pipeline.error_handling';

    public const string ROUTING = 'pipeline.routing';

    private function __construct() {}

    /**
     * @return list<string> pipeline steps, outermost first
     */
    public static function definition(): array
    {
        /** @var list<string> $steps */
        $steps = require \dirname(__DIR__, 2) . '/config/middleware.php';

        return $steps;
    }
}
