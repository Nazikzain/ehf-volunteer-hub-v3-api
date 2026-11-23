<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

/**
 * Invalid or missing configuration. Raised at start-up so a misconfigured
 * deployment fails closed instead of running with unsafe defaults.
 * Messages name the variable, never its value.
 */
final class ConfigurationException extends \RuntimeException {}
