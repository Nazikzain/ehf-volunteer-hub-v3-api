<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Infrastructure;

use Ehf\Api\Infrastructure\Id\UlidGenerator;
use PHPUnit\Framework\TestCase;

final class UlidGeneratorTest extends TestCase
{
    public function testFormat(): void
    {
        $id = (new UlidGenerator())->generate();

        self::assertSame(26, \strlen($id));
        self::assertTrue(UlidGenerator::isValid($id));
    }

    public function testTimestampIsEncodedFirstAndSortable(): void
    {
        $early = (new UlidGenerator(static fn(): int => 1_700_000_000_000))->generate();
        $late = (new UlidGenerator(static fn(): int => 1_700_000_000_001))->generate();

        self::assertSame('01HF7YAT00', substr($early, 0, 10));
        self::assertLessThan(0, strcmp(substr($early, 0, 10), substr($late, 0, 10)));
    }

    public function testZeroAndMaximumTimestamps(): void
    {
        self::assertStringStartsWith('0000000000', (new UlidGenerator(static fn(): int => 0))->generate());
        self::assertStringStartsWith('7ZZZZZZZZZ', (new UlidGenerator(static fn(): int => 0xFFFFFFFFFFFF))->generate());
    }

    public function testOutOfRangeTimestampIsRefused(): void
    {
        $this->expectException(\RangeException::class);

        (new UlidGenerator(static fn(): int => 0xFFFFFFFFFFFF + 1))->generate();
    }

    public function testIdsAreUnique(): void
    {
        $generator = new UlidGenerator();
        $ids = [];
        for ($i = 0; $i < 2000; ++$i) {
            $ids[] = $generator->generate();
        }

        self::assertCount(2000, array_unique($ids));
    }

    public function testValidationRejectsOtherStrings(): void
    {
        self::assertFalse(UlidGenerator::isValid('01HF4DKSE0ABCDEFGHJKMNPQRI')); // I is not Crockford
        self::assertFalse(UlidGenerator::isValid('81HF4DKSE0ABCDEFGHJKMNPQRS')); // overflows 48 bits
        self::assertFalse(UlidGenerator::isValid('short'));
    }
}
