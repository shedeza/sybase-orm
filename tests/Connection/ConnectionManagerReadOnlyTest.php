<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Exception\PersistenceException;

/**
 * Tests for read-only connection protection.
 */
final class ConnectionManagerReadOnlyTest extends TestCase
{
    public function testIsReadOnlyReturnsFalseByDefault(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'testdb']);

        $this->assertFalse($manager->isReadOnly());
    }

    public function testIsReadOnlyReturnsTrueWhenConfigured(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'testdb', 'read_only' => true]);

        $this->assertTrue($manager->isReadOnly());
    }

    public function testExecuteStatementThrowsOnReadOnly(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'reporting', 'read_only' => true]);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('read-only');

        $manager->executeStatement('INSERT INTO users (name) VALUES (?)', ['Alice']);
    }

    public function testBeginTransactionThrowsOnReadOnly(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'reporting', 'read_only' => true]);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('read-only');

        $manager->beginTransaction();
    }

    public function testExecuteQueryWorksOnReadOnly(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'reporting', 'read_only' => true]);
        $manager->getConnection(); // Initialize

        $stmt = $manager->executeQuery('SELECT 1', []);

        $this->assertInstanceOf(\PDOStatement::class, $stmt);
    }

    public function testGetConfigSafeIncludesReadOnly(): void
    {
        $manager = new ReadOnlyTestManager(['dbname' => 'testdb', 'read_only' => true]);

        $config = $manager->getConfigSafe();

        $this->assertTrue($config['read_only']);
    }
}

/**
 * @internal
 */
class ReadOnlyTestManager extends ConnectionManager
{
    protected function createPdo(string $dsn, string $username, string $password, array $options): \PDO
    {
        return new class() extends \PDO {
            public function __construct()
            {
            }

            public function exec(string $statement): int|false
            {
                return 0;
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $stmt = new class() extends \PDOStatement {
                    public function __construct()
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
                    {
                        return true;
                    }
                };

                return $stmt;
            }
        };
    }
}
