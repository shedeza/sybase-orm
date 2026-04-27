<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;

/**
 * Tests for SybaseDialect::generateSelectTop().
 */
final class SybaseDialectSelectTopTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    public function testGenerateSelectTopSimple(): void
    {
        $sql = $this->dialect->generateSelectTop(10, ['*'], 'users');

        $this->assertSame('SELECT TOP 10 * FROM [users]', $sql);
    }

    public function testGenerateSelectTopWithColumns(): void
    {
        $sql = $this->dialect->generateSelectTop(5, ['id', 'name'], 'users');

        $this->assertSame('SELECT TOP 5 [id], [name] FROM [users]', $sql);
    }

    public function testGenerateSelectTopWithAlias(): void
    {
        $sql = $this->dialect->generateSelectTop(1, ['*'], 'users', 'u');

        $this->assertSame('SELECT TOP 1 * FROM [users] AS [u]', $sql);
    }
}
