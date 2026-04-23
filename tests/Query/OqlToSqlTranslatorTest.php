<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\JoinClause;
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
}
