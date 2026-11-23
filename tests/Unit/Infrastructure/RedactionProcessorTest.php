<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Infrastructure;

use Ehf\Api\Infrastructure\Logging\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactionProcessorTest extends TestCase
{
    public function testSecretKeysAreRedactedAtAnyDepth(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'message',
            context: [
                'password' => 'p1',
                'new_password' => 'p2',
                'DB_PASSWORD' => 'p3',
                'csrf_token' => 't1',
                'Authorization' => 'Bearer x',
                'headers' => ['Cookie' => 'c', 'Accept' => 'application/json'],
                'api_key' => 'k',
                'request_id' => '01HF4DKSE0ABCDEFGHJKMNPQRS',
                'count' => 3,
            ],
            extra: ['session_id' => 's'],
        );

        $clean = (new RedactionProcessor())($record);

        $r = RedactionProcessor::REDACTED;
        self::assertSame([
            'password' => $r,
            'new_password' => $r,
            'DB_PASSWORD' => $r,
            'csrf_token' => $r,
            'Authorization' => $r,
            'headers' => ['Cookie' => $r, 'Accept' => 'application/json'],
            'api_key' => $r,
            'request_id' => '01HF4DKSE0ABCDEFGHJKMNPQRS',
            'count' => 3,
        ], $clean->context);
        self::assertSame(['session_id' => $r], $clean->extra);
    }
}
