<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

/**
 * Tests for QueryBuilder distinct() and addGroupBy() methods.
 */
final class QueryBuilderDistinctTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder(new SybaseDialect());
    }

    public function testDistinctSelect(): void
    {
        $sql = $this->qb
            ->select('e.name')
            ->distinct()
            ->from('users', 'e')
            ->getSQL();

        $this->assertSame('SELECT DISTINCT e.name FROM users e', $sql);
    }

    public function testDistinctCanBeDisabled(): void
    {
        $sql = $this->qb
            ->select('e.name')
            ->distinct()
            ->distinct(false)
            ->from('users', 'e')
            ->getSQL();

        $this->assertSame('SELECT e.name FROM users e', $sql);
    }

    public function testDistinctResetOnReset(): void
    {
        $this->qb->select('e.name')->distinct()->from('users', 'e')->getSQL();

        $sql = $this->qb->reset()
            ->select('e.id')
            ->from('users', 'e')
            ->getSQL();

        $this->assertSame('SELECT e.id FROM users e', $sql);
    }

    public function testAddGroupBy(): void
    {
        $sql = $this->qb
            ->select('e.department', 'e.role', 'COUNT(*)')
            ->from('users', 'e')
            ->groupBy('e.department')
            ->addGroupBy('e.role')
            ->getSQL();

        $this->assertSame('SELECT e.department, e.role, COUNT(*) FROM users e GROUP BY e.department, e.role', $sql);
    }

    public function testAddGroupByWithoutInitialGroupBy(): void
    {
        $sql = $this->qb
            ->select('e.department', 'COUNT(*)')
            ->from('users', 'e')
            ->addGroupBy('e.department')
            ->getSQL();

        $this->assertSame('SELECT e.department, COUNT(*) FROM users e GROUP BY e.department', $sql);
    }
}
