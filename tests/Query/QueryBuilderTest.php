<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;
use SybaseORM\Query\QueryBuilderInterface;

final class QueryBuilderTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder(new SybaseDialect());
    }

    // ── Interface compliance ────────────────────────────────────────

    public function testImplementsQueryBuilderInterface(): void
    {
        $this->assertInstanceOf(QueryBuilderInterface::class, $this->qb);
    }

    // ── Fluent chaining ─────────────────────────────────────────────

    public function testAllMethodsReturnSameInstance(): void
    {
        $same = $this->qb
            ->select('id', 'name')
            ->from('users', 'u')
            ->where('u.id = ?', [1])
            ->andWhere('u.active = ?', [true])
            ->orWhere('u.role = ?', ['admin'])
            ->join('orders', 'o', 'o.user_id = u.id')
            ->leftJoin('profiles', 'p', 'p.user_id = u.id')
            ->orderBy('u.name')
            ->groupBy('u.role')
            ->limit(10)
            ->offset(20)
            ->with('posts');

        $this->assertSame($this->qb, $same);
    }

    // ── SELECT ──────────────────────────────────────────────────────

    public function testSelectWithSpecificColumns(): void
    {
        $sql = $this->qb->select('id', 'name')->from('users')->getSQL();

        $this->assertSame('SELECT id, name FROM users', $sql);
    }

    public function testSelectDefaultsToStar(): void
    {
        $sql = $this->qb->from('users')->getSQL();

        $this->assertSame('SELECT * FROM users', $sql);
    }

    // ── FROM ────────────────────────────────────────────────────────

    public function testFromWithAlias(): void
    {
        $sql = $this->qb->select('u.id')->from('users', 'u')->getSQL();

        $this->assertSame('SELECT u.id FROM users u', $sql);
    }

    public function testFromWithoutAlias(): void
    {
        $sql = $this->qb->select('id')->from('users')->getSQL();

        $this->assertSame('SELECT id FROM users', $sql);
    }

    // ── WHERE / andWhere / orWhere ──────────────────────────────────

    public function testWhereWithParameters(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->where('id = ?', [42])
            ->getSQL();

        $this->assertSame('SELECT id FROM users WHERE id = ?', $sql);
        $this->assertSame([42], $this->qb->getParameters());
    }

    public function testAndWhereAppendsWithAnd(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->where('active = ?', [1])
            ->andWhere('role = ?', ['admin'])
            ->getSQL();

        $this->assertSame('SELECT id FROM users WHERE active = ? AND role = ?', $sql);
        $this->assertSame([1, 'admin'], $this->qb->getParameters());
    }

    public function testOrWhereAppendsWithOr(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->where('role = ?', ['admin'])
            ->orWhere('role = ?', ['superadmin'])
            ->getSQL();

        $this->assertSame('SELECT id FROM users WHERE role = ? OR role = ?', $sql);
        $this->assertSame(['admin', 'superadmin'], $this->qb->getParameters());
    }

    public function testMixedAndOrWhere(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = ?', [1])
            ->andWhere('age > ?', [18])
            ->orWhere('role = ?', ['admin'])
            ->getSQL();

        $this->assertSame(
            'SELECT * FROM users WHERE active = ? AND age > ? OR role = ?',
            $sql
        );
        $this->assertSame([1, 18, 'admin'], $this->qb->getParameters());
    }

    public function testWhereReplacesExistingConditions(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('old = ?', [1])
            ->andWhere('extra = ?', [2]);

        // Calling where() again replaces everything
        $sql = $this->qb->where('new = ?', [99])->getSQL();

        $this->assertSame('SELECT id FROM users WHERE new = ?', $sql);
        $this->assertSame([99], $this->qb->getParameters());
    }

    public function testNoWhereClauseWhenNoneSet(): void
    {
        $sql = $this->qb->select('id')->from('users')->getSQL();

        $this->assertStringNotContainsString('WHERE', $sql);
        $this->assertSame([], $this->qb->getParameters());
    }

    // ── JOIN / LEFT JOIN ────────────────────────────────────────────

    public function testJoin(): void
    {
        $sql = $this->qb
            ->select('u.id', 'o.total')
            ->from('users', 'u')
            ->join('orders', 'o', 'o.user_id = u.id')
            ->getSQL();

        $this->assertSame(
            'SELECT u.id, o.total FROM users u JOIN orders o ON o.user_id = u.id',
            $sql
        );
    }

    public function testLeftJoin(): void
    {
        $sql = $this->qb
            ->select('u.id', 'p.bio')
            ->from('users', 'u')
            ->leftJoin('profiles', 'p', 'p.user_id = u.id')
            ->getSQL();

        $this->assertSame(
            'SELECT u.id, p.bio FROM users u LEFT JOIN profiles p ON p.user_id = u.id',
            $sql
        );
    }

    public function testMultipleJoins(): void
    {
        $sql = $this->qb
            ->select('u.id')
            ->from('users', 'u')
            ->join('orders', 'o', 'o.user_id = u.id')
            ->leftJoin('profiles', 'p', 'p.user_id = u.id')
            ->getSQL();

        $this->assertStringContainsString('JOIN orders o ON o.user_id = u.id', $sql);
        $this->assertStringContainsString('LEFT JOIN profiles p ON p.user_id = u.id', $sql);
    }

    // ── ORDER BY ────────────────────────────────────────────────────

    public function testOrderByDefaultAsc(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->orderBy('name')
            ->getSQL();

        $this->assertSame('SELECT id FROM users ORDER BY name ASC', $sql);
    }

    public function testOrderByDesc(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->orderBy('created_at', 'DESC')
            ->getSQL();

        $this->assertSame('SELECT id FROM users ORDER BY created_at DESC', $sql);
    }

    public function testMultipleOrderBy(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->orderBy('last_name')
            ->orderBy('first_name', 'DESC')
            ->getSQL();

        $this->assertSame(
            'SELECT id FROM users ORDER BY last_name ASC, first_name DESC',
            $sql
        );
    }

    // ── GROUP BY ────────────────────────────────────────────────────

    public function testGroupBy(): void
    {
        $sql = $this->qb
            ->select('role', 'COUNT(*)')
            ->from('users')
            ->groupBy('role')
            ->getSQL();

        $this->assertSame('SELECT role, COUNT(*) FROM users GROUP BY role', $sql);
    }

    public function testGroupByMultipleColumns(): void
    {
        $sql = $this->qb
            ->select('dept', 'role', 'COUNT(*)')
            ->from('users')
            ->groupBy('dept', 'role')
            ->getSQL();

        $this->assertSame(
            'SELECT dept, role, COUNT(*) FROM users GROUP BY dept, role',
            $sql
        );
    }

    // ── Pagination (limit / offset via dialect) ─────────────────────

    public function testLimitWithoutOffsetUsesTop(): void
    {
        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->limit(10)
            ->getSQL();

        $this->assertStringContainsString('TOP 10', $sql);
    }

    public function testLimitWithZeroOffsetUsesTop(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->limit(5)
            ->offset(0)
            ->getSQL();

        $this->assertStringContainsString('TOP 5', $sql);
    }

    public function testLimitWithPositiveOffsetUsesRowNumber(): void
    {
        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->orderBy('id')
            ->limit(10)
            ->offset(20)
            ->getSQL();

        $this->assertStringContainsString('ROW_NUMBER()', $sql);
        $this->assertStringContainsString('BETWEEN 21 AND 30', $sql);
    }

    public function testPaginationWithoutOrderByFallsBackToSelect1(): void
    {
        $sql = $this->qb
            ->select('id')
            ->from('users')
            ->limit(10)
            ->offset(5)
            ->getSQL();

        $this->assertStringContainsString('ROW_NUMBER()', $sql);
        $this->assertStringContainsString('ORDER BY (SELECT 1)', $sql);
    }

    public function testNoLimitProducesNoPagination(): void
    {
        $sql = $this->qb->select('id')->from('users')->getSQL();

        $this->assertStringNotContainsString('TOP', $sql);
        $this->assertStringNotContainsString('ROW_NUMBER', $sql);
    }

    // ── Eager loading (with) ────────────────────────────────────────

    public function testWithGeneratesLeftJoin(): void
    {
        $sql = $this->qb
            ->select('u.*')
            ->from('users', 'u')
            ->with('posts')
            ->getSQL();

        $this->assertStringContainsString('LEFT JOIN posts', $sql);
    }

    public function testWithMultipleRelations(): void
    {
        $sql = $this->qb
            ->select('u.*')
            ->from('users', 'u')
            ->with('posts', 'comments')
            ->getSQL();

        $this->assertStringContainsString('LEFT JOIN posts', $sql);
        $this->assertStringContainsString('LEFT JOIN comments', $sql);
    }

    // ── Full query composition ──────────────────────────────────────

    public function testComplexQueryComposition(): void
    {
        $sql = $this->qb
            ->select('u.id', 'u.name', 'o.total')
            ->from('users', 'u')
            ->join('orders', 'o', 'o.user_id = u.id')
            ->where('u.active = ?', [1])
            ->andWhere('o.total > ?', [100])
            ->groupBy('u.id', 'u.name', 'o.total')
            ->orderBy('o.total', 'DESC')
            ->getSQL();

        $this->assertSame(
            'SELECT u.id, u.name, o.total FROM users u'
            . ' JOIN orders o ON o.user_id = u.id'
            . ' WHERE u.active = ? AND o.total > ?'
            . ' GROUP BY u.id, u.name, o.total'
            . ' ORDER BY o.total DESC',
            $sql
        );
        $this->assertSame([1, 100], $this->qb->getParameters());
    }

    public function testComplexQueryWithPagination(): void
    {
        $sql = $this->qb
            ->select('u.id', 'u.name')
            ->from('users', 'u')
            ->where('u.active = ?', [1])
            ->orderBy('u.name')
            ->limit(25)
            ->offset(50)
            ->getSQL();

        $this->assertStringContainsString('ROW_NUMBER()', $sql);
        $this->assertStringContainsString('BETWEEN 51 AND 75', $sql);
        $this->assertSame([1], $this->qb->getParameters());
    }

    // ── Parameter accumulation ──────────────────────────────────────

    public function testParametersAccumulateAcrossWhereClauses(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('a = ?', [1])
            ->andWhere('b = ?', [2])
            ->orWhere('c = ?', [3]);

        $this->assertSame([1, 2, 3], $this->qb->getParameters());
    }

    public function testGetParametersReturnsEmptyArrayByDefault(): void
    {
        $this->assertSame([], $this->qb->getParameters());
    }

    public function testNamedParameters(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('name = :name', [':name' => 'Alice'])
            ->andWhere('role = :role', [':role' => 'admin']);

        $this->assertSame(
            [':name' => 'Alice', ':role' => 'admin'],
            $this->qb->getParameters()
        );
    }
}
