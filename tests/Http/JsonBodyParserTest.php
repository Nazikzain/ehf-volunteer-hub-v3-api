<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Http;

use Ehf\Api\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class JsonBodyParserTest extends TestCase
{
    private TestApp $test;

    protected function setUp(): void
    {
        $this->test = new TestApp(TestApp::settings(maxJsonBodyBytes: 2048));
        // Echo route used only by these tests.
        $this->test->app->post('/api/v3/__test/echo', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write(json_encode(['parsed' => $request->getParsedBody()], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });
    }

    public function testJsonObjectIsParsed(): void
    {
        $response = $this->post(['Content-Type' => 'application/json'], '{"name":"Demo","minutes":150,"nested":{"a":[1,2]}}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['parsed' => ['name' => 'Demo', 'minutes' => 150, 'nested' => ['a' => [1, 2]]]], TestApp::json($response));
    }

    public function testUtf8CharsetParameterIsAccepted(): void
    {
        $response = $this->post(['Content-Type' => 'application/json; charset=UTF-8'], '{"name":"Zoë"}');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testEmptyBodyIsAllowed(): void
    {
        $response = $this->post(['Content-Type' => 'application/json'], '');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['parsed' => null], TestApp::json($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'syntax error' => ['{"name":'];
        yield 'top-level array' => ['[1,2,3]'];
        yield 'empty array' => ['[]'];
        yield 'scalar' => ['"text"'];
        yield 'invalid utf-8' => ["{\"name\":\"\xB1\x31\"}"];
        yield 'too deep' => [str_repeat('{"a":', 70) . '1' . str_repeat('}', 70)];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodiesAreRefused(string $body): void
    {
        $response = $this->post(['Content-Type' => 'application/json'], $body);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('malformed_json', TestApp::error($response)['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedContentTypes(): iterable
    {
        yield 'form' => ['application/x-www-form-urlencoded'];
        yield 'text' => ['text/plain'];
        yield 'missing' => [''];
        yield 'json latin1' => ['application/json; charset=iso-8859-1'];
    }

    #[DataProvider('unsupportedContentTypes')]
    public function testOtherContentTypesAreRefused(string $contentType): void
    {
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];
        $response = $this->post($headers, '{"a":1}');

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('unsupported_media_type', TestApp::error($response)['code']);
    }

    public function testMultipartIsPassedThroughForUploads(): void
    {
        $response = $this->post(['Content-Type' => 'multipart/form-data; boundary=x'], "--x\r\n--x--\r\n");

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOversizedBodyIsRefused(): void
    {
        $body = '{"a":"' . str_repeat('x', 3000) . '"}';

        $declared = $this->post(['Content-Type' => 'application/json', 'Content-Length' => (string) \strlen($body)], $body);
        self::assertSame(413, $declared->getStatusCode());
        self::assertSame('payload_too_large', TestApp::error($declared)['code']);

        $undeclared = $this->post(['Content-Type' => 'application/json'], $body);
        self::assertSame(413, $undeclared->getStatusCode());
    }

    public function testGetRequestsAreNotParsed(): void
    {
        $response = $this->test->handle('GET', '/api/v3/health', ['Content-Type' => 'text/plain'], 'not json');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(array $headers, string $body): ResponseInterface
    {
        return $this->test->handle('POST', '/api/v3/__test/echo', $headers, $body);
    }
}
