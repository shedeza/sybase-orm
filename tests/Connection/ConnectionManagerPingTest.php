<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;

final class ConnectionManagerPingTest extends TestCase
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

    // ── ping() ──────────────────────────────────────────────────────

    public function testPingReturnsTrueWhenConnectionAlive(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);

        $mockPdo->method('exec')->willReturn(0);

        $manager->setMockPdo($mockPdo);

        $this->assertTrue($manager->ping());
    }

    public function testPingReturnsFalseWhenConnectionFails(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);

        $callCount = 0;
        $mockPdo->method('exec')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            // First two calls are SET ANSINULL ON and SET QUOTED_IDENTIFIER ON (from getConnection)
            if ($callCount <= 2) {
                return 0;
            }
            // Third call is SELECT 1 from ping() — simulate failure
            throw new \PDOException('server has gone away');
        });

        $manager->setMockPdo($mockPdo);

        $this->assertFalse($manager->ping());
    }

    public function testPingReturnsFalseWhenNoConnection(): void
    {
        $manager = $this->createManager();
        $manager->setThrowOnCreate(new \PDOException('Connection refused'));

        $this->assertFalse($manager->ping());
    }

    // ── getServerVersion() ──────────────────────────────────────────

    public function testGetServerVersionReturnsVersionString(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('exec')->willReturn(0);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->with(\PDO::FETCH_NUM)->willReturn(['Adaptive Server Enterprise/16.0']);
        $mockStmt->expects($this->once())->method('closeCursor');

        $mockPdo->method('prepare')->with('SELECT @@version')->willReturn($mockStmt);

        $manager->setMockPdo($mockPdo);

        $this->assertSame('Adaptive Server Enterprise/16.0', $manager->getServerVersion());
    }

    public function testGetServerVersionReturnsUnknownOnEmptyResult(): void
    {
        $manager = $this->createManager();
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('exec')->willReturn(0);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->with(\PDO::FETCH_NUM)->willReturn(false);
        $mockStmt->expects($this->once())->method('closeCursor');

        $mockPdo->method('prepare')->with('SELECT @@version')->willReturn($mockStmt);

        $manager->setMockPdo($mockPdo);

        $this->assertSame('unknown', $manager->getServerVersion());
    }
}
