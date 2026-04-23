<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
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
use SybaseORM\Query\OqlToSqlTranslator;
use SybaseORM\Tests\Query\Fixtures\OqlPostEntity;
use SybaseORM\Tests\Query\Fixtures\OqlUserEntity;

/**
 * Unit tests for OqlToSqlTranslator.
 * Validates: Requirements 4.2, 4.4
 */
class OqlToSqlTranslatorTest extends TestCase
{
    private OqlToSqlTranslator $translator;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();

        $dialect = new SybaseDialect();
        $metadataReader = new MetadataReader();

        $this->translator = new OqlToSqlTranslator(
            $dialect,
            $metadataReader,
            [OqlUserEntity::class, OqlPostEntity::class],
        );
    }

    public function testTranslateSimpleSelect(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u]', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateSelectWithPropertyAccess(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.name'),
                new SelectExpression('u.email'),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].[name], [u].[email] FROM [users] [u]', $result['sql']);
    }

    public function testTranslateSelectWithWhereParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'name'),
                    '=',
                    new Parameter('name'),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[name] = :name', $result['sql']);
        $this->assertSame(['name'], $result['parameters']);
    }

    public function testTranslateSelectWithJoin(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            joins: [
                new JoinClause('JOIN', new PropertyAccess('u', 'posts'), 'p'),
            ],
        );

        $result = $this->translator->translate($ast);

        // OneToMany: owner.id = target.joinColumn
        $this->assertStringContainsString('JOIN [posts] [p] ON', $result['sql']);
        $this->assertStringContainsString('[u].[id]', $result['sql']);
    }

    public function testTranslateSelectWithWhereAndOrderBy(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new Comparison(
                    new PropertyAccess('u', 'age'),
                    '>',
                    new Parameter('minAge'),
                ),
            ),
            orderBy: new OrderByClause([
                new OrderByItem(new PropertyAccess('u', 'name'), 'ASC'),
            ]),
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('WHERE [u].[age] > :minAge', $result['sql']);
        $this->assertStringContainsString('ORDER BY [u].[name] ASC', $result['sql']);
        $this->assertSame(['minAge'], $result['parameters']);
    }

    public function testTranslateSelectWithLogicalExpression(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                    'AND',
                    new Comparison(new PropertyAccess('u', 'age'), '>', new Parameter('age')),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('[u].[name] = :name AND [u].[age] > :age', $result['sql']);
        $this->assertSame(['name', 'age'], $result['parameters']);
    }

    public function testParametersAreCollected(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new Comparison(new PropertyAccess('u', 'name'), 'LIKE', new Parameter('pattern')),
                    'OR',
                    new Comparison(new PropertyAccess('u', 'email'), '=', new Parameter('email')),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame(['pattern', 'email'], $result['parameters']);
    }

    public function testResolvesEntityNameToTableName(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        // Entity "OqlUserEntity" maps to table "users"
        $this->assertStringContainsString('[users]', $result['sql']);
    }

    public function testResolvesPropertyNameToColumnName(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u.name')],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        // Property "name" maps to column "name"
        $this->assertStringContainsString('[u].[name]', $result['sql']);
    }

    // --- Task 8.3: IS NULL / IS NOT NULL ---

    public function testTranslateIsNull(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new IsNullExpression(new PropertyAccess('u', 'email')),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[email] IS NULL', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateIsNotNull(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new IsNullExpression(new PropertyAccess('u', 'name'), negated: true),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[name] IS NOT NULL', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    // --- Task 8.3: IN / NOT IN ---

    public function testTranslateInWithParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'name'),
                    [new Parameter('names')],
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[name] IN (:names)', $result['sql']);
        $this->assertSame(['names'], $result['parameters']);
    }

    public function testTranslateNotInWithParameter(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'age'),
                    [new Parameter('ages')],
                    negated: true,
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[age] NOT IN (:ages)', $result['sql']);
        $this->assertSame(['ages'], $result['parameters']);
    }

    public function testTranslateInWithLiterals(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new InExpression(
                    new PropertyAccess('u', 'age'),
                    [
                        new Literal(18, 'integer'),
                        new Literal(25, 'integer'),
                        new Literal(30, 'integer'),
                    ],
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].* FROM [users] [u] WHERE [u].[age] IN (18, 25, 30)', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    // --- Task 8.3: Aggregate functions ---

    public function testTranslateCountFunction(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'id'))),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT COUNT([u].[id]) FROM [users] [u]', $result['sql']);
    }

    public function testTranslateCountStar(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', '*')),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT COUNT(*) FROM [users] [u]', $result['sql']);
    }

    public function testTranslateCountDistinct(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'name'), distinct: true)),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT COUNT(DISTINCT [u].[name]) FROM [users] [u]', $result['sql']);
    }

    public function testTranslateSumFunction(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(new FunctionCall('SUM', new PropertyAccess('u', 'age'))),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT SUM([u].[age]) FROM [users] [u]', $result['sql']);
    }

    // --- Task 8.3: HAVING ---

    public function testTranslateHavingClause(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.name'),
                new SelectExpression(new FunctionCall('COUNT', new PropertyAccess('u', 'id'))),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
            groupBy: new GroupByClause([new PropertyAccess('u', 'name')]),
            havingClause: new HavingClause(
                new Comparison(
                    new FunctionCall('COUNT', new PropertyAccess('u', 'id')),
                    '>',
                    new Literal(5, 'integer'),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('GROUP BY [u].[name]', $result['sql']);
        $this->assertStringContainsString('HAVING COUNT([u].[id]) > 5', $result['sql']);
    }

    // --- Task 8.3: Entity-based JOIN ---

    public function testTranslateEntityBasedJoin(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u'), new SelectExpression('p')],
            from: new FromClause('OqlUserEntity', 'u'),
            joins: [
                new JoinClause(
                    'JOIN',
                    new PropertyAccess('OqlPostEntity', ''),
                    'p',
                    entityName: 'OqlPostEntity',
                    withCondition: new Comparison(
                        new PropertyAccess('p', 'id'),
                        '=',
                        new PropertyAccess('u', 'id'),
                    ),
                ),
            ],
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('JOIN [posts] [p] ON', $result['sql']);
        $this->assertStringContainsString('[p].[id] = [u].[id]', $result['sql']);
    }

    public function testTranslateLeftJoinEntityBased(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            joins: [
                new JoinClause(
                    'LEFT JOIN',
                    new PropertyAccess('OqlPostEntity', ''),
                    'p',
                    entityName: 'OqlPostEntity',
                    withCondition: new Comparison(
                        new PropertyAccess('p', 'id'),
                        '=',
                        new PropertyAccess('u', 'id'),
                    ),
                ),
            ],
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('LEFT JOIN [posts] [p] ON', $result['sql']);
        $this->assertStringContainsString('[p].[id] = [u].[id]', $result['sql']);
    }

    // --- Task 8.3: SELECT * wildcard ---

    public function testTranslateSelectWildcard(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('*')],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT * FROM [users] [u]', $result['sql']);
    }

    // --- Task 8.3: SELECT with alias ---

    public function testTranslateSelectWithAlias(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression('u.name', alias: 'userName'),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT [u].[name] AS [userName] FROM [users] [u]', $result['sql']);
    }

    public function testTranslateFunctionCallWithAlias(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [
                new SelectExpression(
                    new FunctionCall('COUNT', new PropertyAccess('u', 'id')),
                    alias: 'userCount',
                ),
            ],
            from: new FromClause('OqlUserEntity', 'u'),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT COUNT([u].[id]) AS [userCount] FROM [users] [u]', $result['sql']);
    }

    // --- Task 8.3: SELECT DISTINCT ---

    public function testTranslateSelectDistinct(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u.name')],
            from: new FromClause('OqlUserEntity', 'u'),
            distinct: true,
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('SELECT DISTINCT [u].[name] FROM [users] [u]', $result['sql']);
    }

    // --- Task 8.3: Combined IS NULL in LogicalExpression ---

    public function testTranslateIsNullInLogicalExpression(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new IsNullExpression(new PropertyAccess('u', 'email')),
                    'OR',
                    new Comparison(new PropertyAccess('u', 'name'), '=', new Parameter('name')),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('[u].[email] IS NULL OR [u].[name] = :name', $result['sql']);
        $this->assertSame(['name'], $result['parameters']);
    }

    // --- Task 8.3: IN parameters are collected ---

    public function testInExpressionCollectsParameters(): void
    {
        $ast = new SelectStatement(
            selectExpressions: [new SelectExpression('u')],
            from: new FromClause('OqlUserEntity', 'u'),
            where: new WhereClause(
                new LogicalExpression(
                    new InExpression(
                        new PropertyAccess('u', 'name'),
                        [new Parameter('names')],
                    ),
                    'AND',
                    new Comparison(new PropertyAccess('u', 'age'), '>', new Parameter('minAge')),
                ),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame(['names', 'minAge'], $result['parameters']);
    }
}
