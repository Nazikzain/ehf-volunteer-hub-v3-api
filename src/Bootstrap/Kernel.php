<?php

declare(strict_types=1);

namespace Ehf\Api\Bootstrap;

use Ehf\Api\Config\ConfigurationException;
use Ehf\Api\Config\Environment;
use Ehf\Api\Config\Settings;

/**
 * Entry point used by public/index.php.
 */
final class Kernel
{
    private function __construct() {}

    public static function run(string $projectRoot): void
    {
        try {
            $settings = Settings::fromEnvironment(Environment::load($projectRoot), $projectRoot);
            AppFactory::create($settings)->run();
        } catch (\Throwable $e) {
            // The application logger may not exist yet; use the PHP error log (server-private).
            // Configuration messages name the variable, never its value.
            $reason = $e instanceof ConfigurationException ? $e->getMessage() : $e::class;
            error_log('[ehf-api] request failed outside the pipeline: ' . $reason);
            FallbackResponse::emit();
        }
    }
}
