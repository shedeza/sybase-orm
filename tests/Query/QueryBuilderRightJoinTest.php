<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

/**
 * Tests for QueryBuilder::rightJoin().
 */
final class QueryBuilderRightJoinTest extends TestCase
{
    public function testRightJoin(): void
    {
        $qb = new QueryBuilder(new SybaseDialect());

        $sql = $qb
            ->select('u.id', 'o.total')
            ->from('users', 'u')
            ->rightJoin('orders', 'o', 'o.user_id = u.id')
            ->getSQL();

        $this->assertSame(
            'SELECT u.id, o.total FROM users u RIGHT JOIN orders o ON o.user_id = u.id',
            $sql,
        );
    }
}
