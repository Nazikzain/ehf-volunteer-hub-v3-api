<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Contract;

use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\RouteAccess;
use Ehf\Api\Tests\Support\TestApp;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI contract is the API contract (API V1.3.1 §2.7, §12). These tests keep the code
 * and openapi/openapi.yaml in step:
 * - every route exists in the contract and every contract operation has a route;
 * - every operation carries the required x-* annotations, including exactly one x-permission;
 * - real responses validate against the contract schemas;
 * - the error-code enum matches the code (and therefore API §4.3).
 */
final class OpenApiContractTest extends TestCase
{
    private const string SCHEMA_ID = 'https://contract.ehf.invalid/openapi.json';

    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    private const array REQUIRED_ANNOTATIONS = [
        'x-action-type', 'x-permission', 'x-scope', 'x-context', 'x-field-sets', 'x-guards', 'x-workflow-dependency', 'x-audit',
    ];

    /** @var array<string, mixed> */
    private static array $document;

    public static function setUpBeforeClass(): void
    {
        /** @var array<string, mixed> $document */
        $document = Yaml::parseFile(\dirname(__DIR__, 2) . '/openapi/openapi.yaml');
        self::$document = $document;
    }

    public function testDocumentHeader(): void
    {
        self::assertSame('3.1.0', self::$document['openapi']);
        self::assertSame([['url' => '/api/v3', 'description' => 'Every route is under /api/v3 (API §2.2).']], self::$document['servers']);
    }

    public function testRoutesAndContractOperationsMatch(): void
    {
        $routes = [];
        /** @var RouteInterface $route */
        foreach ((new TestApp())->app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            self::assertStringStartsWith('/api/v3/', $pattern, 'Every route must be under /api/v3.');
            foreach ($route->getMethods() as $method) {
                $routes[] = strtolower($method) . ' ' . self::normalise(substr($pattern, \strlen('/api/v3')));
            }
        }

        $operations = [];
        foreach ($this->paths() as $path => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $operations[] = $method . ' ' . $path;
                }
            }
        }

        sort($routes);
        sort($operations);
        self::assertSame($operations, $routes);
    }

    public function testEveryRouteDeclaresTheSamePermissionAsTheContract(): void
    {
        /** @var RouteInterface $route */
        foreach ((new TestApp())->app->getRouteCollector()->getRoutes() as $route) {
            $path = self::normalise(substr($route->getPattern(), \strlen('/api/v3')));
            foreach ($route->getMethods() as $method) {
                $operation = $this->paths()[$path][strtolower($method)] ?? null;
                self::assertIsArray($operation, "{$method} {$path}");
                $declared = $route->getArgument(RouteAccess::PERMISSION);
                self::assertNotNull($declared, "{$method} {$path} declares no permission");
                $contract = $operation['x-permission'];
                self::assertIsString($contract);
                if ($declared === RouteAccess::SERVICE_CHECKED) {
                    self::assertStringContainsString(' or ', $contract, "{$method} {$path}");
                } else {
                    self::assertSame($declared, $contract, "{$method} {$path}");
                }
            }
        }
    }

    public function testEveryOperationHasTheRequiredAnnotations(): void
    {
        foreach ($this->paths() as $path => $item) {
            foreach (self::METHODS as $method) {
                if (!isset($item[$method])) {
                    continue;
                }

                $operation = $item[$method];
                self::assertIsArray($operation);
                foreach (self::REQUIRED_ANNOTATIONS as $annotation) {
                    self::assertArrayHasKey($annotation, $operation, "{$method} {$path}: {$annotation}");
                }

                self::assertIsString($operation['x-permission'], "{$method} {$path}: exactly one primary permission code");
                self::assertNotSame('', $operation['x-permission']);
            }
        }
    }

    public function testHealthResponseMatchesTheContract(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/health');

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSchema($response, 'HealthResponse');
    }

    public function testErrorResponsesMatchTheContract(): void
    {
        $app = new TestApp();

        $this->assertMatchesSchema($app->handle('GET', '/api/v3/missing'), 'ErrorResponse');
        $this->assertMatchesSchema($app->handle('OPTIONS', '/api/v3/health', [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]), 'ErrorResponse');
    }

    public function testErrorCodeEnumMatchesTheCode(): void
    {
        $schemas = $this->schemas();
        self::assertIsArray($schemas['ErrorCode']);

        $contract = $schemas['ErrorCode']['enum'];
        self::assertIsArray($contract);
        $code = array_map(static fn(ErrorCode $c): string => $c->value, ErrorCode::cases());

        sort($contract);
        sort($code);
        self::assertSame($code, $contract);
    }

    private function assertMatchesSchema(ResponseInterface $response, string $schemaName): void
    {
        $validator = new Validator();
        $schema = json_decode(json_encode(self::$document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        $validator->resolver()?->registerRaw($schema, self::SCHEMA_ID);

        $data = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate($data, self::SCHEMA_ID . '#/components/schemas/' . $schemaName);

        $errors = $result->error() === null ? [] : (new ErrorFormatter())->format($result->error());
        self::assertTrue($result->isValid(), $schemaName . ': ' . json_encode($errors, JSON_THROW_ON_ERROR));
    }

    /**
     * `/users/{id:[0-9]+}` → `/users/{id}`.
     */
    private static function normalise(string $path): string
    {
        return (string) preg_replace('/\{([a-zA-Z]+):(?:[^{}]|\{\d+\})+\}/', '{$1}', $path);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(): array
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::$document['paths'];

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $components = self::$document['components'];
        self::assertIsArray($components);
        $schemas = $components['schemas'];
        self::assertIsArray($schemas);

        /** @var array<string, mixed> $schemas */
        return $schemas;
    }
}
