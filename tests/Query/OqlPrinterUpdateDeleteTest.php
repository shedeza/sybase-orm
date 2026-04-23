<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SetClause;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\OqlPrinter;

/**
 * Unit tests for OqlPrinter UPDATE, DELETE, and CustomFunctionCall printing.
 * Validates: Requirements 29.4, 30.2
 */
final class OqlPrinterUpdateDeleteTest extends TestCase
{
    private OqlPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new OqlPrinter();
    }

    public function testPrintSimpleUpdate(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'name'), new Parameter('name'))],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.name = :name', $result);
    }

    public function testPrintUpdateWithMultipleSets(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [
                new SetClause(new PropertyAccess('u', 'name'), new Parameter('name')),
                new SetClause(new PropertyAccess('u', 'email'), new Parameter('email')),
            ],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.name = :name, u.email = :email', $result);
    }

    public function testPrintUpdateWithWhere(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'name'), new Parameter('name'))],
            new WhereClause(
                new Comparison(new PropertyAccess('u', 'id'), '=', new Parameter('id')),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.name = :name WHERE u.id = :id', $result);
    }

    public function testPrintUpdateWithNullValue(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'deletedAt'), new Literal('NULL', 'null'))],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.deletedAt = NULL', $result);
    }

    public function testPrintUpdateWithStringLiteral(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'status'), new Literal('active', 'string'))],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame("UPDATE User u SET u.status = 'active'", $result);
    }

    public function testPrintUpdateWithNumericLiteral(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'age'), new Literal(25, 'integer'))],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.age = 25', $result);
    }

    public function testPrintUpdateWithRandFunction(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'randomValue'), new CustomFunctionCall('RAND'))],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.randomValue = RAND()', $result);
    }

    public function testPrintUpdateWithConvertFunction(): void
    {
        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(
                new PropertyAccess('u', 'score'),
                new CustomFunctionCall('CONVERT', [new Parameter('value')], 'REAL'),
            )],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.score = CONVERT(:value AS REAL)', $result);
    }

    public function testPrintUpdateWithNestedConvertRand(): void
    {
        $inner = new CustomFunctionCall('RAND');
        $outer = new CustomFunctionCall('CONVERT', [$inner], 'REAL');

        $stmt = new UpdateStatement(
            'User',
            'u',
            [new SetClause(new PropertyAccess('u', 'randomValue'), $outer)],
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('UPDATE User u SET u.randomValue = CONVERT(RAND() AS REAL)', $result);
    }

    public function testPrintSimpleDelete(): void
    {
        $stmt = new DeleteStatement('User', 'u');

        $result = $this->printer->print($stmt);

        $this->assertSame('DELETE FROM User u', $result);
    }

    public function testPrintDeleteWithWhere(): void
    {
        $stmt = new DeleteStatement(
            'User',
            'u',
            new WhereClause(
                new Comparison(new PropertyAccess('u', 'id'), '=', new Parameter('id')),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertSame('DELETE FROM User u WHERE u.id = :id', $result);
    }

    public function testPrintCustomFunctionInComparison(): void
    {
        // Test that CustomFunctionCall works in comparison operands via SELECT
        $selectStmt = new \SybaseORM\Query\AST\SelectStatement(
            selectExpressions: [new \SybaseORM\Query\AST\SelectExpression('u')],
            from: new \SybaseORM\Query\AST\FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(
                    new CustomFunctionCall('CONVERT', [new PropertyAccess('u', 'score')], 'REAL'),
                    '>',
                    new Literal(0.5, 'float'),
                ),
            ),
        );

        $result = $this->printer->print($selectStmt);

        $this->assertStringContainsString('CONVERT(u.score AS REAL) > 0.5', $result);
    }
}
