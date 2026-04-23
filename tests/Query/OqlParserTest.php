<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\FunctionCall;
use SybaseORM\Query\AST\HavingClause;
use SybaseORM\Query\AST\InExpression;
use SybaseORM\Query\AST\IsNullExpression;
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
 * Validates: Requirements 4.1, 4.3, 5.1, 5.2, 6.1, 6.2, 6.3, 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.4, 9.1, 9.2
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

    // ── IS NULL / IS NOT NULL (Requirements 5.1, 5.2) ──────────────

    public function testParseIsNull(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.deletedAt IS NULL');

        $this->assertNotNull($ast->where);
        $condition = $ast->where->condition;
        $this->assertInstanceOf(IsNullExpression::class, $condition);
        $this->assertSame('u', $condition->property->alias);
        $this->assertSame('deletedAt', $condition->property->property);
        $this->assertFalse($condition->negated);
    }

    public function testParseIsNotNull(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.email IS NOT NULL');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(IsNullExpression::class, $condition);
        $this->assertSame('u', $condition->property->alias);
        $this->assertSame('email', $condition->property->property);
        $this->assertTrue($condition->negated);
    }

    public function testParseIsNullCombinedWithAnd(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.name = :name AND u.deletedAt IS NULL');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(LogicalExpression::class, $condition);
        $this->assertSame('AND', $condition->operator);
        $this->assertInstanceOf(Comparison::class, $condition->left);
        $this->assertInstanceOf(IsNullExpression::class, $condition->right);
        $this->assertFalse($condition->right->negated);
    }

    public function testThrowsOnMalformedIsNull(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('SELECT u FROM User u WHERE u.name IS EMPTY');
    }

    // ── IN / NOT IN (Requirements 6.1, 6.2, 6.3) ───────────────────

    public function testParseInWithParameter(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.status IN (:statuses)');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertSame('u', $condition->property->alias);
        $this->assertSame('status', $condition->property->property);
        $this->assertFalse($condition->negated);
        $this->assertCount(1, $condition->values);
        $this->assertInstanceOf(Parameter::class, $condition->values[0]);
        $this->assertSame('statuses', $condition->values[0]->name);
    }

    public function testParseNotIn(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.role NOT IN (:excluded)');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertTrue($condition->negated);
        $this->assertCount(1, $condition->values);
        $this->assertInstanceOf(Parameter::class, $condition->values[0]);
        $this->assertSame('excluded', $condition->values[0]->name);
    }

    public function testParseInWithLiterals(): void
    {
        $ast = $this->parser->parse("SELECT u FROM User u WHERE u.id IN (1, 2, 3)");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertFalse($condition->negated);
        $this->assertCount(3, $condition->values);

        $this->assertInstanceOf(Literal::class, $condition->values[0]);
        $this->assertSame(1, $condition->values[0]->value);
        $this->assertSame('integer', $condition->values[0]->type);

        $this->assertInstanceOf(Literal::class, $condition->values[1]);
        $this->assertSame(2, $condition->values[1]->value);

        $this->assertInstanceOf(Literal::class, $condition->values[2]);
        $this->assertSame(3, $condition->values[2]->value);
    }

    public function testParseInWithStringLiterals(): void
    {
        $ast = $this->parser->parse("SELECT u FROM User u WHERE u.status IN ('active', 'pending')");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertCount(2, $condition->values);

        $this->assertInstanceOf(Literal::class, $condition->values[0]);
        $this->assertSame('active', $condition->values[0]->value);
        $this->assertSame('string', $condition->values[0]->type);

        $this->assertInstanceOf(Literal::class, $condition->values[1]);
        $this->assertSame('pending', $condition->values[1]->value);
    }

    public function testThrowsOnUnclosedIn(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('SELECT u FROM User u WHERE u.id IN (1, 2');
    }

    // ── Aggregate Functions (Requirements 7.1, 7.2, 7.3) ───────────

    public function testParseCount(): void
    {
        $ast = $this->parser->parse('SELECT COUNT(u.id) FROM User u');

        $this->assertCount(1, $ast->selectExpressions);
        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('COUNT', $expr->functionName);
        $this->assertInstanceOf(PropertyAccess::class, $expr->argument);
        $this->assertSame('u', $expr->argument->alias);
        $this->assertSame('id', $expr->argument->property);
        $this->assertFalse($expr->distinct);
    }

    public function testParseCountStar(): void
    {
        $ast = $this->parser->parse('SELECT COUNT(*) FROM User u');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('COUNT', $expr->functionName);
        $this->assertSame('*', $expr->argument);
        $this->assertFalse($expr->distinct);
    }

    public function testParseCountDistinct(): void
    {
        $ast = $this->parser->parse('SELECT COUNT(DISTINCT u.department) FROM User u');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('COUNT', $expr->functionName);
        $this->assertTrue($expr->distinct);
        $this->assertInstanceOf(PropertyAccess::class, $expr->argument);
        $this->assertSame('department', $expr->argument->property);
    }

    public function testParseSum(): void
    {
        $ast = $this->parser->parse('SELECT SUM(o.amount) FROM Order o');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('SUM', $expr->functionName);
        $this->assertInstanceOf(PropertyAccess::class, $expr->argument);
        $this->assertSame('amount', $expr->argument->property);
    }

    public function testParseAvg(): void
    {
        $ast = $this->parser->parse('SELECT AVG(o.price) FROM Order o');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('AVG', $expr->functionName);
    }

    public function testParseMin(): void
    {
        $ast = $this->parser->parse('SELECT MIN(o.createdAt) FROM Order o');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('MIN', $expr->functionName);
    }

    public function testParseMax(): void
    {
        $ast = $this->parser->parse('SELECT MAX(o.total) FROM Order o');

        $expr = $ast->selectExpressions[0]->expression;
        $this->assertInstanceOf(FunctionCall::class, $expr);
        $this->assertSame('MAX', $expr->functionName);
    }

    // ── HAVING (Requirement 7.4) ────────────────────────────────────

    public function testParseHaving(): void
    {
        $ast = $this->parser->parse('SELECT u.department, COUNT(u.id) FROM User u GROUP BY u.department HAVING COUNT(u.id) > 5');

        $this->assertNotNull($ast->groupBy);
        $this->assertNotNull($ast->havingClause);
        $this->assertInstanceOf(HavingClause::class, $ast->havingClause);

        $condition = $ast->havingClause->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(FunctionCall::class, $condition->left);
        $this->assertSame('COUNT', $condition->left->functionName);
        $this->assertSame('>', $condition->operator);
        $this->assertInstanceOf(Literal::class, $condition->right);
        $this->assertSame(5, $condition->right->value);
    }

    // ── Entity-based JOIN WITH (Requirements 8.1, 8.2, 8.4) ────────

    public function testParseEntityJoinWith(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u JOIN Address a WITH a.userId = u.id');

        $this->assertCount(1, $ast->joins);
        $join = $ast->joins[0];
        $this->assertInstanceOf(JoinClause::class, $join);
        $this->assertSame('JOIN', $join->joinType);
        $this->assertSame('Address', $join->entityName);
        $this->assertSame('a', $join->alias);
        $this->assertNotNull($join->withCondition);
        $this->assertInstanceOf(Comparison::class, $join->withCondition);
        $this->assertSame('=', $join->withCondition->operator);
    }

    public function testParseLeftJoinEntityWith(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u LEFT JOIN Profile p WITH p.userId = u.id');

        $this->assertCount(1, $ast->joins);
        $join = $ast->joins[0];
        $this->assertSame('LEFT JOIN', $join->joinType);
        $this->assertSame('Profile', $join->entityName);
        $this->assertSame('p', $join->alias);
        $this->assertNotNull($join->withCondition);
    }

    public function testExistingRelationshipJoinStillWorks(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u JOIN u.posts p WHERE p.title = :title');

        $this->assertCount(1, $ast->joins);
        $join = $ast->joins[0];
        $this->assertSame('JOIN', $join->joinType);
        $this->assertSame('u', $join->property->alias);
        $this->assertSame('posts', $join->property->property);
        $this->assertSame('p', $join->alias);
        $this->assertNull($join->entityName);
        $this->assertNull($join->withCondition);
    }

    public function testExistingLeftJoinStillWorks(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u LEFT JOIN u.comments c');

        $this->assertCount(1, $ast->joins);
        $join = $ast->joins[0];
        $this->assertSame('LEFT JOIN', $join->joinType);
        $this->assertSame('u', $join->property->alias);
        $this->assertSame('comments', $join->property->property);
        $this->assertNull($join->entityName);
        $this->assertNull($join->withCondition);
    }

    // ── SELECT * and SELECT DISTINCT (Requirement 9.1) ──────────────

    public function testParseSelectWildcard(): void
    {
        $ast = $this->parser->parse('SELECT * FROM User u');

        $this->assertCount(1, $ast->selectExpressions);
        $this->assertSame('*', $ast->selectExpressions[0]->expression);
    }

    public function testParseSelectDistinct(): void
    {
        $ast = $this->parser->parse('SELECT DISTINCT u.name FROM User u');

        $this->assertTrue($ast->distinct);
        $this->assertCount(1, $ast->selectExpressions);
        $this->assertSame('u.name', $ast->selectExpressions[0]->expression);
    }

    public function testParseSelectNotDistinctByDefault(): void
    {
        $ast = $this->parser->parse('SELECT u FROM User u');

        $this->assertFalse($ast->distinct);
    }

    // ── Column Aliases (Requirement 9.2) ────────────────────────────

    public function testParseColumnAlias(): void
    {
        $ast = $this->parser->parse('SELECT u.name AS userName FROM User u');

        $this->assertCount(1, $ast->selectExpressions);
        $this->assertSame('u.name', $ast->selectExpressions[0]->expression);
        $this->assertSame('userName', $ast->selectExpressions[0]->alias);
    }

    public function testParseFunctionCallWithAlias(): void
    {
        $ast = $this->parser->parse('SELECT COUNT(u.id) AS total FROM User u');

        $this->assertCount(1, $ast->selectExpressions);
        $expr = $ast->selectExpressions[0];
        $this->assertInstanceOf(FunctionCall::class, $expr->expression);
        $this->assertSame('COUNT', $expr->expression->functionName);
        $this->assertSame('total', $expr->alias);
    }

    public function testParseMultipleExpressionsWithAliases(): void
    {
        $ast = $this->parser->parse('SELECT u.department AS dept, COUNT(u.id) AS cnt FROM User u GROUP BY u.department');

        $this->assertCount(2, $ast->selectExpressions);
        $this->assertSame('u.department', $ast->selectExpressions[0]->expression);
        $this->assertSame('dept', $ast->selectExpressions[0]->alias);
        $this->assertInstanceOf(FunctionCall::class, $ast->selectExpressions[1]->expression);
        $this->assertSame('cnt', $ast->selectExpressions[1]->alias);
    }

    public function testParseExpressionWithoutAlias(): void
    {
        $ast = $this->parser->parse('SELECT u.name FROM User u');

        $this->assertNull($ast->selectExpressions[0]->alias);
    }

    // ── Error Cases ─────────────────────────────────────────────────

    public function testThrowsOnIsFollowedByInvalidToken(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('SELECT u FROM User u WHERE u.name IS VALID');
    }

    public function testThrowsOnInWithoutOpenParen(): void
    {
        $this->expectException(OqlParseException::class);
        $this->parser->parse('SELECT u FROM User u WHERE u.id IN 1, 2, 3');
    }
}
