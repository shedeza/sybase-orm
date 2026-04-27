<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManager;

/**
 * Tests for ConnectionManager::reconnect().
 */
final class ConnectionManagerReconnectTest extends TestCase
{
    public function testReconnectClearsInternalState(): void
    {
        // Use a testable subclass that tracks connection creation
        $manager = new ReconnectTestableManager(['dbname' => 'testdb']);

        // First connection
        $manager->getConnection();
        $this->assertSame(1, $manager->connectCount);

        // Second call reuses existing connection
        $manager->getConnection();
        $this->assertSame(1, $manager->connectCount);

        // Reconnect forces new connection on next call
        $manager->reconnect();
        $manager->getConnection();
        $this->assertSame(2, $manager->connectCount);
    }

    public function testReconnectClearsTransactionState(): void
    {
        $manager = new ReconnectTestableManager(['dbname' => 'testdb']);
        $manager->getConnection();

        // Simulate being in a transaction
        $manager->beginTransaction();
        $this->assertTrue($manager->isInTransaction());

        // Reconnect should clear transaction state
        $manager->reconnect();
        $this->assertFalse($manager->isInTransaction());
    }
}

/**
 * @internal
 */
class ReconnectTestableManager extends ConnectionManager
{
    public int $connectCount = 0;

    protected function createPdo(string $dsn, string $username, string $password, array $options): \PDO
    {
        $this->connectCount++;

        $pdo = new class() extends \PDO {
            public function __construct()
            {
                // Skip parent constructor — no real DB connection
            }

            public function exec(string $statement): int|false
            {
                return 0;
            }

            public function beginTransaction(): bool
            {
                return true;
            }
        };

        return $pdo;
    }
}
