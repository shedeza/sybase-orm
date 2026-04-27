<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\MigrationException;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\SybaseORMException;

/**
 * Tests for exception factory methods.
 */
final class ExceptionFactoryMethodsTest extends TestCase
{
    public function testPersistenceExceptionForEntity(): void
    {
        $ex = PersistenceException::forEntity('App\\Entity\\User', 'insert');

        $this->assertInstanceOf(PersistenceException::class, $ex);
        $this->assertInstanceOf(SybaseORMException::class, $ex);
        $this->assertStringContainsString('App\\Entity\\User', $ex->getMessage());
        $this->assertStringContainsString('insert', $ex->getMessage());
    }

    public function testPersistenceExceptionForEntityWithPrevious(): void
    {
        $prev = new \RuntimeException('DB error');
        $ex = PersistenceException::forEntity('App\\Entity\\User', 'update', $prev);

        $this->assertSame($prev, $ex->getPrevious());
    }

    public function testOqlParseExceptionUnexpectedToken(): void
    {
        $ex = OqlParseException::unexpectedToken('FROM', 'WHERE', 'SELECT u WHERE u.id = 1');

        $this->assertInstanceOf(OqlParseException::class, $ex);
        $this->assertStringContainsString('FROM', $ex->getMessage());
        $this->assertStringContainsString('WHERE', $ex->getMessage());
    }

    public function testConnectionLostExceptionFromPdoException(): void
    {
        $pdo = new \PDOException('Connection reset');
        $ex = ConnectionLostException::fromPdoException($pdo);

        $this->assertInstanceOf(ConnectionLostException::class, $ex);
        $this->assertSame($pdo, $ex->getPrevious());
        $this->assertStringContainsString('Connection reset', $ex->getMessage());
    }

    public function testMigrationExceptionForVersion(): void
    {
        $ex = MigrationException::forVersion('20240101120000', 'table already exists');

        $this->assertInstanceOf(MigrationException::class, $ex);
        $this->assertStringContainsString('20240101120000', $ex->getMessage());
        $this->assertStringContainsString('table already exists', $ex->getMessage());
    }
}
