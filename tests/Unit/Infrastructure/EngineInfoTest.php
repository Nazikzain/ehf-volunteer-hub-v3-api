<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Infrastructure;

use Ehf\Api\Infrastructure\Database\EngineInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineInfoTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function versions(): iterable
    {
        yield 'mysql 8.0.16 minimum' => ['8.0.16', EngineInfo::MYSQL, '8.0.16', true];
        yield 'mysql 8.0.15 too old' => ['8.0.15', EngineInfo::MYSQL, '8.0.15', false];
        yield 'mysql 8.4' => ['8.4.3', EngineInfo::MYSQL, '8.4.3', true];
        yield 'mysql 5.7' => ['5.7.44-log', EngineInfo::MYSQL, '5.7.44', false];
        yield 'mariadb 10.6' => ['10.6.20-MariaDB-ubu2004', EngineInfo::MARIADB, '10.6.20', true];
        yield 'mariadb 10.5 too old' => ['10.5.27-MariaDB', EngineInfo::MARIADB, '10.5.27', false];
        yield 'mariadb 11.4' => ['11.4.4-MariaDB-log', EngineInfo::MARIADB, '11.4.4', true];
        yield 'mariadb compatibility prefix' => ['5.5.5-10.6.20-MariaDB', EngineInfo::MARIADB, '10.6.20', true];
    }

    #[DataProvider('versions')]
    public function testDetection(string $raw, string $engine, string $version, bool $supported): void
    {
        $info = EngineInfo::fromVersionString($raw);

        self::assertSame($engine, $info->engine);
        self::assertSame($version, $info->version);
        self::assertSame($supported, $info->meetsMinimum());
    }

    public function testUnrecognisedVersionIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        EngineInfo::fromVersionString('unknown');
    }
}
