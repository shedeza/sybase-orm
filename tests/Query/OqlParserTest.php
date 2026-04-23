<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\OqlParser;

/**
 * Unit tests for OqlParser.
 * Validates: Requirements 4.1, 4.3
 */
class OqlParserTest extends TestCase
{
    private OqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();
    }

    public function testParseSimpleSelect(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u');

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertCount(1, $ast->selectExpressions);
        $this->assertSame('u', $ast->selectExpressions[0]->expression);
        $this->assertSame('User', $ast->from->entityName);
        $this->assertSame('u', $ast->from->alias);
        $this->assertNull($ast->where);
        $this->assertEmpty($ast->joins);
        $this->assertNull($ast->orderBy);
        $this->assertNull($ast->groupBy);
    }

    public function testParseSelectWithWhereParameter(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.name = :name');

        $this->assertNotNull($ast->where);
        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(PropertyAccess::class, $condition->left);
        $this->assertSame('u', $condition->left->alias);
        $this->assertSame('name', $condition->left->property);
        $this->assertSame('=', $condition->operator);
        $this->assertInstanceOf(Parameter::class, $condition->right);
        $this->assertSame('name', $condition->right->name);
    }

    public function testParseSelectWithWhereLiteral(): void
    {
        $ast = $this->parser->parse("SELECT u FROM User u WHERE u.age > 18");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('>', $condition->operator);
        $this->assertInstanceOf(Literal::class, $condition->right);
        $this->assertSame(18, $condition->right->value);
        $this->assertSame('integer', $condition->right->type);
    }

    public function testParseSelectWithStringLiteral(): void
    {
        $ast = $this->parser->parse("SELECT u FROM User u WHERE u.name = 'John'");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(Literal::class, $condition->right);
        $this->assertSame('John', $condition->right->value);
        $this->assertSame('string', $condition->right->type);
    }

    public function testParseSelectWithAndCondition(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.name = :name AND u.age > :age');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(LogicalExpression::class, $condition);
        $this->assertSame('AND', $condition->operator);
        $this->assertInstanceOf(Comparison::class, $condition->left);
        $this->assertInstanceOf(Comparison::class, $condition->right);
    }

    public function testParseSelectWithOrCondition(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.name = :name OR u.email = :email');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(LogicalExpression::class, $condition);
        $this->assertSame('OR', $condition->operator);
    }

    public function testParseSelectWithJoin(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u JOIN u.posts p WHERE p.title = :title');

        $this->assertCount(1, $ast->joins);
        $join = $ast->joins[0];
        $this->assertInstanceOf(JoinClause::class, $join);
        $this->assertSame('JOIN', $join->joinType);
        $this->assertSame('u', $join->property->alias);
        $this->assertSame('posts', $join->property->property);
        $this->assertSame('p', $join->alias);
    }

    public function testParseSelectWithLeftJoin(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u LEFT JOIN u.posts p');

        $this->assertCount(1, $ast->joins);
        $this->assertSame('LEFT JOIN', $ast->joins[0]->joinType);
    }

    public function testParseSelectWithOrderBy(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u ORDER BY u.name ASC');

        $this->assertNotNull($ast->orderBy);
        $this->assertCount(1, $ast->orderBy->items);
        $this->assertSame('u', $ast->orderBy->items[0]->property->alias);
        $this->assertSame('name', $ast->orderBy->items[0]->property->property);
        $this->assertSame('ASC', $ast->orderBy->items[0]->direction);
    }

    public function testParseSelectWithOrderByDesc(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u ORDER BY u.age DESC');

        $this->assertSame('DESC', $ast->orderBy->items[0]->direction);
    }

    public function testParseSelectWithMultipleOrderBy(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u ORDER BY u.name ASC, u.age DESC');

        $this->assertCount(2, $ast->orderBy->items);
        $this->assertSame('name', $ast->orderBy->items[0]->property->property);
        $this->assertSame('ASC', $ast->orderBy->items[0]->direction);
        $this->assertSame('age', $ast->orderBy->items[1]->property->property);
        $this->assertSame('DESC', $ast->orderBy->items[1]->direction);
    }

    public function testParseSelectWithGroupBy(): void
    {
        $ast = $this->parser->parse('SELECT u.name FROM User u GROUP BY u.name');

        $this->assertNotNull($ast->groupBy);
        $this->assertCount(1, $ast->groupBy->properties);
        $this->assertSame('u', $ast->groupBy->properties[0]->alias);
        $this->assertSame('name', $ast->groupBy->properties[0]->property);
    }

    public function testParseComplexQuery(): void
    {
        $oql = 'SELECT u FROM User u JOIN u.posts p WHERE u.name = :name AND p.title LIKE :title ORDER BY u.name ASC';
        $ast = $this->parser->parse($oql);

        $this->assertCount(1, $ast->selectExpressions);
        $this->assertSame('User', $ast->from->entityName);
        $this->assertCount(1, $ast->joins);
        $this->assertNotNull($ast->where);
        $this->assertInstanceOf(LogicalExpression::class, $ast->where->condition);
        $this->assertNotNull($ast->orderBy);
    }

    public function testParseMultipleSelectExpressions(): void
    {
        $ast = $this->parser->parse('SELECT u.name, u.email FROM User u');

        $this->assertCount(2, $ast->selectExpressions);
        $this->assertSame('u.name', $ast->selectExpressions[0]->expression);
        $this->assertSame('u.email', $ast->selectExpressions[1]->expression);
    }

    public function testParseComparisonOperators(): void
    {
        $operators = ['=', '!=', '<', '>', '<=', '>='];

        foreach ($operators as $op) {
            $ast = $this->parser->parse("SELECT u FROM User u WHERE u.age {$op} :age");
            $this->assertInstanceOf(Comparison::class, $ast->where->condition);
            $this->assertSame($op, $ast->where->condition->operator, "Failed for operator: {$op}");
        }
    }

    public function testParseLikeOperator(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.name LIKE :pattern');

        $condition = $ast->where->condition;
        $this->assertSame('LIKE', $condition->operator);
    }

    public function testThrowsOnMissingSELECT(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('FROM User u');
    }

    public function testThrowsOnMissingFROM(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('SELECT u WHERE u.name = :name');
    }

    public function testThrowsOnEmptyQuery(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('');
    }
}
