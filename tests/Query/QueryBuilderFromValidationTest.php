<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

/**
 * Tests for QueryBuilder from() validation.
 */
final class QueryBuilderFromValidationTest extends TestCase
{
    public function testGetSQLWithoutFromThrowsLogicException(): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $qb->select('id');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('from()');

        $qb->getSQL();
    }

    public function testGetSQLAfterResetWithoutFromThrows(): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $qb->select('id')->from('users', 'u')->getSQL(); // works

        $qb->reset()->select('id');

        $this->expectException(\LogicException::class);
        $qb->getSQL();
    }
}
