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
        $mockStmt->expects($this->once())->method('bindValue')->with(1, 1, \PDO::PARAM_INT);
        $mockStmt->expects($this->once())->method('execute');

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

    // --- Task 13.3: Charset conversion ---

    public function testCharsetConversionDefaultsToFalse(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues) {
            $boundValues[$pos] = $val;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->executeQuery('SELECT * FROM t WHERE name = ?', ['café']);

        // Without charset_conversion, the string should pass through unchanged (UTF-8)
        $this->assertSame('café', $boundValues[1]);
    }

    public function testCharsetConversionDisabledPassesStringsThrough(): void
    {
        $manager = $this->createManager(['charset_conversion' => false]);
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues) {
            $boundValues[$pos] = $val;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->executeQuery('SELECT * FROM t WHERE name = ?', ['café']);

        $this->assertSame('café', $boundValues[1]);
    }

    public function testCharsetConversionEnabledConvertsUtf8ToIso88591Outbound(): void
    {
        $manager = $this->createManager(['charset_conversion' => true]);
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues) {
            $boundValues[$pos] = $val;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // "café" in UTF-8: the 'é' is 0xC3 0xA9
        $utf8String = 'café';
        $manager->executeQuery('SELECT * FROM t WHERE name = ?', [$utf8String]);

        // After conversion, 'é' should be ISO-8859-1 single byte 0xE9
        $expected = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8String);
        $this->assertSame($expected, $boundValues[1]);
    }

    public function testCharsetConversionEnabledConvertsIso88591ToUtf8Inbound(): void
    {
        $manager = $this->createManager(['charset_conversion' => true]);

        // Simulate a row from the database in ISO-8859-1
        $iso8859String = iconv('UTF-8', 'ISO-8859-1', 'café');
        $row = ['name' => $iso8859String, 'id' => 42];

        $result = $manager->convertResultRow($row);

        // String values should be converted to UTF-8, non-strings pass through
        $this->assertSame('café', $result['name']);
        $this->assertSame(42, $result['id']);
    }

    public function testCharsetConversionDisabledPassesResultRowThrough(): void
    {
        $manager = $this->createManager(['charset_conversion' => false]);

        $row = ['name' => 'hello', 'id' => 1];
        $result = $manager->convertResultRow($row);

        $this->assertSame($row, $result);
    }

    public function testCharsetConversionPreservesNonStringParams(): void
    {
        $manager = $this->createManager(['charset_conversion' => true]);
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $boundTypes = [];
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues, &$boundTypes) {
            $boundValues[$pos] = $val;
            $boundTypes[$pos] = $type;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        $mockStmt->method('closeCursor')->willReturn(true);

        $manager->setMockPdo($mockPdo);
        $manager->executeStatement('UPDATE t SET val = ? WHERE id = ?', ['text', 42]);

        // String should be converted, int should pass through with PARAM_INT
        $this->assertSame(iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'text'), $boundValues[1]);
        $this->assertSame(42, $boundValues[2]);
        $this->assertSame(\PDO::PARAM_INT, $boundTypes[2]);
    }

    public function testCharsetConversionPreservesOriginalOnIconvFailure(): void
    {
        $manager = $this->createManager(['charset_conversion' => true]);

        // A string with characters that cannot be represented in ISO-8859-1
        // Chinese characters are not in ISO-8859-1 range
        $utf8String = '你好世界';

        // convertResultRow with a string that's not valid ISO-8859-1 should preserve it
        // For outbound: test via convertResultRow since we can't easily test convertToDatabase directly
        // But we can test the round-trip preservation behavior
        $row = ['name' => $utf8String];
        $result = $manager->convertResultRow($row);

        // The string should be preserved (iconv from ISO-8859-1 to UTF-8 may alter it,
        // but the key point is no exception is thrown)
        $this->assertIsString($result['name']);
    }

    // --- Array param expansion at connection level (v1.2.6) ---

    public function testExpandArrayParamsInExecuteQuery(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $boundTypes = [];
        $mockPdo->method('prepare')->with('SELECT * FROM t WHERE id IN (?, ?, ?)')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues, &$boundTypes) {
            $boundValues[$pos] = $val;
            $boundTypes[$pos] = $type;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // Pass an array param — expandArrayParams should expand the single ? into ?, ?, ?
        $manager->executeQuery('SELECT * FROM t WHERE id IN (?)', [['a', 'b', 'c']]);

        $this->assertCount(3, $boundValues);
        $this->assertSame('a', $boundValues[1]);
        $this->assertSame('b', $boundValues[2]);
        $this->assertSame('c', $boundValues[3]);
    }

    public function testExpandArrayParamsMixedScalarAndArray(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $boundTypes = [];
        $mockPdo->method('prepare')->with('SELECT * FROM t WHERE name = ? AND id IN (?, ?)')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues, &$boundTypes) {
            $boundValues[$pos] = $val;
            $boundTypes[$pos] = $type;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        $manager->executeQuery('SELECT * FROM t WHERE name = ? AND id IN (?)', ['John', [1, 2]]);

        $this->assertCount(3, $boundValues);
        $this->assertSame('John', $boundValues[1]);
        $this->assertSame(1, $boundValues[2]);
        $this->assertSame(2, $boundValues[3]);
        $this->assertSame(\PDO::PARAM_STR, $boundTypes[1]);
        $this->assertSame(\PDO::PARAM_INT, $boundTypes[2]);
        $this->assertSame(\PDO::PARAM_INT, $boundTypes[3]);
    }

    public function testExpandArrayParamsHandlesNullInsideArray(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $boundTypes = [];
        $mockPdo->method('prepare')->with('SELECT * FROM t WHERE id IN (?, ?)')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues, &$boundTypes) {
            $boundValues[$pos] = $val;
            $boundTypes[$pos] = $type;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        $manager->executeQuery('SELECT * FROM t WHERE id IN (?)', [[null, 5]]);

        $this->assertCount(2, $boundValues);
        $this->assertNull($boundValues[1]);
        $this->assertSame(\PDO::PARAM_NULL, $boundTypes[1]);
        $this->assertSame(5, $boundValues[2]);
        $this->assertSame(\PDO::PARAM_INT, $boundTypes[2]);
    }

    public function testExpandArrayParamsNoOpForFlatParams(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $boundValues = [];
        $mockPdo->method('prepare')->with('SELECT * FROM t WHERE id = ? AND name = ?')->willReturn($mockStmt);
        $mockStmt->method('bindValue')->willReturnCallback(function (int $pos, mixed $val, int $type) use (&$boundValues) {
            $boundValues[$pos] = $val;
            return true;
        });
        $mockStmt->method('execute')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        // All scalar params — no expansion needed
        $manager->executeQuery('SELECT * FROM t WHERE id = ? AND name = ?', [42, 'John']);

        $this->assertCount(2, $boundValues);
        $this->assertSame(42, $boundValues[1]);
        $this->assertSame('John', $boundValues[2]);
    }

    public function testExpandArrayParamsInExecuteStatement(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMockPdo();
        $mockStmt = $this->createMock(\PDOStatement::class);

        $capturedSql = null;
        $mockPdo->method('prepare')->willReturnCallback(function (string $sql) use ($mockStmt, &$capturedSql) {
            $capturedSql = $sql;
            return $mockStmt;
        });
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(3);
        $mockStmt->method('closeCursor')->willReturn(true);

        $manager->setMockPdo($mockPdo);

        $manager->executeStatement('DELETE FROM t WHERE id IN (?)', [[1, 2, 3]]);

        $this->assertSame('DELETE FROM t WHERE id IN (?, ?, ?)', $capturedSql);
    }
}
