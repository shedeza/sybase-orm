<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;

/**
 * Unit tests for SybaseDialect value expressions in generateInsert/generateUpdate.
 * Validates: Requirement 34.4
 */
final class ValueExpressionsTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    // ── generateInsert with value expressions ───────────────────────

    public function testGenerateInsertWithoutValueExpressions(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['name', 'email'],
            ['?', '?'],
        );

        $this->assertSame('INSERT INTO [users] ([name], [email]) VALUES (?, ?)', $sql);
    }

    public function testGenerateInsertWithValueExpressions(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['name', 'score'],
            ['?', '?'],
            null,
            [null, 'CONVERT(REAL, ?)'],
        );

        $this->assertSame('INSERT INTO [users] ([name], [score]) VALUES (?, CONVERT(REAL, ?))', $sql);
    }

    public function testGenerateInsertWithIdentityAndValueExpressions(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['id', 'name', 'score'],
            ['?', '?', '?'],
            'id',
            [null, null, 'CONVERT(REAL, ?)'],
        );

        // id column should be filtered out
        $this->assertSame('INSERT INTO [users] ([name], [score]) VALUES (?, CONVERT(REAL, ?))', $sql);
    }

    public function testGenerateInsertWithAllNullExpressions(): void
    {
        $sql = $this->dialect->generateInsert(
            'users',
            ['name', 'email'],
            ['?', '?'],
            null,
            [null, null],
        );

        $this->assertSame('INSERT INTO [users] ([name], [email]) VALUES (?, ?)', $sql);
    }

    // ── generateUpdate with value expressions ───────────────────────

    public function testGenerateUpdateWithoutValueExpressions(): void
    {
        $sql = $this->dialect->generateUpdate(
            'users',
            ['name', 'email'],
            '[id] = ?',
        );

        $this->assertSame('UPDATE [users] SET [name] = ?, [email] = ? WHERE [id] = ?', $sql);
    }

    public function testGenerateUpdateWithValueExpressions(): void
    {
        $sql = $this->dialect->generateUpdate(
            'users',
            ['name', 'score'],
            '[id] = ?',
            [null, 'CONVERT(REAL, ?)'],
        );

        $this->assertSame('UPDATE [users] SET [name] = ?, [score] = CONVERT(REAL, ?) WHERE [id] = ?', $sql);
    }

    public function testGenerateUpdateWithAllWrapped(): void
    {
        $sql = $this->dialect->generateUpdate(
            'users',
            ['score1', 'score2'],
            '[id] = ?',
            ['CONVERT(REAL, ?)', 'CONVERT(INT, ?)'],
        );

        $this->assertSame('UPDATE [users] SET [score1] = CONVERT(REAL, ?), [score2] = CONVERT(INT, ?) WHERE [id] = ?', $sql);
    }
}
