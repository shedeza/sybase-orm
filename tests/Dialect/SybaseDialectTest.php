<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Dialect\SybaseDialect;

final class SybaseDialectTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    // ── Interface compliance ────────────────────────────────────────

    public function testImplementsDialectInterface(): void
    {
        $this->assertInstanceOf(DialectInterface::class, $this->dialect);
    }

    // ── applyPagination: TOP (offset=0 or null) ────────────────────

    public function testPaginationWithNullOffsetUsesTop(): void
    {
        $sql = 'SELECT [id], [name] FROM [users]';
        $result = $this->dialect->applyPagination($sql, 10);

        $this->assertSame('SELECT TOP 10 [id], [name] FROM [users]', $result);
    }

    public function testPaginationWithZeroOffsetUsesTop(): void
    {
        $sql = 'SELECT [id], [name] FROM [users]';
        $result = $this->dialect->applyPagination($sql, 5, 0);

        $this->assertSame('SELECT TOP 5 [id], [name] FROM [users]', $result);
    }

    // ── applyPagination: ROW_NUMBER() (offset > 0) ─────────────────

    public function testPaginationWithPositiveOffsetUsesRowNumber(): void
    {
        $sql = 'SELECT [id], [name] FROM [users] ORDER BY [id]';
        $result = $this->dialect->applyPagination($sql, 10, 20);

        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('ORDER BY [id]', $result);
        $this->assertStringContainsString('BETWEEN 21 AND 30', $result);
    }

    public function testPaginationRowNumberWithoutOrderByUsesFallback(): void
    {
        $sql = 'SELECT [id], [name] FROM [users]';
        $result = $this->dialect->applyPagination($sql, 10, 5);

        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('ORDER BY (SELECT 1)', $result);
        $this->assertStringContainsString('BETWEEN 6 AND 15', $result);
    }

    public function testPaginationRowNumberWrapsOriginalQuery(): void
    {
        $sql = 'SELECT [id], [email] FROM [accounts] ORDER BY [email]';
        $result = $this->dialect->applyPagination($sql, 25, 50);

        // The original ORDER BY should be moved into ROW_NUMBER()
        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY [email])', $result);
        $this->assertStringContainsString('BETWEEN 51 AND 75', $result);
        // The outer query should reference __paged
        $this->assertStringContainsString('__paged', $result);
    }

    // ── generateInsert ──────────────────────────────────────────────

    public function testGenerateInsertBasic(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['name', 'email'],
            ['?', '?']
        );

        $this->assertSame('INSERT INTO [users] ([name], [email]) VALUES (?, ?)', $sql);
    }

    public function testGenerateInsertOmitsIdentityColumn(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['id', 'name', 'email'],
            ['?', '?', '?'],
            'id'
        );

        $this->assertSame('INSERT INTO [users] ([name], [email]) VALUES (?, ?)', $sql);
        $this->assertStringNotContainsString('[id]', $sql);
    }

    public function testGenerateInsertWithoutIdentityKeepsAllColumns(): void
    {
        $sql = $this->dialect->generateInsert(
            'settings',
            ['key', 'value'],
            [':key', ':value']
        );

        $this->assertSame('INSERT INTO [settings] ([key], [value]) VALUES (:key, :value)', $sql);
    }

    public function testGenerateInsertIdentityColumnNullDoesNotFilter(): void
    {
        $sql = $this->dialect->generateInsert(
            'logs',
            ['id', 'message'],
            ['?', '?'],
            null
        );

        $this->assertSame('INSERT INTO [logs] ([id], [message]) VALUES (?, ?)', $sql);
    }

    // ── getLastInsertIdSQL ──────────────────────────────────────────

    public function testGetLastInsertIdSQLReturnsAtAtIdentity(): void
    {
        $this->assertSame('SELECT @@identity', $this->dialect->getLastInsertIdSQL());
    }

    // ── quoteIdentifier ─────────────────────────────────────────────

    public function testQuoteIdentifierWrapsBrackets(): void
    {
        $this->assertSame('[users]', $this->dialect->quoteIdentifier('users'));
    }

    public function testQuoteIdentifierDoesNotDoubleQuote(): void
    {
        $this->assertSame('[users]', $this->dialect->quoteIdentifier('[users]'));
    }

    public function testQuoteIdentifierEscapesBracketsInName(): void
    {
        $this->assertSame('[my]]table]', $this->dialect->quoteIdentifier('my]table'));
    }

    public function testQuoteIdentifierHandlesSchemaQualifiedName(): void
    {
        $this->assertSame('[billing].[invoices]', $this->dialect->quoteIdentifier('billing.invoices'));
    }

    public function testQuoteIdentifierHandlesDboSchema(): void
    {
        $this->assertSame('[dbo].[users]', $this->dialect->quoteIdentifier('dbo.users'));
    }

    // ── generateNullComparison ──────────────────────────────────────

    public function testGenerateNullComparisonIsNull(): void
    {
        $this->assertSame('[deleted_at] IS NULL', $this->dialect->generateNullComparison('deleted_at', true));
    }

    public function testGenerateNullComparisonIsNotNull(): void
    {
        $this->assertSame('[status] IS NOT NULL', $this->dialect->generateNullComparison('status', false));
    }

    // ── generateUpdate ──────────────────────────────────────────────

    public function testGenerateUpdate(): void
    {
        $sql = $this->dialect->generateUpdate('users', ['name', 'email'], '[id] = ?');

        $this->assertSame('UPDATE [users] SET [name] = ?, [email] = ? WHERE [id] = ?', $sql);
    }

    // ── generateDelete ──────────────────────────────────────────────

    public function testGenerateDelete(): void
    {
        $sql = $this->dialect->generateDelete('users', '[id] = ?');

        $this->assertSame('DELETE FROM [users] WHERE [id] = ?', $sql);
    }

    // ── generateSelect ──────────────────────────────────────────────

    public function testGenerateSelectWithoutAlias(): void
    {
        $sql = $this->dialect->generateSelect(['id', 'name'], 'users');

        $this->assertSame('SELECT [id], [name] FROM [users]', $sql);
    }

    public function testGenerateSelectWithAlias(): void
    {
        $sql = $this->dialect->generateSelect(['id', 'name'], 'users', 'u');

        $this->assertSame('SELECT [id], [name] FROM [users] AS [u]', $sql);
    }

    // ── applyPagination: ORDER BY inside subquery ───────────────────

    public function testPaginationDoesNotMatchOrderByInsideSubquery(): void
    {
        $sql = 'SELECT [id], [name] FROM [users] WHERE [id] IN (SELECT [id] FROM [scores] ORDER BY [score] DESC)';
        $result = $this->dialect->applyPagination($sql, 10, 5);

        // The ORDER BY inside the subquery should NOT be extracted
        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('ORDER BY (SELECT 1)', $result);
        // The subquery ORDER BY should remain in the inner query
        $this->assertStringContainsString('ORDER BY [score] DESC', $result);
    }

    public function testPaginationExtractsTopLevelOrderByNotSubquery(): void
    {
        $sql = 'SELECT [id], [name] FROM [users] WHERE [id] IN (SELECT [id] FROM [scores] ORDER BY [score]) ORDER BY [name]';
        $result = $this->dialect->applyPagination($sql, 10, 20);

        // Should extract the top-level ORDER BY [name], not the subquery one
        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY [name])', $result);
        $this->assertStringContainsString('BETWEEN 21 AND 30', $result);
    }

    public function testPaginationHandlesNestedParentheses(): void
    {
        $sql = 'SELECT [id] FROM [users] WHERE [id] IN (SELECT [id] FROM (SELECT [id] FROM [scores] ORDER BY [score]) AS sub) ORDER BY [id] ASC';
        $result = $this->dialect->applyPagination($sql, 5, 10);

        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY [id] ASC)', $result);
        $this->assertStringContainsString('BETWEEN 11 AND 15', $result);
    }

    public function testPaginationWithMultipleTopLevelOrderByUsesLast(): void
    {
        // This is unusual SQL but tests that the parser picks the LAST top-level ORDER BY
        $sql = 'SELECT [id], [name] FROM [users] ORDER BY [id]';
        $result = $this->dialect->applyPagination($sql, 10, 0);

        // offset=0 uses TOP, not ROW_NUMBER
        $this->assertSame('SELECT TOP 10 [id], [name] FROM [users] ORDER BY [id]', $result);
    }

    public function testPaginationWithOrderByContainingMultipleColumns(): void
    {
        $sql = 'SELECT [id], [name] FROM [users] ORDER BY [name] ASC, [id] DESC';
        $result = $this->dialect->applyPagination($sql, 10, 20);

        $this->assertStringContainsString('ROW_NUMBER() OVER (ORDER BY [name] ASC, [id] DESC)', $result);
        $this->assertStringContainsString('BETWEEN 21 AND 30', $result);
    }
}
