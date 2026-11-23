<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

use Ehf\Api\Bootstrap\AppFactory;
use Ehf\Api\Config\AppEnvironment;
use Ehf\Api\Config\Settings;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Builds the real application (real container, routes and pipeline) with test settings and an
 * in-memory logger, and sends PSR-7 requests through it.
 */
final class TestApp
{
    public const string ALLOWED_ORIGIN = 'https://app.example.test';

    public readonly TestHandler $logs;

    /** @var App<ContainerInterface> */
    public readonly App $app;

    public function __construct(?Settings $settings = null)
    {
        $this->logs = new TestHandler();
        $this->app = AppFactory::create($settings ?? self::settings(), new Logger('test', [$this->logs]));
    }

    /**
     * @param list<string> $origins
     */
    public static function settings(
        AppEnvironment $environment = AppEnvironment::Testing,
        array $origins = [self::ALLOWED_ORIGIN],
        bool $hsts = false,
        int $maxJsonBodyBytes = Settings::DEFAULT_MAX_JSON_BODY_BYTES,
        string $basePath = '',
    ): Settings {
        return new Settings(
            environment: $environment,
            projectRoot: \dirname(__DIR__, 2),
            basePath: $basePath,
            corsAllowedOrigins: $origins,
            hstsEnabled: $hsts,
            maxJsonBodyBytes: $maxJsonBodyBytes,
            logPath: 'php://memory',
            logLevel: 'debug',
            database: null,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $path, array $headers = [], ?string $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }

        return $request;
    }

    /**
     * @param array<string, string> $headers
     */
    public function handle(string $method, string $path, array $headers = [], ?string $body = null): ResponseInterface
    {
        return $this->app->handle(self::request($method, $path, $headers, $body));
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Response body is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{code: string, message: string, details: list<mixed>, request_id: string}
     */
    public static function error(ResponseInterface $response): array
    {
        $body = self::json($response);
        $error = $body['error'] ?? null;
        if (!\is_array($error)) {
            throw new \UnexpectedValueException('Response has no error object.');
        }

        /** @var array{code: string, message: string, details: list<mixed>, request_id: string} $error */
        return $error;
    }
}
