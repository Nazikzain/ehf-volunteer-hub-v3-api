<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Database;

/**
 * Lazily opened connection with transaction and query helpers. Repositories use it; nothing else
 * builds SQL.
 *
 * Rows are returned as arrays of scalars; read them through Row for typed access.
 */
final class Database
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    private ?\PDO $pdo = null;

    public function __construct(private readonly ConnectionFactory $factory) {}

    public function pdo(): \PDO
    {
        return $this->pdo ??= $this->factory->create();
    }

    /**
     * Runs $work in one transaction (or inside the current one). Any exception rolls back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction()) {
            return $work();
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @phpstan-impure
     */
    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /**
     * @param list<scalar|null> $params
     */
    public function value(string $sql, array $params = []): string|int|null
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return int affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return int the new row's id
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    public static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }
}
