<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\ConnectionLostException;

/**
 * Tests for prepared statement caching in ConnectionManager.
 */
final class StmtCacheTest extends TestCase
{
    private function createManager(array $configOverrides = []): TestableConnectionManager
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

        return new TestableConnectionManager($config);
    }

    private function createMockPdo(): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    public function testExecuteQueryCachesPreparedStatement(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        // prepare() should be called only ONCE for the same SQL
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE id = ?')
            ->willReturn($mockStmt);

        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('bindValue')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // First call — prepares and caches
        $manager->executeQuery('SELECT * FROM users WHERE id = ?', [1]);

        // Second call — reuses cached statement
        $manager->executeQuery('SELECT * FROM users WHERE id = ?', [2]);
    }

    public function testExecuteStatementCachesPreparedStatement(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        // prepare() should be called only ONCE for the same SQL
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('UPDATE users SET name = ? WHERE id = ?')
            ->willReturn($mockStmt);

        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        $mockStmt->method('closeCursor')->willReturn(true);
        $mockStmt->method('bindValue')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        $manager->executeStatement('UPDATE users SET name = ? WHERE id = ?', ['Alice', 1]);
        $manager->executeStatement('UPDATE users SET name = ? WHERE id = ?', ['Bob', 2]);
    }

    public function testDifferentSqlGetsDifferentCachedStatements(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt1 = $this->createMock(\PDOStatement::class);
        $mockStmt2 = $this->createMock(\PDOStatement::class);

        // prepare() should be called TWICE — once per distinct SQL
        $mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($mockStmt1, $mockStmt2) {
                if (str_contains($sql, 'users')) {
                    return $mockStmt1;
                }
                return $mockStmt2;
            });

        $mockStmt1->method('execute')->willReturn(true);
        $mockStmt1->method('bindValue')->willReturn(true);
        $mockStmt2->method('execute')->willReturn(true);
        $mockStmt2->method('bindValue')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        $result1 = $manager->executeQuery('SELECT * FROM users WHERE id = ?', [1]);
        $result2 = $manager->executeQuery('SELECT * FROM orders WHERE id = ?', [1]);

        $this->assertSame($mockStmt1, $result1);
        $this->assertSame($mockStmt2, $result2);
    }

    public function testStmtCacheClearedOnConnectionLost(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('exec')->willReturn(0);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('bindValue')->willReturn(true);

        $callCount = 0;
        $mockPdo->method('prepare')
            ->willReturnCallback(function (string $sql) use ($mockStmt, &$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \PDOException('server has gone away');
                }
                return $mockStmt;
            });

        $manager->setMockPdo($mockPdo);

        // First call — caches the statement
        $manager->executeQuery('SELECT 1', []);

        // Second call with different SQL — triggers connection lost
        try {
            $manager->executeQuery('SELECT 2', []);
        } catch (ConnectionLostException) {
            // expected
        }

        // After connection lost, the cache should be cleared.
        // Re-set mock PDO (simulating reconnection) and verify prepare is called again
        $newMockPdo = $this->createMock(\PDO::class);
        $newMockPdo->method('exec')->willReturn(0);
        $newStmt = $this->createMock(\PDOStatement::class);
        $newStmt->method('execute')->willReturn(true);
        $newStmt->method('bindValue')->willReturn(true);
        $newMockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT 1')
            ->willReturn($newStmt);

        $manager->setMockPdo($newMockPdo);

        // This should call prepare() again since cache was cleared
        $manager->executeQuery('SELECT 1', []);
    }

    public function testCachedStatementRecesNewBindParams(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStmt);

        $boundValues = [];
        $mockStmt->method('bindValue')->willReturnCallback(
            function (int $pos, mixed $val, int $type) use (&$boundValues) {
                $boundValues[] = ['pos' => $pos, 'val' => $val, 'type' => $type];
                return true;
            },
        );
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // First call
        $manager->executeQuery('SELECT * FROM t WHERE id = ?', [1]);

        $this->assertCount(1, $boundValues);
        $this->assertSame(1, $boundValues[0]['val']);

        // Reset tracking
        $boundValues = [];

        // Second call with different params — should rebind
        $manager->executeQuery('SELECT * FROM t WHERE id = ?', [42]);

        $this->assertCount(1, $boundValues);
        $this->assertSame(42, $boundValues[0]['val']);
    }

    public function testCacheWorksWithExpandedArrayParams(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        // After expansion, SQL becomes "SELECT * FROM t WHERE id IN (?, ?, ?)"
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM t WHERE id IN (?, ?, ?)')
            ->willReturn($mockStmt);

        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('bindValue')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // First call with array param
        $manager->executeQuery('SELECT * FROM t WHERE id IN (?)', [[1, 2, 3]]);

        // Second call with same expanded SQL — should reuse cached statement
        $manager->executeQuery('SELECT * FROM t WHERE id IN (?, ?, ?)', [4, 5, 6]);
    }
}
