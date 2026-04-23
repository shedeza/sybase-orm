<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

/**
 * Property-based tests for QueryBuilder.
 *
 * Property 6: QueryBuilder HAVING Clause Inclusion
 * Validates: Requirements 11.1, 11.2
 *
 * For any non-empty HAVING condition string passed to QueryBuilder::having(),
 * the SQL produced by getSQL() SHALL contain a HAVING clause with that condition,
 * positioned after the GROUP BY clause (if present) and before the ORDER BY clause (if present).
 */
final class QueryBuilderPropertyTest extends TestCase
{
    /**
     * @dataProvider havingConditionProvider
     *
     * **Validates: Requirements 11.1, 11.2**
     */
    public function testHavingClauseIncludedInSql(string $condition): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $sql = $qb
            ->select('role', 'COUNT(*)')
            ->from('users')
            ->groupBy('role')
            ->having($condition)
            ->getSQL();

        $this->assertStringContainsString('HAVING ' . $condition, $sql);
    }

    /**
     * @dataProvider havingConditionProvider
     *
     * **Validates: Requirements 11.1, 11.2**
     */
    public function testHavingAppearsAfterGroupByAndBeforeOrderBy(string $condition): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $sql = $qb
            ->select('role', 'COUNT(*)')
            ->from('users')
            ->groupBy('role')
            ->having($condition)
            ->orderBy('role')
            ->getSQL();

        $groupByPos = strpos($sql, 'GROUP BY');
        $havingPos = strpos($sql, 'HAVING');
        $orderByPos = strpos($sql, 'ORDER BY');

        $this->assertNotFalse($groupByPos, 'SQL must contain GROUP BY');
        $this->assertNotFalse($havingPos, 'SQL must contain HAVING');
        $this->assertNotFalse($orderByPos, 'SQL must contain ORDER BY');

        $this->assertGreaterThan($groupByPos, $havingPos, 'HAVING must appear after GROUP BY');
        $this->assertGreaterThan($havingPos, $orderByPos, 'ORDER BY must appear after HAVING');
    }

    /**
     * @dataProvider havingConditionProvider
     *
     * **Validates: Requirements 11.1, 11.2**
     */
    public function testHavingWithoutGroupByStillPresent(string $condition): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $sql = $qb
            ->select('COUNT(*)')
            ->from('users')
            ->having($condition)
            ->getSQL();

        $this->assertStringContainsString('HAVING ' . $condition, $sql);
    }

    /**
     * @dataProvider havingConditionWithOrderByProvider
     *
     * **Validates: Requirements 11.1, 11.2**
     */
    public function testHavingWithoutGroupByAppearsBeforeOrderBy(string $condition): void
    {
        $qb = new QueryBuilder(new SybaseDialect());
        $sql = $qb
            ->select('COUNT(*)')
            ->from('users')
            ->having($condition)
            ->orderBy('COUNT(*)', 'DESC')
            ->getSQL();

        $havingPos = strpos($sql, 'HAVING');
        $orderByPos = strpos($sql, 'ORDER BY');

        $this->assertNotFalse($havingPos, 'SQL must contain HAVING');
        $this->assertNotFalse($orderByPos, 'SQL must contain ORDER BY');
        $this->assertGreaterThan($havingPos, $orderByPos, 'ORDER BY must appear after HAVING');
    }

    /**
     * Generates 100+ random non-empty condition strings for property testing.
     *
     * @return \Generator<string, array{string}>
     */
    public static function havingConditionProvider(): \Generator
    {
        $aggregates = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
        $operators = ['>', '<', '>=', '<=', '=', '!='];
        $columns = ['amount', 'total', 'qty', 'price', 'score', 'cnt', 'val', 'num'];

        for ($i = 0; $i < 110; $i++) {
            $func = $aggregates[array_rand($aggregates)];
            $col = $columns[array_rand($columns)];
            $op = $operators[array_rand($operators)];
            $value = rand(1, 1000);

            $condition = "{$func}({$col}) {$op} {$value}";
            yield "condition_{$i}: {$condition}" => [$condition];
        }
    }

    /**
     * Generates 100+ random non-empty condition strings for ORDER BY combination tests.
     *
     * @return \Generator<string, array{string}>
     */
    public static function havingConditionWithOrderByProvider(): \Generator
    {
        $aggregates = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
        $operators = ['>', '<', '>=', '<=', '=', '!='];
        $columns = ['amount', 'total', 'qty', 'price', 'score'];

        for ($i = 0; $i < 110; $i++) {
            $func = $aggregates[array_rand($aggregates)];
            $col = $columns[array_rand($columns)];
            $op = $operators[array_rand($operators)];
            $value = rand(1, 1000);

            $condition = "{$func}({$col}) {$op} {$value}";
            yield "orderby_condition_{$i}: {$condition}" => [$condition];
        }
    }
}
