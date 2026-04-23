<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\OrderByItem;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlPrinter;

/**
 * Property-based round-trip test for OQL Parser and Printer.
 *
 * **Validates: Requirements 4.6**
 *
 * Property 1: Round-trip OQL (ida y vuelta)
 * For all valid OQL ASTs, parse(print(ast)) == ast
 *
 * Since PHP doesn't have a standard property-based testing library,
 * we use a data provider with representative AST structures that cover
 * the full grammar space.
 */
class OqlRoundTripTest extends TestCase
{
    private OqlParser $parser;
    private OqlPrinter $printer;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();
        $this->printer = new OqlPrinter();
    }

    /**
     * @dataProvider validAstProvider
     */
    public function testRoundTripParseOfPrintedAstEqualsOriginal(SelectStatement $ast, string $description): void
    {
        // Print AST to OQL text
        $oqlText = $this->printer->print($ast);

        // Parse OQL text back to AST
        $reparsedAst = $this->parser->parse($oqlText);

        // Assert structural equality
        $this->assertEquals($ast, $reparsedAst, sprintf(
            "Round-trip failed for: %s\nOQL text: %s",
            $description,
            $oqlText,
        ));
    }

    /**
     * @dataProvider validOqlStringProvider
     */
    public function testRoundTripPrintOfParsedOqlEqualsOriginal(string $oql, string $description): void
    {
        // Parse OQL text to AST
        $ast = $this->parser->parse($oql);

        // Print AST back to OQL text
        $reprintedOql = $this->printer->print($ast);

        // Assert text equality
        $this->assertSame($oql, $reprintedOql, sprintf(
            "Round-trip failed for: %s",
            $description,
        ));
    }

    /**
     * Provides a variety of valid AST structures covering the full OQL grammar.
     */
    public static function validAstProvider(): iterable
    {
        // 1. Simple SELECT
        yield 'simple select' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
            ),
            'Simple SELECT u FROM User u',
        ];

        // 2. Multiple select expressions
        yield 'multiple select expressions' => [
            new SelectStatement(
                selectExpressions: [
                    new SelectExpression('u.name'),
                    new SelectExpression('u.email'),
                ],
                from: new FromClause('User', 'u'),
            ),
            'SELECT u.name, u.email FROM User u',
        ];

        // 3. WHERE with parameter
        yield 'where with parameter' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                where: new WhereClause(
                    new Comparison(
                        new PropertyAccess('u', 'name'),
                        '=',
                        new Parameter('name'),
                    ),
                ),
            ),
            'WHERE with parameter',
        ];

        // 4. WHERE with integer literal
        yield 'where with integer literal' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                where: new WhereClause(
                    new Comparison(
                        new PropertyAccess('u', 'age'),
                        '>',
                        new Literal(18, 'integer'),
                    ),
                ),
            ),
            'WHERE with integer literal',
        ];

        // 5. WHERE with string literal
        yield 'where with string literal' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                where: new WhereClause(
                    new Comparison(
                        new PropertyAccess('u', 'name'),
                        '=',
                        new Literal('John', 'string'),
                    ),
                ),
            ),
            'WHERE with string literal',
        ];

        // 6. WHERE with AND
        yield 'where with AND' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                where: new WhereClause(
                    new LogicalExpression(
                        new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                        'AND',
                        new Comparison(new PropertyAccess('u', 'age'), '>', new Parameter('age')),
                    ),
                ),
            ),
            'WHERE with AND',
        ];

        // 7. WHERE with OR
        yield 'where with OR' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                where: new WhereClause(
                    new LogicalExpression(
                        new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                        'OR',
                        new Comparison(new PropertyAccess('u', 'email'), '=', new Parameter('email')),
                    ),
                ),
            ),
            'WHERE with OR',
        ];

        // 8. JOIN
        yield 'join' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                joins: [
                    new JoinClause('JOIN', new PropertyAccess('u', 'posts'), 'p'),
                ],
            ),
            'JOIN',
        ];

        // 9. LEFT JOIN
        yield 'left join' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                joins: [
                    new JoinClause('LEFT JOIN', new PropertyAccess('u', 'posts'), 'p'),
                ],
            ),
            'LEFT JOIN',
        ];

        // 10. ORDER BY ASC
        yield 'order by asc' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                orderBy: new OrderByClause([
                    new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
                ]),
            ),
            'ORDER BY ASC',
        ];

        // 11. ORDER BY DESC
        yield 'order by desc' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                orderBy: new OrderByClause([
                    new OrderByItem(new PropertyAccess('u', 'name'), 'DESC'),
                ]),
            ),
            'ORDER BY DESC',
        ];

        // 12. Multiple ORDER BY
        yield 'multiple order by' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                orderBy: new OrderByClause([
                    new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
                    new OrderByItem(new PropertyAccess('u', 'age'), 'DESC'),
                ]),
            ),
            'Multiple ORDER BY',
        ];

        // 13. GROUP BY
        yield 'group by' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u.name')],
                from: new FromClause('User', 'u'),
                groupBy: new GroupByClause([
                    new PropertyAccess('u', 'name'),
                ]),
            ),
            'GROUP BY',
        ];

        // 14. Full complex query
        yield 'full complex query' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u')],
                from: new FromClause('User', 'u'),
                joins: [
                    new JoinClause('JOIN', new PropertyAccess('u', 'posts'), 'p'),
                ],
                where: new WhereClause(
                    new LogicalExpression(
                        new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                        'AND',
                        new Comparison(new PropertyAccess('p', 'title'), 'LIKE', new Parameter('title')),
                    ),
                ),
                orderBy: new OrderByClause([
                    new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
                ]),
            ),
            'Full complex query with JOIN, WHERE, ORDER BY',
        ];

        // 15. All comparison operators
        foreach (['=', '!=', '<', '>', '<=', '>=', 'LIKE'] as $op) {
            yield "comparison operator {$op}" => [
                new SelectStatement(
                    selectExpressions: [new SelectExpression('u')],
                    from: new FromClause('User', 'u'),
                    where: new WhereClause(
                        new Comparison(
                            new PropertyAccess('u', 'age'),
                            $op,
                            new Parameter('val'),
                        ),
                    ),
                ),
                "Comparison operator {$op}",
            ];
        }

        // 16. JOIN + WHERE + GROUP BY + ORDER BY
        yield 'all clauses combined' => [
            new SelectStatement(
                selectExpressions: [new SelectExpression('u.name')],
                from: new FromClause('User', 'u'),
                joins: [
                    new JoinClause('LEFT JOIN', new PropertyAccess('u', 'posts'), 'p'),
                ],
                where: new WhereClause(
                    new Comparison(new PropertyAccess('u', 'age'), '>', new Literal(21, 'integer')),
                ),
                orderBy: new OrderByClause([
                    new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
                ]),
                groupBy: new GroupByClause([
                    new PropertyAccess('u', 'name'),
                ]),
            ),
            'All clauses combined',
        ];
    }

    /**
     * Provides canonical OQL strings for text-level round-trip testing.
     */
    public static function validOqlStringProvider(): iterable
    {
        yield 'simple select' => [
            'SELECT u FROM User u',
            'Simple SELECT',
        ];

        yield 'select with where' => [
            'SELECT u FROM User u WHERE u.name = :name',
            'SELECT with WHERE',
        ];

        yield 'select with integer literal' => [
            'SELECT u FROM User u WHERE u.age > 18',
            'SELECT with integer literal',
        ];

        yield 'select with string literal' => [
            "SELECT u FROM User u WHERE u.name = 'John'",
            'SELECT with string literal',
        ];

        yield 'select with and' => [
            'SELECT u FROM User u WHERE u.name = :name AND u.age > :age',
            'SELECT with AND',
        ];

        yield 'select with join' => [
            'SELECT u FROM User u JOIN u.posts p',
            'SELECT with JOIN',
        ];

        yield 'select with left join' => [
            'SELECT u FROM User u LEFT JOIN u.posts p',
            'SELECT with LEFT JOIN',
        ];

        yield 'select with order by' => [
            'SELECT u FROM User u ORDER BY u.name ASC',
            'SELECT with ORDER BY',
        ];

        yield 'select with group by' => [
            'SELECT u.name FROM User u GROUP BY u.name',
            'SELECT with GROUP BY',
        ];

        yield 'complex query' => [
            'SELECT u FROM User u JOIN u.posts p WHERE u.name = :name AND p.title LIKE :title ORDER BY u.name ASC',
            'Complex query',
        ];

        yield 'multiple select expressions' => [
            'SELECT u.name, u.email FROM User u',
            'Multiple select expressions',
        ];

        yield 'multiple order by' => [
            'SELECT u FROM User u ORDER BY u.name ASC, u.age DESC',
            'Multiple ORDER BY',
        ];
    }
}
