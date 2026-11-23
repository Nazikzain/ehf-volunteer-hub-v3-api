<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

/**
 * Connection settings for the runtime database user (API §15.2: least privilege; the
 * migration user is separate and used only from the command line, Phase 3).
 *
 * No value has a default: the database name, user and password are created for V3
 * at deployment and exist only in the server's private configuration.
 */
final readonly class DatabaseSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        #[\SensitiveParameter]
        private string $password,
    ) {}

    public static function fromEnvironment(Environment $env, string $prefix = 'DB_'): ?self
    {
        $host = $env->get($prefix . 'HOST');
        $name = $env->get($prefix . 'NAME');
        $user = $env->get($prefix . 'USER');
        $password = $env->get($prefix . 'PASSWORD');
        $port = $env->get($prefix . 'PORT') ?? '3306';

        if ($host === null && $name === null && $user === null && $password === null) {
            return null; // Database not configured (Phase 2 needs no database).
        }

        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new ConfigurationException($prefix . 'PORT must be a TCP port number.');
        }

        return new self(
            self::required($host, $prefix . 'HOST'),
            (int) $port,
            self::required($name, $prefix . 'NAME'),
            self::required($user, $prefix . 'USER'),
            self::required($password, $prefix . 'PASSWORD'),
        );
    }

    private static function required(?string $value, string $name): string
    {
        return $value ?? throw new ConfigurationException($name . ' must be set when database settings are provided.');
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'name' => $this->name, 'user' => $this->user, 'password' => '[REDACTED]'];
    }
}
