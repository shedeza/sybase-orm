<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use SybaseORM\Connection\ConnectionManager;

/**
 * Testable subclass that overrides PDO creation to allow mocking.
 */
class TestableConnectionManager extends ConnectionManager
{
    private ?\PDO $mockPdo = null;
    private ?\PDOException $throwOnCreate = null;
    private ?string $lastDsn = null;
    private ?array $lastOptions = null;

    public function setMockPdo(?\PDO $pdo): void
    {
        $this->mockPdo = $pdo;
    }

    public function setThrowOnCreate(?\PDOException $exception): void
    {
        $this->throwOnCreate = $exception;
    }

    public function getLastDsn(): ?string
    {
        return $this->lastDsn;
    }

    public function getLastOptions(): ?array
    {
        return $this->lastOptions;
    }

    protected function createPdo(string $dsn, string $username, string $password, array $options): \PDO
    {
        $this->lastDsn = $dsn;
        $this->lastOptions = $options;

        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        if ($this->mockPdo !== null) {
            return $this->mockPdo;
        }

        throw new \PDOException('No mock PDO configured for test');
    }
}
