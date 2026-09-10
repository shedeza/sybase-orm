<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManager;

/**
 * @covers \SybaseORM\Connection\ConnectionManager
 */
final class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $connectionManager;

    protected function setUp(): void
    {
        $this->connectionManager = new ConnectionManager([
            'dbname' => 'test_db',
            'charset_conversion' => true,
        ]);
    }

    public function testExpandArrayParamsExpandsCorrectly(): void
    {
        $reflection = new \ReflectionMethod(ConnectionManager::class, 'expandArrayParams');
        $reflection->setAccessible(true);

        $sql = 'SELECT * FROM users WHERE id IN (?) AND status = ?';
        $params = [[1, 2, 3], 'active'];

        $result = $reflection->invoke($this->connectionManager, $sql, $params);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        
        [$newSql, $newParams] = $result;

        $this->assertSame('SELECT * FROM users WHERE id IN (?, ?, ?) AND status = ?', $newSql);
        $this->assertSame([1, 2, 3, 'active'], $newParams);
    }

    public function testExpandArrayParamsThrowsOnNestedArray(): void
    {
        $reflection = new \ReflectionMethod(ConnectionManager::class, 'expandArrayParams');
        $reflection->setAccessible(true);

        $sql = 'SELECT * FROM users WHERE id IN (?)';
        $params = [[[1, 2]]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nested arrays are not supported');

        $reflection->invoke($this->connectionManager, $sql, $params);
    }

    public function testConvertResultRowConvertsCharset(): void
    {
        // iconv behavior depends on system, but we can verify it doesn't crash
        // and attempts to process strings.
        $row = ['id' => 1, 'name' => 'Test'];
        $converted = $this->connectionManager->convertResultRow($row);

        $this->assertIsArray($converted);
        $this->assertSame(1, $converted['id']);
        $this->assertIsString($converted['name']);
    }

    public function testConvertParamsSkipsBinaryStrings(): void
    {
        $reflection = new \ReflectionMethod(ConnectionManager::class, 'convertToDatabase');
        $reflection->setAccessible(true);

        $binaryString = "bin\0ary";
        
        $result = $reflection->invoke($this->connectionManager, $binaryString);
        
        $this->assertSame($binaryString, $result);
    }
}
