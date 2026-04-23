<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\TransactionException;

class ConnectionManagerTest extends TestCase
{
    private function createManager(array $configOverrides = [], ?\PDO $mockPdo = null): TestableConnectionManager
    {
        $config = array_merge([
            'host' => 'sybase-host',
            'port' => 5000,
            'dbname' => 'testdb',
            'username' => 'sa',
            'password' => 'secret',
            'charset' => 'UTF-8',
            'persistent' => false,
        ], $configOverrides);

        $manager = new TestableConnectionManager($config);

        if ($mockPdo !== null) {
            $manager->setMockPdo($mockPdo);
        }

        return $manager;
    }

    private function createMockPdo(): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    // --- Task 6.1: DSN building and SET commands ---

    public function testGetConnectionBuildsDsnCorrectly(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);

        $mockPdo->expects($this->exactly(2))
            ->method('exec')
            ->willReturnCallback(function (string $sql) {
                static $callIndex = 0;
                $expected = ['SET ANSINULL ON', 'SET QUOTED_IDENTIFIER ON'];
                $this->assertSame($expected[$callIndex], $sql);
                $callIndex++;
                return 0;
            });

        $manager->setMockPdo($mockPdo);
        $connection = $manager->getConnection();

        $this->assertSame($mockPdo, $connection);
        $this->assertSame('dblib:host=sybase-host:5000;dbname=testdb;charset=UTF-8', $manager->getLastDsn());
    }

    public function testGetConnectionExecutesSetCommands(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);

        $execCalls = [];
        $mockPdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });

        $manager->setMockPdo($mockPdo);
        $manager->getConnection();

        $this->assertSame(['SET ANSINULL ON', 'SET QUOTED_IDENTIFIER ON'], $execCalls);
    }

    public function testGetConnectionReturnsSameInstanceOnSubsequentCalls(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $first = $manager->getConnection();
        $second = $manager->getConnection();

        $this->assertSame($first, $second);
    }

    public function testGetConnectionWithDefaultConfig(): void
    {
        $manager = new TestableConnectionManager(['dbname' => 'testdb']);
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $manager->getConnection();

        $this->assertSame('dblib:host=localhost:5000;dbname=testdb;charset=UTF-8', $manager->getLastDsn());
    }

    public function testThrowsOnEmptyDbname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dbname/');

        new TestableConnectionManager([]);
    }

    public function testThrowsOnInvalidPortZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        new TestableConnectionManager(['dbname' => 'test', 'port' => 0]);
    }

    public function testThrowsOnInvalidPortTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        new TestableConnectionManager(['dbname' => 'test', 'port' => 70000]);
    }

    public function testAcceptsValidPort(): void
    {
        $manager = $this->createManager(['port' => 4100]);
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $manager->getConnection();

        $this->assertStringContainsString(':4100', $manager->getLastDsn());
    }

    // --- Task 6.1: executeQuery and executeStatement ---

    public function testExecuteQueryPreparesAndExecutes(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $mockPdo->method('prepare')->with('SELECT * FROM users WHERE id = ?')->willReturn($mockStmt);
        $mockStmt->expects($this->once())->method('execute')->with([1]);

        $manager->setMockPdo($mockPdo);
        $result = $manager->executeQuery('SELECT * FROM users WHERE id = ?', [1]);

        $this->assertSame($mockStmt, $result);
    }

    public function testExecuteStatementReturnsRowCountAndClosesCursor(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(3);
        $mockStmt->expects($this->once())->method('closeCursor');

        $manager->setMockPdo($mockPdo);
        $result = $manager->executeStatement('UPDATE users SET name = ? WHERE id = ?', ['John', 1]);

        $this->assertSame(3, $result);
    }

    // --- Task 6.2: Persistent connections ---

    public function testPersistentConnectionSetsAttribute(): void
    {
        $manager = $this->createManager(['persistent' => true]);
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $manager->getConnection();

        $options = $manager->getLastOptions();
        $this->assertArrayHasKey(\PDO::ATTR_PERSISTENT, $options);
        $this->assertTrue($options[\PDO::ATTR_PERSISTENT]);
    }

    public function testNonPersistentConnectionDoesNotSetPersistentAttribute(): void
    {
        $manager = $this->createManager(['persistent' => false]);
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $manager->getConnection();

        $options = $manager->getLastOptions();
        $this->assertArrayNotHasKey(\PDO::ATTR_PERSISTENT, $options);
    }

    // --- Task 6.2: ConnectionLostException ---

    public function testGetConnectionThrowsConnectionLostOnPdoException(): void
    {
        $manager = $this->createManager();
        $manager->setMockPdo(null);
        $manager->setThrowOnCreate(new \PDOException('Connection refused'));

        $this->expectException(ConnectionLostException::class);
        $this->expectExceptionMessageMatches('/Failed to connect.*sybase-host.*5000/');

        $manager->getConnection();
    }

    public function testExecuteQueryThrowsConnectionLostOnLostConnection(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $exception = new \PDOException('server has gone away');
        $ref = new \ReflectionProperty(\PDOException::class, 'code');
        $ref->setValue($exception, '08S01');
        $mockPdo->method('prepare')->willThrowException($exception);

        $manager->setMockPdo($mockPdo);

        $this->expectException(ConnectionLostException::class);
        $manager->executeQuery('SELECT 1');
    }

    public function testExecuteStatementThrowsConnectionLostOnError(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockPdo->method('prepare')->willThrowException(new \PDOException('broken pipe'));

        $manager->setMockPdo($mockPdo);

        $this->expectException(ConnectionLostException::class);
        $manager->executeStatement('DELETE FROM users');
    }

    // --- Task 6.3: Transaction management ---

    public function testBeginTransactionStartsTransaction(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockPdo->expects($this->once())->method('beginTransaction')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->beginTransaction();

        $this->assertTrue($manager->isInTransaction());
    }

    public function testCommitSucceeds(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockPdo->method('beginTransaction')->willReturn(true);
        $mockPdo->expects($this->once())->method('commit')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->beginTransaction();
        $manager->commit();

        $this->assertFalse($manager->isInTransaction());
    }

    public function testCommitWithoutTransactionThrowsTransactionException(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot commit: no active transaction.');

        $manager->commit();
    }

    public function testRollbackSucceeds(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockPdo->method('beginTransaction')->willReturn(true);
        $mockPdo->expects($this->once())->method('rollBack')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->beginTransaction();
        $manager->rollback();

        $this->assertFalse($manager->isInTransaction());
    }

    public function testRollbackWithoutTransactionThrowsTransactionException(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot rollback: no active transaction.');

        $manager->rollback();
    }

    // --- Task 6.3: Isolation levels ---

    public function testSetTransactionIsolationExecutesSql(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);

        $execCalls = [];
        $mockPdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });

        $manager->setMockPdo($mockPdo);
        $manager->getConnection(); // triggers SET commands
        $manager->setTransactionIsolation('READ COMMITTED');

        $this->assertContains('SET TRANSACTION ISOLATION LEVEL READ COMMITTED', $execCalls);
    }

    /**
     * @dataProvider validIsolationLevelsProvider
     */
    public function testSetTransactionIsolationAcceptsValidLevels(string $level): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('exec')->willReturn(0);
        $manager->setMockPdo($mockPdo);

        // Should not throw
        $manager->setTransactionIsolation($level);
        $this->assertTrue(true);
    }

    public static function validIsolationLevelsProvider(): array
    {
        return [
            'READ UNCOMMITTED' => ['READ UNCOMMITTED'],
            'READ COMMITTED' => ['READ COMMITTED'],
            'REPEATABLE READ' => ['REPEATABLE READ'],
            'SERIALIZABLE' => ['SERIALIZABLE'],
            'lowercase' => ['read committed'],
            'mixed case' => ['Read Committed'],
        ];
    }

    public function testSetTransactionIsolationRejectsInvalidLevel(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $manager->setMockPdo($mockPdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid isolation level/');

        $manager->setTransactionIsolation('SNAPSHOT');
    }

    // --- Connection lost resets state ---

    public function testConnectionLostResetsTransactionState(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('exec')->willReturn(0);
        $mockPdo->method('beginTransaction')->willReturn(true);
        $commitException = new \PDOException('server has gone away');
        $ref = new \ReflectionProperty(\PDOException::class, 'code');
        $ref->setValue($commitException, '08S01');
        $mockPdo->method('commit')->willThrowException($commitException);

        $manager->setMockPdo($mockPdo);
        $manager->beginTransaction();

        try {
            $manager->commit();
        } catch (ConnectionLostException) {
            // expected
        }

        $this->assertFalse($manager->isInTransaction());
    }
}
