<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Logging;

use Ehf\Api\Config\Settings;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Application log: JSON lines in private storage (outside the web root), with secrets redacted.
 * Separate from the audit log, which is a database table from Phase 4 (API §11).
 */
final class LoggerFactory
{
    public static function create(Settings $settings): LoggerInterface
    {
        $handler = new StreamHandler($settings->logPath, self::level($settings->logLevel), filePermission: 0o640);
        $handler->setFormatter(new JsonFormatter(includeStacktraces: true));

        $logger = new Logger('ehf-api');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new RedactionProcessor());

        return $logger;
    }

    private static function level(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if (strtolower($level->getName()) === $name) {
                return $level;
            }
        }

        throw new \InvalidArgumentException('Unknown log level.');
    }
}
