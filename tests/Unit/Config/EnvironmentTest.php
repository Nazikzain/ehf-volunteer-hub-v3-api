<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Config;

use Ehf\Api\Config\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ehf-env-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
        unset($_ENV['EHF_TEST_ONLY_A'], $_SERVER['EHF_TEST_ONLY_A'], $_ENV['EHF_TEST_ONLY_B'], $_SERVER['EHF_TEST_ONLY_B']);
        putenv('EHF_TEST_ONLY_B');
    }

    public function testDotEnvIsLoadedAndRealEnvironmentWins(): void
    {
        file_put_contents($this->dir . '/.env', "EHF_TEST_ONLY_A=from-file\nEHF_TEST_ONLY_B=from-file\n");
        putenv('EHF_TEST_ONLY_B=from-process');

        $env = Environment::load($this->dir);

        self::assertSame('from-file', $env->get('EHF_TEST_ONLY_A'));
        self::assertSame('from-process', $env->get('EHF_TEST_ONLY_B'));
    }

    public function testMissingDotEnvIsNotAnError(): void
    {
        self::assertNull(Environment::load($this->dir)->get('EHF_TEST_ONLY_A'));
    }

    public function testBlankValuesAreTreatedAsUnset(): void
    {
        $env = new Environment(['A' => '   ', 'B' => ' value ']);

        self::assertNull($env->get('A'));
        self::assertSame('value', $env->get('B'));
        self::assertNull($env->get('C'));
    }
}
