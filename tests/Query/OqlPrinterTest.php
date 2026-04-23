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
use SybaseORM\Query\OqlPrinter;

/**
 * Unit tests for OqlPrinter.
 * Validates: Requirement 4.5
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
}
