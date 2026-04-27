<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\OqlPrinter;

/**
 * Tests for OqlPrinter handling of custom functions (multi-arg, no-arg, CONVERT).
 */
final class OqlPrinterCustomFunctionTest extends TestCase
{
    private OqlPrinter $printer;

    protected function setUp(): void
    {
        $this->printer = new OqlPrinter();
    }

    public function testPrintNoArgCustomFunction(): void
    {
        $stmt = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'token'),
                    '=',
                    new CustomFunctionCall('NEWID'),
                ),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertStringContainsString('NEWID()', $result);
    }

    public function testPrintMultiArgCustomFunction(): void
    {
        $func = new CustomFunctionCall('DATEDIFF_DAYS', [
            new PropertyAccess('u', 'createdAt'),
            new PropertyAccess('u', 'updatedAt'),
        ]);

        $stmt = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison($func, '>', new Literal(30, 'integer')),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertStringContainsString('DATEDIFF_DAYS(u.createdAt, u.updatedAt)', $result);
    }

    public function testPrintConvertFunction(): void
    {
        $func = new CustomFunctionCall('CONVERT', [new Parameter('value')], 'REAL');

        $stmt = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison($func, '>', new Literal(0, 'integer')),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertStringContainsString('CONVERT(:value AS REAL)', $result);
    }

    public function testPrintRandFunction(): void
    {
        $func = new CustomFunctionCall('RAND');

        $stmt = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison(new PropertyAccess('u', 'score'), '>', $func),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertStringContainsString('RAND()', $result);
    }

    public function testPrintCustomFunctionWithLiteralArgs(): void
    {
        $func = new CustomFunctionCall('MY_FUNC', [
            new Literal(42, 'integer'),
            new Literal('hello', 'string'),
        ]);

        $stmt = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('User', 'u'),
            where: new WhereClause(
                new Comparison($func, '=', new Parameter('val')),
            ),
        );

        $result = $this->printer->print($stmt);

        $this->assertStringContainsString("MY_FUNC(42, 'hello')", $result);
    }
}
