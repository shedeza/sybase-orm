<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query\AST;

use PHPUnit\Framework\TestCase;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SetClause;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\AST\Comparison;

/**
 * Unit tests for new AST nodes: UpdateStatement, DeleteStatement, SetClause, CustomFunctionCall.
 * Validates: Requirements 29.1–29.3, 30.1, 32.1
 */
final class AstNodesTest extends TestCase
{
    public function testUpdateStatementConstruction(): void
    {
        $setClauses = [
            new SetClause(
                new PropertyAccess('u', 'name'),
                new Parameter('name'),
            ),
        ];

        $stmt = new UpdateStatement('User', 'u', $setClauses);

        $this->assertSame('User', $stmt->entityName);
        $this->assertSame('u', $stmt->alias);
        $this->assertCount(1, $stmt->setClauses);
        $this->assertNull($stmt->where);
    }

    public function testUpdateStatementWithWhere(): void
    {
        $setClauses = [
            new SetClause(
                new PropertyAccess('u', 'name'),
                new Parameter('name'),
            ),
        ];
        $where = new WhereClause(
            new Comparison(
                new PropertyAccess('u', 'id'),
                '=',
                new Parameter('id'),
            ),
        );

        $stmt = new UpdateStatement('User', 'u', $setClauses, $where);

        $this->assertNotNull($stmt->where);
        $this->assertInstanceOf(WhereClause::class, $stmt->where);
    }

    public function testDeleteStatementConstruction(): void
    {
        $stmt = new DeleteStatement('User', 'u');

        $this->assertSame('User', $stmt->entityName);
        $this->assertSame('u', $stmt->alias);
        $this->assertNull($stmt->where);
    }

    public function testDeleteStatementWithWhere(): void
    {
        $where = new WhereClause(
            new Comparison(
                new PropertyAccess('u', 'id'),
                '=',
                new Parameter('id'),
            ),
        );

        $stmt = new DeleteStatement('User', 'u', $where);

        $this->assertNotNull($stmt->where);
    }

    public function testSetClauseWithParameter(): void
    {
        $clause = new SetClause(
            new PropertyAccess('u', 'name'),
            new Parameter('name'),
        );

        $this->assertSame('u', $clause->property->alias);
        $this->assertSame('name', $clause->property->property);
        $this->assertInstanceOf(Parameter::class, $clause->value);
        $this->assertSame('name', $clause->value->name);
    }

    public function testSetClauseWithLiteral(): void
    {
        $clause = new SetClause(
            new PropertyAccess('u', 'age'),
            new Literal(25, 'integer'),
        );

        $this->assertInstanceOf(Literal::class, $clause->value);
        $this->assertSame(25, $clause->value->value);
    }

    public function testSetClauseWithNullLiteral(): void
    {
        $clause = new SetClause(
            new PropertyAccess('u', 'deletedAt'),
            new Literal('NULL', 'null'),
        );

        $this->assertInstanceOf(Literal::class, $clause->value);
        $this->assertSame('null', $clause->value->type);
    }

    public function testSetClauseWithCustomFunctionCall(): void
    {
        $func = new CustomFunctionCall('RAND', [], null);
        $clause = new SetClause(
            new PropertyAccess('u', 'randomValue'),
            $func,
        );

        $this->assertInstanceOf(CustomFunctionCall::class, $clause->value);
    }

    public function testCustomFunctionCallRand(): void
    {
        $func = new CustomFunctionCall('RAND', [], null);

        $this->assertSame('RAND', $func->functionName);
        $this->assertEmpty($func->arguments);
        $this->assertNull($func->castType);
    }

    public function testCustomFunctionCallConvert(): void
    {
        $func = new CustomFunctionCall(
            'CONVERT',
            [new PropertyAccess('u', 'value')],
            'REAL',
        );

        $this->assertSame('CONVERT', $func->functionName);
        $this->assertCount(1, $func->arguments);
        $this->assertInstanceOf(PropertyAccess::class, $func->arguments[0]);
        $this->assertSame('REAL', $func->castType);
    }

    public function testCustomFunctionCallNested(): void
    {
        $inner = new CustomFunctionCall('RAND', [], null);
        $outer = new CustomFunctionCall('CONVERT', [$inner], 'REAL');

        $this->assertSame('CONVERT', $outer->functionName);
        $this->assertCount(1, $outer->arguments);
        $this->assertInstanceOf(CustomFunctionCall::class, $outer->arguments[0]);
        $this->assertSame('RAND', $outer->arguments[0]->functionName);
        $this->assertSame('REAL', $outer->castType);
    }

    public function testCustomFunctionCallWithParameter(): void
    {
        $func = new CustomFunctionCall(
            'CONVERT',
            [new Parameter('value')],
            'INT',
        );

        $this->assertCount(1, $func->arguments);
        $this->assertInstanceOf(Parameter::class, $func->arguments[0]);
        $this->assertSame('INT', $func->castType);
    }
}
