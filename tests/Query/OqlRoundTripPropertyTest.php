<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlPrinter;

/**
 * Property-based test for OQL Parse–Print–Parse Round-Trip.
 *
 * **Validates: Requirements 5.5, 6.7, 7.10, 8.6, 9.6**
 *
 * For any valid OQL SelectStatement AST — including those containing IS NULL, IS NOT NULL,
 * IN, NOT IN, aggregate functions (COUNT, SUM, AVG, MIN, MAX), DISTINCT, HAVING clauses,
 * entity-based JOIN ... WITH conditions, SELECT * wildcards, and column aliases — printing
 * the AST to OQL text and then parsing that text SHALL produce an AST equivalent to the original.
 */
final class OqlRoundTripPropertyTest extends TestCase
{
    private OqlParser $parser;
    private OqlPrinter $printer;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();
        $this->printer = new OqlPrinter();
    }

    /**
     * @dataProvider randomOqlStringsProvider
     */
    public function testParsePrintParseRoundTrip(string $oql, string $description): void
    {
        // Parse the OQL string into an AST
        $ast1 = $this->parser->parse($oql);

        // Print the AST back to OQL text
        $printed = $this->printer->print($ast1);

        // Parse the printed OQL text into a second AST
        $ast2 = $this->parser->parse($printed);

        // The two ASTs must be structurally equivalent
        $this->assertEquals($ast1, $ast2, sprintf(
            "Parse–Print–Parse round-trip failed for: %s\nOriginal OQL: %s\nPrinted OQL:  %s",
            $description,
            $oql,
            $printed,
        ));
    }

    /**
     * Generates 120+ random OQL strings covering all new syntax elements:
     * IS NULL, IS NOT NULL, IN, NOT IN, aggregate functions, DISTINCT,
     * HAVING, entity-based JOIN WITH, SELECT *, and column aliases.
     *
     * @return \Generator<string, array{string, string}>
     */
    public static function randomOqlStringsProvider(): \Generator
    {
        $entities = ['User', 'Order', 'Product', 'Category', 'Invoice', 'Department'];
        $aliases = ['u', 'o', 'p', 'c', 'i', 'd'];
        $properties = ['name', 'email', 'status', 'amount', 'price', 'title', 'code', 'active'];
        $aggregates = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
        $comparisonOps = ['=', '!=', '<', '>', '<=', '>='];
        $directions = ['ASC', 'DESC'];

        $pick = static fn(array $arr): string => $arr[mt_rand(0, count($arr) - 1)];
        $pickIdx = static fn(int $max): int => mt_rand(0, $max);

        // Helper: generate a random property access string
        $randProp = static fn(string $alias) => $alias . '.' . $pick($properties);

        // Helper: generate a random comparison condition
        $randComparison = static function (string $alias) use ($pick, $comparisonOps, $randProp): string {
            $left = $randProp($alias);
            $op = $pick($comparisonOps);
            // Right side: parameter or integer literal
            $right = mt_rand(0, 1) === 0 ? ':param' . mt_rand(1, 99) : (string) mt_rand(1, 1000);

            return $left . ' ' . $op . ' ' . $right;
        };

        // Helper: generate a random WHERE condition (single or compound)
        $randCondition = static function (string $alias) use ($randComparison, $randProp): string {
            $type = mt_rand(0, 5);

            return match (true) {
                $type === 0 => $randProp($alias) . ' IS NULL',
                $type === 1 => $randProp($alias) . ' IS NOT NULL',
                $type === 2 => $randProp($alias) . ' IN (:param' . mt_rand(1, 99) . ')',
                $type === 3 => $randProp($alias) . ' NOT IN (' . mt_rand(1, 100) . ', ' . mt_rand(101, 200) . ')',
                $type === 4 => $randComparison($alias) . ' AND ' . $randProp($alias) . ' IS NOT NULL',
                default => $randComparison($alias),
            };
        };

        // ── Category 1: IS NULL / IS NOT NULL queries (20 iterations) ──
        for ($i = 0; $i < 20; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $prop = $pick($properties);
            $negated = mt_rand(0, 1) === 1;
            $nullExpr = $negated ? 'IS NOT NULL' : 'IS NULL';

            $oql = "SELECT {$alias} FROM {$entity} {$alias} WHERE {$alias}.{$prop} {$nullExpr}";

            yield "is_null_{$i}" => [$oql, "IS NULL/IS NOT NULL iteration {$i}"];
        }

        // ── Category 2: IN / NOT IN queries (20 iterations) ──
        for ($i = 0; $i < 20; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $prop = $pick($properties);
            $negated = mt_rand(0, 1) === 1;
            $inKeyword = $negated ? 'NOT IN' : 'IN';

            // Randomly choose parameter or literal list
            if (mt_rand(0, 1) === 0) {
                $valueList = ':vals' . mt_rand(1, 99);
            } else {
                $numLiterals = mt_rand(2, 5);
                $literals = [];
                for ($j = 0; $j < $numLiterals; $j++) {
                    $literals[] = (string) mt_rand(1, 500);
                }
                $valueList = implode(', ', $literals);
            }

            $oql = "SELECT {$alias} FROM {$entity} {$alias} WHERE {$alias}.{$prop} {$inKeyword} ({$valueList})";

            yield "in_expr_{$i}" => [$oql, "IN/NOT IN iteration {$i}"];
        }

        // ── Category 3: Aggregate functions in SELECT (20 iterations) ──
        for ($i = 0; $i < 20; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $func = $pick($aggregates);
            $prop = $pick($properties);
            $useDistinct = mt_rand(0, 1) === 1;
            $distinctKw = $useDistinct ? 'DISTINCT ' : '';

            // COUNT can use * as argument
            if ($func === 'COUNT' && mt_rand(0, 2) === 0) {
                $argument = '*';
                $distinctKw = ''; // COUNT(*) doesn't use DISTINCT
            } else {
                $argument = $distinctKw . $alias . '.' . $prop;
            }

            $oql = "SELECT {$func}({$argument}) FROM {$entity} {$alias}";

            yield "aggregate_{$i}" => [$oql, "Aggregate function iteration {$i}"];
        }

        // ── Category 4: HAVING clause queries (15 iterations) ──
        for ($i = 0; $i < 15; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $func = $pick($aggregates);
            $prop = $pick($properties);
            $groupProp = $pick($properties);
            $op = $pick($comparisonOps);
            $threshold = mt_rand(1, 100);

            $oql = "SELECT {$alias}.{$groupProp}, {$func}({$alias}.{$prop}) FROM {$entity} {$alias}"
                . " GROUP BY {$alias}.{$groupProp}"
                . " HAVING {$func}({$alias}.{$prop}) {$op} {$threshold}";

            yield "having_{$i}" => [$oql, "HAVING clause iteration {$i}"];
        }

        // ── Category 5: Entity-based JOIN WITH (15 iterations) ──
        for ($i = 0; $i < 15; $i++) {
            $eIdx = $pickIdx(count($entities) - 2);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $joinEntity = $entities[$eIdx + 1];
            $joinAlias = $aliases[$eIdx + 1];
            $joinType = mt_rand(0, 1) === 0 ? 'JOIN' : 'LEFT JOIN';
            $prop1 = $pick($properties);
            $prop2 = $pick($properties);

            $oql = "SELECT {$alias} FROM {$entity} {$alias}"
                . " {$joinType} {$joinEntity} {$joinAlias} WITH {$alias}.{$prop1} = {$joinAlias}.{$prop2}";

            yield "entity_join_{$i}" => [$oql, "Entity-based JOIN WITH iteration {$i}"];
        }

        // ── Category 6: SELECT * wildcard (10 iterations) ──
        for ($i = 0; $i < 10; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];

            $oql = "SELECT * FROM {$entity} {$alias}";

            // Optionally add WHERE
            if (mt_rand(0, 1) === 1) {
                $prop = $pick($properties);
                $oql .= " WHERE {$alias}.{$prop} IS NOT NULL";
            }

            yield "wildcard_{$i}" => [$oql, "SELECT * wildcard iteration {$i}"];
        }

        // ── Category 7: Column aliases (10 iterations) ──
        for ($i = 0; $i < 10; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $prop = $pick($properties);
            $colAlias = 'col' . mt_rand(1, 99);

            $oql = "SELECT {$alias}.{$prop} AS {$colAlias} FROM {$entity} {$alias}";

            yield "alias_{$i}" => [$oql, "Column alias iteration {$i}"];
        }

        // ── Category 8: SELECT DISTINCT (10 iterations) ──
        for ($i = 0; $i < 10; $i++) {
            $eIdx = $pickIdx(count($entities) - 1);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $prop = $pick($properties);

            $oql = "SELECT DISTINCT {$alias}.{$prop} FROM {$entity} {$alias}";

            yield "distinct_{$i}" => [$oql, "SELECT DISTINCT iteration {$i}"];
        }

        // ── Category 9: Combined complex queries (15+ iterations) ──
        for ($i = 0; $i < 15; $i++) {
            $eIdx = $pickIdx(count($entities) - 2);
            $entity = $entities[$eIdx];
            $alias = $aliases[$eIdx];
            $joinEntity = $entities[$eIdx + 1];
            $joinAlias = $aliases[$eIdx + 1];

            $parts = [];

            // SELECT clause: mix of aggregates, aliases, properties
            $selectType = mt_rand(0, 3);
            $selectDistinct = mt_rand(0, 1) === 1 && $selectType !== 0;
            $distinctKw = $selectDistinct ? 'DISTINCT ' : '';

            $selectClause = match ($selectType) {
                0 => '*',
                1 => $pick($aggregates) . '(' . $alias . '.' . $pick($properties) . ') AS total',
                2 => $alias . '.' . $pick($properties) . ' AS col1, ' . $alias . '.' . $pick($properties),
                default => $alias . '.' . $pick($properties),
            };

            // Don't use DISTINCT with * wildcard
            if ($selectClause === '*') {
                $distinctKw = '';
            }

            $parts[] = "SELECT {$distinctKw}{$selectClause} FROM {$entity} {$alias}";

            // Optional entity-based JOIN
            if (mt_rand(0, 1) === 1) {
                $joinType = mt_rand(0, 1) === 0 ? 'JOIN' : 'LEFT JOIN';
                $parts[] = "{$joinType} {$joinEntity} {$joinAlias} WITH {$alias}."
                    . $pick($properties) . ' = ' . $joinAlias . '.' . $pick($properties);
            }

            // Optional WHERE with mixed conditions
            if (mt_rand(0, 1) === 1) {
                $condType = mt_rand(0, 3);
                $whereCond = match ($condType) {
                    0 => $alias . '.' . $pick($properties) . ' IS NULL',
                    1 => $alias . '.' . $pick($properties) . ' IN (:param' . mt_rand(1, 50) . ')',
                    2 => $alias . '.' . $pick($properties) . ' NOT IN (' . mt_rand(1, 50) . ', ' . mt_rand(51, 100) . ')',
                    default => $alias . '.' . $pick($properties) . ' ' . $pick($comparisonOps) . ' :p' . mt_rand(1, 50),
                };
                $parts[] = "WHERE {$whereCond}";
            }

            // Optional GROUP BY + HAVING (only when not using *)
            if ($selectClause !== '*' && mt_rand(0, 1) === 1) {
                $groupProp = $pick($properties);
                $parts[] = "GROUP BY {$alias}.{$groupProp}";

                if (mt_rand(0, 1) === 1) {
                    $havingFunc = $pick($aggregates);
                    $havingProp = $pick($properties);
                    $havingOp = $pick($comparisonOps);
                    $parts[] = "HAVING {$havingFunc}({$alias}.{$havingProp}) {$havingOp} " . mt_rand(1, 100);
                }
            }

            // Optional ORDER BY
            if (mt_rand(0, 1) === 1) {
                $orderProp = $pick($properties);
                $dir = $pick($directions);
                $parts[] = "ORDER BY {$alias}.{$orderProp} {$dir}";
            }

            $oql = implode(' ', $parts);

            yield "complex_{$i}" => [$oql, "Complex combined iteration {$i}"];
        }
    }
}
