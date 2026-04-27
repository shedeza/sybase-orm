<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Exception\TransactionException;

/**
 * Tests for ConnectionManager savepoint support.
 */
final class ConnectionManagerSavepointTest extends TestCase
{
    public function testCreateSavepointWithoutTransactionThrows(): void
    {
        $manager = new SavepointTestableManager(['dbname' => 'testdb']);

        $this->expectException(TransactionException::class);
        $manager->createSavepoint();
    }

    public function testCreateSavepointReturnsName(): void
    {
        $manager = new SavepointTestableManager(['dbname' => 'testdb']);
        $manager->getConnection();
        $manager->beginTransaction();

        $name = $manager->createSavepoint();

        $this->assertStringStartsWith('sp_', $name);
        $this->assertContains('SAVE TRANSACTION ' . $name, $manager->executedStatements);
    }

    public function testRollbackToSavepoint(): void
    {
        $manager = new SavepointTestableManager(['dbname' => 'testdb']);
        $manager->getConnection();
        $manager->beginTransaction();

        $name = $manager->createSavepoint();
        $manager->rollbackToSavepoint($name);

        $this->assertContains('ROLLBACK TRANSACTION ' . $name, $manager->executedStatements);
    }

    public function testIsDeadlockDetectsDeadlockMessage(): void
    {
        $e = new \PDOException('Your server command (process id 42) was chosen as the deadlock victim');

        $this->assertTrue(ConnectionManager::isDeadlock($e));
    }

    public function testIsDeadlockReturnsFalseForNormalError(): void
    {
        $e = new \PDOException('Syntax error near SELECT');

        $this->assertFalse(ConnectionManager::isDeadlock($e));
    }
}

/**
 * @internal
 */
class SavepointTestableManager extends ConnectionManager
{
    /** @var string[] */
    public array $executedStatements = [];

    protected function createPdo(string $dsn, string $username, string $password, array $options): \PDO
    {
        $manager = $this;

        return new class($manager) extends \PDO {
            public function __construct(private readonly SavepointTestableManager $manager)
            {
            }

            public function exec(string $statement): int|false
            {
                $this->manager->executedStatements[] = $statement;
                return 0;
            }

            public function beginTransaction(): bool
            {
                return true;
            }
        };
    }
}
