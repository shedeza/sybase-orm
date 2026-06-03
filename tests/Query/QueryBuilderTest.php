<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Query\QueryBuilder;

final class QueryBuilderTest extends TestCase
{
    private QueryBuilder $qb;
    private DialectInterface $dialect;

    protected function setUp(): void
    {
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit, ?int $offset) => $sql . " TOP {$limit}" . ($offset !== null ? " OFFSET {$offset}" : ''));

        $this->qb = new QueryBuilder($this->dialect);
    }

    public function testBasicSelect(): void
    {
        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->getSQL();

        $this->assertSame('SELECT id, name FROM users', $sql);
    }

    public function testSelectAllByDefault(): void
    {
        $sql = $this->qb
            ->from('users')
            ->getSQL();

        $this->assertSame('SELECT * FROM users', $sql);
    }

    public function testFromWithAlias(): void
    {
        $sql = $this->qb
            ->select('u.id')
            ->from('users', 'u')
            ->getSQL();

        $this->assertSame('SELECT u.id FROM users u', $sql);
    }

    public function testWhereClause(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = :active', [':active' => 1])
            ->getSQL();

        $this->assertSame('SELECT * FROM users WHERE active = :active', $sql);
        $this->assertSame([':active' => 1], $this->qb->getParameters());
    }

    public function testAndWhere(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = 1')
            ->andWhere('role = :role', [':role' => 'admin'])
            ->getSQL();

        $this->assertSame('SELECT * FROM users WHERE active = 1 AND role = :role', $sql);
    }

    public function testOrWhere(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = 1')
            ->orWhere('role = :role', [':role' => 'admin'])
            ->getSQL();

        $this->assertSame('SELECT * FROM users WHERE active = 1 OR role = :role', $sql);
    }

    public function testJoin(): void
    {
        $sql = $this->qb
            ->select('u.id', 'o.total')
            ->from('users', 'u')
            ->join('orders', 'o', 'o.user_id = u.id')
            ->getSQL();

        $this->assertSame('SELECT u.id, o.total FROM users u JOIN orders o ON o.user_id = u.id', $sql);
    }

    public function testLeftJoin(): void
    {
        $sql = $this->qb
            ->select('u.id', 'o.total')
            ->from('users', 'u')
            ->leftJoin('orders', 'o', 'o.user_id = u.id')
            ->getSQL();

        $this->assertSame('SELECT u.id, o.total FROM users u LEFT JOIN orders o ON o.user_id = u.id', $sql);
    }

    public function testOrderBy(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->orderBy('name', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->getSQL();

        $this->assertSame('SELECT * FROM users ORDER BY name ASC, created_at DESC', $sql);
    }

    public function testGroupByAndHaving(): void
    {
        $sql = $this->qb
            ->select('role', 'COUNT(*) as total')
            ->from('users')
            ->groupBy('role')
            ->having('COUNT(*) > :min', [':min' => 5])
            ->getSQL();

        $this->assertSame('SELECT role, COUNT(*) as total FROM users GROUP BY role HAVING COUNT(*) > :min', $sql);
        $this->assertArrayHasKey(':min', $this->qb->getParameters());
    }

    public function testDistinct(): void
    {
        $sql = $this->qb
            ->select('email')
            ->distinct()
            ->from('users')
            ->getSQL();

        $this->assertSame('SELECT DISTINCT email FROM users', $sql);
    }

    public function testLimitCallsDialect(): void
    {
        $sql = $this->qb
            ->select('*')
            ->from('users')
            ->limit(10)
            ->offset(20)
            ->getSQL();

        $this->assertStringContainsString('TOP 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testResetClearsState(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('active = 1')
            ->reset();

        $this->assertSame([], $this->qb->getSelectColumns());
        $this->assertNull($this->qb->getFrom());
        $this->assertSame([], $this->qb->getParameters());
    }

    public function testGetSQLWithoutFromThrowsException(): void
    {
        $this->expectException(\LogicException::class);

        $this->qb->select('id')->getSQL();
    }

    public function testSetParameter(): void
    {
        $this->qb
            ->select('*')
            ->from('users')
            ->where('id = :id')
            ->setParameter(':id', 42);

        $this->assertSame(42, $this->qb->getParameters()[':id']);
    }
}
