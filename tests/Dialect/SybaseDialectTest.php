<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;

/**
 * @covers \SybaseORM\Dialect\SybaseDialect
 */
final class SybaseDialectTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    public function testApplyPaginationWithTopOnly(): void
    {
        $sql = 'SELECT * FROM users';
        $paged = $this->dialect->applyPagination($sql, 10);
        $this->assertSame('SELECT TOP 10 * FROM users', $paged);
    }

    public function testApplyPaginationWithOffsetUsesRowNumber(): void
    {
        $sql = 'SELECT id, name FROM users ORDER BY id ASC';
        $paged = $this->dialect->applyPagination($sql, 10, 20);
        
        $expected = 'SELECT * FROM (SELECT ROW_NUMBER() OVER (ORDER BY id ASC) AS [__row_number], __inner.* FROM (SELECT id, name FROM users) AS __inner) AS __paged WHERE [__row_number] BETWEEN 21 AND 30';
        $this->assertSame($expected, $paged);
    }

    public function testGenerateInsert(): void
    {
        $sql = $this->dialect->generateInsert('users', ['name', 'status'], ['?', '?']);
        $this->assertSame('INSERT INTO [users] ([name], [status]) VALUES (?, ?)', $sql);
    }

    public function testQuoteIdentifier(): void
    {
        $this->assertSame('[user].[name]', $this->dialect->quoteIdentifier('user.name'));
    }
}
