<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\OqlParser;

/**
 * Unit tests for OqlParser UPDATE, DELETE, and CustomFunction parsing.
 * Validates: Requirements 29.1–29.5, 30.1–30.3, 32.1–32.3
 */
final class OqlParserUpdateDeleteTest extends TestCase
{
    private OqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();
    }

    // ── Statement type detection ────────────────────────────────────

    public function testParseDetectsSelect(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u');
        $this->assertInstanceOf(SelectStatement::class, $ast);
    }

    public function testParseDetectsUpdate(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.name = :name');
        $this->assertInstanceOf(UpdateStatement::class, $ast);
    }

    public function testParseDetectsDelete(): void
    {
        $ast = $this->parser->parse('DELETE FROM User u');
        $this->assertInstanceOf(DeleteStatement::class, $ast);
    }

    public function testParseThrowsOnUnknownStatement(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('INSERT INTO User u');
    }

    // ── UPDATE parsing ──────────────────────────────────────────────

    public function testParseSimpleUpdate(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.name = :name');

        $this->assertInstanceOf(UpdateStatement::class, $ast);
        $this->assertSame('User', $ast->entityName);
        $this->assertSame('u', $ast->alias);
        $this->assertCount(1, $ast->setClauses);
        $this->assertNull($ast->where);

        $set = $ast->setClauses[0];
        $this->assertSame('u', $set->property->alias);
        $this->assertSame('name', $set->property->property);
        $this->assertInstanceOf(Parameter::class, $set->value);
        $this->assertSame('name', $set->value->name);
    }

    public function testParseUpdateWithMultipleSetClauses(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.name = :name, u.email = :email');

        $this->assertCount(2, $ast->setClauses);
        $this->assertSame('name', $ast->setClauses[0]->property->property);
        $this->assertSame('email', $ast->setClauses[1]->property->property);
    }

    public function testParseUpdateWithWhere(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.name = :name WHERE u.id = :id');

        $this->assertNotNull($ast->where);
        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('=', $condition->operator);
    }

    public function testParseUpdateWithWhereAndMultipleSets(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.name = :name, u.age = :age WHERE u.id = :id AND u.active = 1');

        $this->assertCount(2, $ast->setClauses);
        $this->assertNotNull($ast->where);
        $this->assertInstanceOf(LogicalExpression::class, $ast->where->condition);
    }

    public function testParseUpdateWithNullValue(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.deletedAt = NULL WHERE u.id = :id');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(Literal::class, $set->value);
        $this->assertSame('null', $set->value->type);
        $this->assertSame('NULL', $set->value->value);
    }

    public function testParseUpdateWithStringLiteral(): void
    {
        $ast = $this->parser->parse("UPDATE User u SET u.status = 'active' WHERE u.id = :id");

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(Literal::class, $set->value);
        $this->assertSame('string', $set->value->type);
        $this->assertSame('active', $set->value->value);
    }

    public function testParseUpdateWithNumericLiteral(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.age = 25 WHERE u.id = :id');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(Literal::class, $set->value);
        $this->assertSame('integer', $set->value->type);
        $this->assertSame(25, $set->value->value);
    }

    public function testParseUpdateWithFloatLiteral(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.score = 3.14 WHERE u.id = :id');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(Literal::class, $set->value);
        $this->assertSame('float', $set->value->type);
        $this->assertSame(3.14, $set->value->value);
    }

    // ── DELETE parsing ──────────────────────────────────────────────

    public function testParseSimpleDelete(): void
    {
        $ast = $this->parser->parse('DELETE FROM User u');

        $this->assertInstanceOf(DeleteStatement::class, $ast);
        $this->assertSame('User', $ast->entityName);
        $this->assertSame('u', $ast->alias);
        $this->assertNull($ast->where);
    }

    public function testParseDeleteWithWhere(): void
    {
        $ast = $this->parser->parse('DELETE FROM User u WHERE u.id = :id');

        $this->assertNotNull($ast->where);
        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
    }

    public function testParseDeleteWithComplexWhere(): void
    {
        $ast = $this->parser->parse('DELETE FROM User u WHERE u.active = 0 AND u.createdAt < :cutoff');

        $this->assertNotNull($ast->where);
        $this->assertInstanceOf(LogicalExpression::class, $ast->where->condition);
    }

    public function testParseDeleteWithoutFromThrows(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('DELETE User u');
    }

    // ── Custom function parsing ─────────────────────────────────────

    public function testParseRandFunction(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.randomValue = RAND()');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $set->value);
        $this->assertSame('RAND', $set->value->functionName);
        $this->assertEmpty($set->value->arguments);
        $this->assertNull($set->value->castType);
    }

    public function testParseConvertFunction(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.score = CONVERT(:value AS REAL)');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $set->value);
        $this->assertSame('CONVERT', $set->value->functionName);
        $this->assertCount(1, $set->value->arguments);
        $this->assertInstanceOf(Parameter::class, $set->value->arguments[0]);
        $this->assertSame('REAL', $set->value->castType);
    }

    public function testParseConvertWithPropertyAccess(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.score = CONVERT(u.rawScore AS REAL)');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $set->value);
        $this->assertSame('CONVERT', $set->value->functionName);
        $this->assertInstanceOf(PropertyAccess::class, $set->value->arguments[0]);
        $this->assertSame('rawScore', $set->value->arguments[0]->property);
    }

    public function testParseNestedConvertRand(): void
    {
        $ast = $this->parser->parse('UPDATE User u SET u.randomValue = CONVERT(RAND() AS REAL)');

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $set->value);
        $this->assertSame('CONVERT', $set->value->functionName);
        $this->assertSame('REAL', $set->value->castType);

        $inner = $set->value->arguments[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $inner);
        $this->assertSame('RAND', $inner->functionName);
    }

    public function testParseCustomFunctionInWhereCondition(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE CONVERT(u.score AS REAL) > 0.5');

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(CustomFunctionCall::class, $condition->left);
        $this->assertSame('CONVERT', $condition->left->functionName);
        $this->assertSame('>', $condition->operator);
    }

    public function testParseCustomFunctionAsRightOperand(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.threshold > RAND()');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(CustomFunctionCall::class, $condition->right);
        $this->assertSame('RAND', $condition->right->functionName);
    }

    public function testParseConvertWithLiteralArgument(): void
    {
        $ast = $this->parser->parse("UPDATE User u SET u.score = CONVERT(42 AS REAL)");

        $set = $ast->setClauses[0];
        $this->assertInstanceOf(CustomFunctionCall::class, $set->value);
        $this->assertInstanceOf(Literal::class, $set->value->arguments[0]);
        $this->assertSame(42, $set->value->arguments[0]->value);
    }
}
