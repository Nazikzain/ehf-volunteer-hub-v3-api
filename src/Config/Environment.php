<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

use Dotenv\Dotenv;

/**
 * Read-only view of the process environment.
 *
 * Secrets live only in the server's private `.env` file (outside the web root) or in real
 * environment variables — never in Git, never in the frontend (API §15.1–15.2).
 * Real environment variables take precedence over `.env`.
 */
final readonly class Environment
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values) {}

    /**
     * Loads `<projectRoot>/.env` if it exists, then reads the process environment.
     */
    public static function load(string $projectRoot): self
    {
        if (is_file($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
        }

        return self::fromGlobals();
    }

    public static function fromGlobals(): self
    {
        $values = [];
        foreach ([getenv(), $_SERVER, $_ENV] as $source) {
            foreach ($source as $name => $value) {
                if (\is_string($name) && \is_string($value) && !\array_key_exists($name, $values)) {
                    $values[$name] = $value;
                }
            }
        }

        return new self($values);
    }

    public function get(string $name): ?string
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
