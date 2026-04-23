<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\FunctionCall;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\HavingClause;
use SybaseORM\Query\AST\InExpression;
use SybaseORM\Query\AST\IsNullExpression;
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
use SybaseORM\Query\OqlPrinter;

/**
 * Unit tests for OqlPrinter.
 * Validates: Requirements 4.5, 5.4, 6.6, 7.8, 7.9, 8.5, 9.5
 */
class OqlPrinterTest extends TestCase
{
    private OqlPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new OqlPrinter();
    }

    public function testPrintSimpleSelect(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame('SELECT u FROM User u', $this->printer->print($ast));
    }

    public function testPrintSelectWithWhereParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'name'),
                    '=',
                    new Parameter('name'),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.name = :name',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithLiteral(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'age'),
                    '>',
                    new Literal(18, 'integer'),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.age > 18',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithStringLiteral(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'name'),
                    '=',
                    new Literal('John', 'string'),
                ),
            ),
        );

        $this->assertSame(
            "SELECT u FROM User u WHERE u.name = 'John'",
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithLogicalAnd(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                    'AND',
                    new Comparison(new PropertyAccess('u', 'age'), '>', new Parameter('age')),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.name = :name AND u.age > :age',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithJoin(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            joins: [
                new JoinClause('JOIN', new PropertyAccess('u', 'posts'), 'p'),
            ],
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('p', 'title'),
                    '=',
                    new Parameter('title'),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u JOIN u.posts p WHERE p.title = :title',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithLeftJoin(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            joins: [
                new JoinClause('LEFT JOIN', new PropertyAccess('u', 'posts'), 'p'),
            ],
        );

        $this->assertSame(
            'SELECT u FROM User u LEFT JOIN u.posts p',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithOrderBy(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            orderBy: new OrderByClause([
                new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
            ]),
        );

        $this->assertSame(
            'SELECT u FROM User u ORDER BY u.name ASC',
            $this->printer->print($ast),
        );
    }

    public function testPrintSelectWithGroupBy(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u.name')],
            from: new FromClause('User', 'u'),
            groupBy: new GroupByClause([
                new PropertyAccess('u', 'name'),
            ]),
        );

        $this->assertSame(
            'SELECT u.name FROM User u GROUP BY u.name',
            $this->printer->print($ast),
        );
    }

    public function testPrintComplexQuery(): void
    {
        $ast = new SelectStatement(
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
        );

        $this->assertSame(
            'SELECT u FROM User u JOIN u.posts p WHERE u.name = :name AND p.title LIKE :title ORDER BY u.name ASC',
            $this->printer->print($ast),
        );
    }

    // ── IsNullExpression tests ──────────────────────────────────────

    public function testPrintIsNull(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new IsNullExpression(new PropertyAccess('u', 'deletedAt')),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.deletedAt IS NULL',
            $this->printer->print($ast),
        );
    }

    public function testPrintIsNotNull(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new IsNullExpression(new PropertyAccess('u', 'email'), negated: true),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.email IS NOT NULL',
            $this->printer->print($ast),
        );
    }

    public function testPrintIsNullWithLogicalExpression(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new IsNullExpression(new PropertyAccess('u', 'deletedAt')),
                    'AND',
                    new Comparison(new PropertyAccess('u', 'active'), '=', new Literal(1, 'integer')),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.deletedAt IS NULL AND u.active = 1',
            $this->printer->print($ast),
        );
    }

    // ── InExpression tests ──────────────────────────────────────────

    public function testPrintInWithParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'status'),
                    [new Parameter('statuses')],
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.status IN (:statuses)',
            $this->printer->print($ast),
        );
    }

    public function testPrintNotInWithParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'role'),
                    [new Parameter('excludedRoles')],
                    negated: true,
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.role NOT IN (:excludedRoles)',
            $this->printer->print($ast),
        );
    }

    public function testPrintInWithLiterals(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'id'),
                    [new Literal(1, 'integer'), new Literal(2, 'integer'), new Literal(3, 'integer')],
                ),
            ),
        );

        $this->assertSame(
            'SELECT u FROM User u WHERE u.id IN (1, 2, 3)',
            $this->printer->print($ast),
        );
    }

    public function testPrintNotInWithStringLiterals(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'status'),
                    [new Literal('active', 'string'), new Literal('pending', 'string')],
                    negated: true,
                ),
            ),
        );

        $this->assertSame(
            "SELECT u FROM User u WHERE u.status NOT IN ('active', 'pending')",
            $this->printer->print($ast),
        );
    }

    // ── FunctionCall tests ──────────────────────────────────────────

    public function testPrintCountFunction(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'id'))),
            ],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT COUNT(u.id) FROM User u',
            $this->printer->print($ast),
        );
    }

    public function testPrintCountStar(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', '*')),
            ],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT COUNT(*) FROM User u',
            $this->printer->print($ast),
        );
    }

    public function testPrintCountDistinct(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'department'), distinct: true)),
            ],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT COUNT(DISTINCT u.department) FROM User u',
            $this->printer->print($ast),
        );
    }

    public function testPrintSumFunction(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('SUM', new PropertyAccess('o', 'amount'))),
            ],
            from: new FromClause('Order', 'o'),
        );

        $this->assertSame(
            'SELECT SUM(o.amount) FROM Order o',
            $this->printer->print($ast),
        );
    }

    public function testPrintAvgMinMaxFunctions(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('AVG', new PropertyAccess('o', 'price'))),
                new SelectExpression(new FunctionCall('MIN', new PropertyAccess('o', 'price'))),
                new SelectExpression(new FunctionCall('MAX', new PropertyAccess('o', 'price'))),
            ],
            from: new FromClause('Order', 'o'),
        );

        $this->assertSame(
            'SELECT AVG(o.price), MIN(o.price), MAX(o.price) FROM Order o',
            $this->printer->print($ast),
        );
    }

    public function testPrintFunctionCallWithAlias(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(
                    new FunctionCall('COUNT', new PropertyAccess('u', 'id')),
                    alias: 'total',
                ),
            ],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT COUNT(u.id) AS total FROM User u',
            $this->printer->print($ast),
        );
    }

    // ── HavingClause tests ──────────────────────────────────────────

    public function testPrintHavingClause(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.department'),
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'id')), alias: 'cnt'),
            ],
            from: new FromClause('User', 'u'),
            groupBy: new GroupByClause([new PropertyAccess('u', 'department')]),
            havingClause: new HavingClause(
                new Comparison(
                    new FunctionCall('COUNT', new PropertyAccess('u', 'id')),
                    '>',
                    new Literal(5, 'integer'),
                ),
            ),
        );

        $this->assertSame(
            'SELECT u.department, COUNT(u.id) AS cnt FROM User u GROUP BY u.department HAVING COUNT(u.id) > 5',
            $this->printer->print($ast),
        );
    }

    public function testPrintHavingWithoutGroupBy(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', '*'), alias: 'total'),
            ],
            from: new FromClause('User', 'u'),
            havingClause: new HavingClause(
                new Comparison(
                    new FunctionCall('COUNT', '*'),
                    '>=',
                    new Literal(10, 'integer'),
                ),
            ),
        );

        $this->assertSame(
            'SELECT COUNT(*) AS total FROM User u HAVING COUNT(*) >= 10',
            $this->printer->print($ast),
        );
    }

    // ── DISTINCT tests ──────────────────────────────────────────────

    public function testPrintSelectDistinct(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u.name')],
            from: new FromClause('User', 'u'),
            distinct: true,
        );

        $this->assertSame(
            'SELECT DISTINCT u.name FROM User u',
            $this->printer->print($ast),
        );
    }

    // ── Wildcard tests ──────────────────────────────────────────────

    public function testPrintSelectWildcard(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('*')],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT * FROM User u',
            $this->printer->print($ast),
        );
    }

    // ── Alias tests ─────────────────────────────────────────────────

    public function testPrintSelectWithAlias(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.name', alias: 'userName'),
                new SelectExpression('u.email', alias: 'userEmail'),
            ],
            from: new FromClause('User', 'u'),
        );

        $this->assertSame(
            'SELECT u.name AS userName, u.email AS userEmail FROM User u',
            $this->printer->print($ast),
        );
    }

    // ── Entity-based JoinClause with WITH tests ─────────────────────

    public function testPrintEntityBasedJoinWithCondition(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u'), new SelectExpression('a')],
            from: new FromClause('User', 'u'),
            joins: [
                new JoinClause(
                    'JOIN',
                    new PropertyAccess('Address', ''),
                    'a',
                    entityName: 'Address',
                    withCondition: new Comparison(
                        new PropertyAccess('a', 'userId'),
                        '=',
                        new PropertyAccess('u', 'id'),
                    ),
                ),
            ],
        );

        $this->assertSame(
            'SELECT u, a FROM User u JOIN Address a WITH a.userId = u.id',
            $this->printer->print($ast),
        );
    }

    public function testPrintEntityBasedLeftJoinWithCondition(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u'), new SelectExpression('p')],
            from: new FromClause('User', 'u'),
            joins: [
                new JoinClause(
                    'LEFT JOIN',
                    new PropertyAccess('Profile', ''),
                    'p',
                    entityName: 'Profile',
                    withCondition: new Comparison(
                        new PropertyAccess('p', 'userId'),
                        '=',
                        new PropertyAccess('u', 'id'),
                    ),
                ),
            ],
        );

        $this->assertSame(
            'SELECT u, p FROM User u LEFT JOIN Profile p WITH p.userId = u.id',
            $this->printer->print($ast),
        );
    }

    public function testPrintEntityBasedJoinWithLogicalCondition(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            joins: [
                new JoinClause(
                    'JOIN',
                    new PropertyAccess('Role', ''),
                    'r',
                    entityName: 'Role',
                    withCondition: new LogicalExpression(
                        new Comparison(
                            new PropertyAccess('r', 'userId'),
                            '=',
                            new PropertyAccess('u', 'id'),
                        ),
                        'AND',
                        new Comparison(
                            new PropertyAccess('r', 'active'),
                            '=',
                            new Literal(1, 'integer'),
                        ),
                    ),
                ),
            ],
        );

        $this->assertSame(
            'SELECT u FROM User u JOIN Role r WITH r.userId = u.id AND r.active = 1',
            $this->printer->print($ast),
        );
    }

    // ── Complex combined tests ──────────────────────────────────────

    public function testPrintComplexQueryWithAllNewFeatures(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.department'),
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'id')), alias: 'cnt'),
            ],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new IsNullExpression(new PropertyAccess('u', 'deletedAt')),
                    'AND',
                    new InExpression(
                        new PropertyAccess('u', 'status'),
                        [new Literal('active', 'string'), new Literal('pending', 'string')],
                    ),
                ),
            ),
            groupBy: new GroupByClause([new PropertyAccess('u', 'department')]),
            havingClause: new HavingClause(
                new Comparison(
                    new FunctionCall('COUNT', new PropertyAccess('u', 'id')),
                    '>',
                    new Literal(3, 'integer'),
                ),
            ),
            orderBy: new OrderByClause([
                new OrderByItem(new PropertyAccess('u', 'department'), 'ASC'),
            ]),
            distinct: true,
        );

        $this->assertSame(
            "SELECT DISTINCT u.department, COUNT(u.id) AS cnt FROM User u WHERE u.deletedAt IS NULL AND u.status IN ('active', 'pending') GROUP BY u.department HAVING COUNT(u.id) > 3 ORDER BY u.department ASC",
            $this->printer->print($ast),
        );
    }
}
