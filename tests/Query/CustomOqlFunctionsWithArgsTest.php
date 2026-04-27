<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlToSqlTranslator;

/**
 * Tests for custom OQL functions with arguments.
 */
final class CustomOqlFunctionsWithArgsTest extends TestCase
{
    private OqlParser $parser;
    private OqlToSqlTranslator $translator;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();

        $metadata = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string'),
                new ColumnMetadata(propertyName: 'createdAt', columnName: 'created_at', type: 'datetime'),
            ],
            idFields: ['id'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($metadata);

        $this->translator = new OqlToSqlTranslator(
            new SybaseDialect(),
            $metadataReader,
            ['App\\Entity\\User'],
            ['User' => 'App\\Entity\\User'],
        );
    }

    public function testParseCustomFunctionWithTwoArgs(): void
    {
        $this->parser->registerFunction('DATEDIFF_DAYS');
        $this->translator->registerFunction('DATEDIFF_DAYS', 'DATEDIFF(day, ?, ?)');

        $ast = $this->parser->parse('SELECT u FROM User u WHERE DATEDIFF_DAYS(u.createdAt, u.createdAt) > :days');

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('DATEDIFF(day,', $result['sql']);
        $this->assertContains('days', $result['parameters']);
    }

    public function testParseCustomFunctionWithPropertyArgs(): void
    {
        $this->parser->registerFunction('MY_FUNC');
        $this->translator->registerFunction('MY_FUNC', 'MY_FUNC(?, ?)');

        $ast = $this->parser->parse('SELECT u FROM User u WHERE MY_FUNC(u.name, u.id) = :val');

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('MY_FUNC(', $result['sql']);
        $this->assertStringContainsString('[name]', $result['sql']);
    }

    public function testParseCustomFunctionWithLiteralArgs(): void
    {
        $this->parser->registerFunction('ADD_DAYS');
        $this->translator->registerFunction('ADD_DAYS', 'DATEADD(day, ?, ?)');

        $ast = $this->parser->parse('SELECT u FROM User u WHERE ADD_DAYS(30, u.createdAt) > :cutoff');

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('DATEADD(day, 30,', $result['sql']);
    }

    public function testParseCustomFunctionWithParameterArgs(): void
    {
        $this->parser->registerFunction('SUBSTR');
        $this->translator->registerFunction('SUBSTR', 'SUBSTRING(?, ?, ?)');

        $ast = $this->parser->parse('SELECT u FROM User u WHERE SUBSTR(u.name, :start, :len) = :val');

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('SUBSTRING(', $result['sql']);
        $this->assertContains('start', $result['parameters']);
        $this->assertContains('len', $result['parameters']);
        $this->assertContains('val', $result['parameters']);
    }

    public function testNoArgFunctionStillWorks(): void
    {
        $this->parser->registerFunction('RAND');
        $this->translator->registerFunction('RAND', 'RAND()');

        $ast = $this->parser->parse('SELECT u FROM User u WHERE u.id = RAND()');

        $result = $this->translator->translate($ast);

        $this->assertStringContainsString('RAND()', $result['sql']);
    }
}
