<?php

declare(strict_types=1);

/**
 * Dependency injection definitions (php-di). Classes not listed here are autowired.
 */

use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\JsonErrorHandler;
use Ehf\Api\Infrastructure\Database\ConnectionFactory;
use Ehf\Api\Infrastructure\Id\RequestIdGenerator;
use Ehf\Api\Infrastructure\Id\UlidGenerator;
use Ehf\Api\Infrastructure\Logging\LoggerFactory;
use Ehf\Api\Infrastructure\Time\Clock;
use Ehf\Api\Infrastructure\Time\SystemClock;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return static function (Settings $settings, ?LoggerInterface $logger): array {
    return [
        Settings::class => $settings,
        ResponseFactoryInterface::class => DI\create(ResponseFactory::class),
        RequestIdGenerator::class => DI\create(UlidGenerator::class),
        LoggerInterface::class => $logger ?? DI\factory(static fn(ContainerInterface $c): LoggerInterface => LoggerFactory::create($settings)),
        ConnectionFactory::class => DI\factory(static fn(): ConnectionFactory => new ConnectionFactory($settings->database)),
        JsonErrorHandler::class => DI\autowire(),
        Clock::class => DI\create(SystemClock::class),
    ];
};
