<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

final class QueryBuilderImmutabilityTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $dialect = new SybaseDialect();
        $this->qb = new QueryBuilder($dialect);
    }

    public function testGetSingleResultPreservesLimitState(): void
    {
        $this->qb->select('id', 'name')->from('users')->limit(50);

        $executedSql = null;
        $this->qb->setExecutor(function (string $sql, array $params, string $mode) use (&$executedSql) {
            $executedSql = $sql;
            return [['id' => 1, 'name' => 'Alice']];
        });

        $result = $this->qb->getSingleResult();

        $this->assertSame(['id' => 1, 'name' => 'Alice'], $result);
        $this->assertIsString($executedSql);
        $this->assertStringContainsString('TOP 1', $executedSql);

        // Verify limitValue is restored to 50
        $this->assertSame(50, $this->qb->getLimit());
    }

    public function testGetSingleScalarResultPreservesLimitState(): void
    {
        $this->qb->select('name')->from('users')->limit(25);

        $this->qb->setExecutor(function (string $sql, array $params, string $mode) {
            return ['Alice'];
        });

        $result = $this->qb->getSingleScalarResult();

        $this->assertSame('Alice', $result);
        $this->assertSame(25, $this->qb->getLimit());
    }

    public function testGetOneOrNullResultPreservesLimitState(): void
    {
        $this->qb->select('id')->from('users')->limit(100);

        $this->qb->setExecutor(function (string $sql, array $params, string $mode) {
            return [['id' => 1]];
        });

        $result = $this->qb->getOneOrNullResult();

        $this->assertSame(['id' => 1], $result);
        $this->assertSame(100, $this->qb->getLimit());
    }
}
