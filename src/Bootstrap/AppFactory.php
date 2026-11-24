<?php

declare(strict_types=1);

namespace Ehf\Api\Bootstrap;

use DI\ContainerBuilder;
use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\JsonErrorHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the Slim application: container, routes and the middleware pipeline in the order of
 * config/middleware.php (API §2.12).
 */
final class AppFactory
{
    private function __construct() {}

    /**
     * @param LoggerInterface|null $logger      overrides the file logger (tests)
     * @param array<string, mixed>  $definitions extra container definitions (tests, e.g. a fixed clock)
     *
     * @return App<ContainerInterface>
     */
    public static function create(Settings $settings, ?LoggerInterface $logger = null, array $definitions = []): App
    {
        $configDir = \dirname(__DIR__, 2) . '/config';

        /** @var \Closure(Settings, ?LoggerInterface): array<string, mixed> $configDefinitions */
        $configDefinitions = require $configDir . '/container.php';

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions($configDefinitions($settings, $logger));
        if ($definitions !== []) {
            $builder->addDefinitions($definitions);
        }
        $container = $builder->build();

        SlimAppFactory::setContainer($container);
        /** @var App<ContainerInterface> $app */
        $app = SlimAppFactory::create();
        $app->setBasePath($settings->basePath);

        /** @var \Closure(App<ContainerInterface>): void $routes */
        $routes = require $configDir . '/routes.php';
        $routes($app);

        // Slim runs the most recently added middleware first, so add innermost first.
        foreach (array_reverse(Pipeline::definition()) as $step) {
            match ($step) {
                Pipeline::ROUTING => $app->addRoutingMiddleware(),
                Pipeline::ERROR_HANDLING => $app
                    ->addErrorMiddleware(false, false, false)
                    ->setDefaultErrorHandler(self::errorHandler($container)),
                default => $app->add(self::middleware($container, $step)),
            };
        }

        return $app;
    }

    private static function errorHandler(ContainerInterface $container): JsonErrorHandler
    {
        $handler = $container->get(JsonErrorHandler::class);
        if (!$handler instanceof JsonErrorHandler) {
            throw new \LogicException('The container did not return the JSON error handler.');
        }

        return $handler;
    }

    private static function middleware(ContainerInterface $container, string $class): MiddlewareInterface
    {
        $middleware = $container->get($class);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \LogicException(\sprintf('%s is not a PSR-15 middleware.', $class));
        }

        return $middleware;
    }
}
