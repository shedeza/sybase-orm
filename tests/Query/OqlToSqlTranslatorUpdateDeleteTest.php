<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SetClause;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\AST\WhereClause;
use SybaseORM\Query\OqlToSqlTranslator;
use SybaseORM\Tests\Query\Fixtures\OqlUserEntity;
use SybaseORM\Tests\Query\Fixtures\OqlPostEntity;

/**
 * Unit tests for OqlToSqlTranslator UPDATE, DELETE, and CustomFunctionCall translation.
 * Validates: Requirements 29.5, 30.2, 32.2
 */
final class OqlToSqlTranslatorUpdateDeleteTest extends TestCase
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

    // ── UPDATE translation ──────────────────────────────────────────

    public function testTranslateSimpleUpdate(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'name'), new Parameter('name'))],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[name] = :name', $result['sql']);
        $this->assertSame(['name'], $result['parameters']);
    }

    public function testTranslateUpdateWithMultipleSets(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [
                new SetClause(new PropertyAccess('u', 'name'), new Parameter('name')),
                new SetClause(new PropertyAccess('u', 'email'), new Parameter('email')),
            ],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[name] = :name, [u].[email] = :email', $result['sql']);
        $this->assertSame(['name', 'email'], $result['parameters']);
    }

    public function testTranslateUpdateWithWhere(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'name'), new Parameter('name'))],
            new WhereClause(
                new Comparison(new PropertyAccess('u', 'id'), '=', new Parameter('id')),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[name] = :name WHERE [u].[id] = :id', $result['sql']);
        // SET params first, then WHERE params
        $this->assertSame(['name', 'id'], $result['parameters']);
    }

    public function testTranslateUpdateWithNullValue(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'email'), new Literal('NULL', 'null'))],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[email] = NULL', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateUpdateWithStringLiteral(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'name'), new Literal('John', 'string'))],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame("UPDATE [users] SET [u].[name] = 'John'", $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateUpdateWithNumericLiteral(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'age'), new Literal(25, 'integer'))],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[age] = 25', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateUpdateParameterOrdering(): void
    {
        // SET params should come before WHERE params
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [
                new SetClause(new PropertyAccess('u', 'name'), new Parameter('newName')),
                new SetClause(new PropertyAccess('u', 'email'), new Parameter('newEmail')),
            ],
            new WhereClause(
                new Comparison(new PropertyAccess('u', 'id'), '=', new Parameter('userId')),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame(['newName', 'newEmail', 'userId'], $result['parameters']);
    }

    // ── DELETE translation ──────────────────────────────────────────

    public function testTranslateSimpleDelete(): void
    {
        $ast = new DeleteStatement('OqlUserEntity', 'u');

        $result = $this->translator->translate($ast);

        $this->assertSame('DELETE FROM [users]', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateDeleteWithWhere(): void
    {
        $ast = new DeleteStatement(
            'OqlUserEntity',
            'u',
            new WhereClause(
                new Comparison(new PropertyAccess('u', 'id'), '=', new Parameter('id')),
            ),
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('DELETE FROM [users] WHERE [u].[id] = :id', $result['sql']);
        $this->assertSame(['id'], $result['parameters']);
    }

    // ── CustomFunctionCall translation ──────────────────────────────

    public function testTranslateUpdateWithRand(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'age'), new CustomFunctionCall('RAND'))],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[age] = RAND()', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateUpdateWithConvert(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(
                new PropertyAccess('u', 'age'),
                new CustomFunctionCall('CONVERT', [new Parameter('value')], 'REAL'),
            )],
        );

        $result = $this->translator->translate($ast);

        // Sybase SQL uses CONVERT(type, expr) not CONVERT(expr AS type)
        $this->assertSame('UPDATE [users] SET [u].[age] = CONVERT(REAL, :value)', $result['sql']);
        $this->assertSame(['value'], $result['parameters']);
    }

    public function testTranslateUpdateWithNestedConvertRand(): void
    {
        $inner = new CustomFunctionCall('RAND');
        $outer = new CustomFunctionCall('CONVERT', [$inner], 'REAL');

        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(new PropertyAccess('u', 'age'), $outer)],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[age] = CONVERT(REAL, RAND())', $result['sql']);
        $this->assertEmpty($result['parameters']);
    }

    public function testTranslateConvertWithPropertyAccess(): void
    {
        $ast = new UpdateStatement(
            'OqlUserEntity',
            'u',
            [new SetClause(
                new PropertyAccess('u', 'age'),
                new CustomFunctionCall('CONVERT', [new PropertyAccess('u', 'name')], 'INT'),
            )],
        );

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[age] = CONVERT(INT, [u].[name])', $result['sql']);
    }

    // ── Full pipeline test (parse → translate) ──────────────────────

    public function testFullPipelineUpdate(): void
    {
        $parser = new \SybaseORM\Query\OqlParser();
        $ast = $parser->parse('UPDATE OqlUserEntity u SET u.name = :name WHERE u.id = :id');

        $result = $this->translator->translate($ast);

        $this->assertSame('UPDATE [users] SET [u].[name] = :name WHERE [u].[id] = :id', $result['sql']);
        $this->assertSame(['name', 'id'], $result['parameters']);
    }

    public function testFullPipelineDelete(): void
    {
        $parser = new \SybaseORM\Query\OqlParser();
        $ast = $parser->parse('DELETE FROM OqlUserEntity u WHERE u.id = :id');

        $result = $this->translator->translate($ast);

        $this->assertSame('DELETE FROM [users] WHERE [u].[id] = :id', $result['sql']);
        $this->assertSame(['id'], $result['parameters']);
    }
}
