<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;

final class SybaseDialectCountExistsTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    // ── generateCount() ─────────────────────────────────────────────

    public function testGenerateCountWithoutWhere(): void
    {
        $sql = $this->dialect->generateCount('users');

        $this->assertSame('SELECT COUNT(*) FROM [users]', $sql);
    }

    public function testGenerateCountWithWhereClause(): void
    {
        $sql = $this->dialect->generateCount('users', '[active] = 1');

        $this->assertSame('SELECT COUNT(*) FROM [users] WHERE [active] = 1', $sql);
    }

    public function testGenerateCountWithNullWhereClause(): void
    {
        $sql = $this->dialect->generateCount('orders', null);

        $this->assertSame('SELECT COUNT(*) FROM [orders]', $sql);
    }

    public function testGenerateCountWithSchemaQualifiedTable(): void
    {
        $sql = $this->dialect->generateCount('billing.invoices');

        $this->assertSame('SELECT COUNT(*) FROM [billing].[invoices]', $sql);
    }

    public function testGenerateCountWithComplexWhereClause(): void
    {
        $sql = $this->dialect->generateCount('users', '[active] = 1 AND [role] = ?');

        $this->assertSame('SELECT COUNT(*) FROM [users] WHERE [active] = 1 AND [role] = ?', $sql);
    }

    // ── generateExists() ────────────────────────────────────────────

    public function testGenerateExistsBasic(): void
    {
        $sql = $this->dialect->generateExists('users', '[id] = ?');

        $this->assertSame(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM [users] WHERE [id] = ?) THEN 1 ELSE 0 END',
            $sql,
        );
    }

    public function testGenerateExistsWithComplexWhere(): void
    {
        $sql = $this->dialect->generateExists('users', '[name] = ? AND [active] = 1');

        $this->assertSame(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM [users] WHERE [name] = ? AND [active] = 1) THEN 1 ELSE 0 END',
            $sql,
        );
    }

    public function testGenerateExistsWithSchemaQualifiedTable(): void
    {
        $sql = $this->dialect->generateExists('billing.invoices', '[status] = ?');

        $this->assertSame(
            'SELECT CASE WHEN EXISTS (SELECT 1 FROM [billing].[invoices] WHERE [status] = ?) THEN 1 ELSE 0 END',
            $sql,
        );
    }
}
