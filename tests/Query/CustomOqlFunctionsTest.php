<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlToSqlTranslator;
use SybaseORM\Tests\Query\Fixtures\OqlUserEntity;

/**
 * Tests for custom OQL function registration in OqlParser and OqlToSqlTranslator.
 */
final class CustomOqlFunctionsTest extends TestCase
{
    // ── OqlParser ──────────────────────────────────────────────────

    public function testParserRecognizesRegisteredFunction(): void
    {
        $parser = new OqlParser();
        $parser->registerFunction('NEWID');

        $ast = $parser->parse('SELECT u FROM User u WHERE u.token = NEWID()');

        $this->assertNotNull($ast->where);
    }

    public function testParserParsesRegisteredFunctionAsOperand(): void
    {
        $parser = new OqlParser();
        $parser->registerFunction('GETDATE');

        $ast = $parser->parse('SELECT u FROM User u WHERE u.createdAt = GETDATE()');

        $this->assertNotNull($ast->where);
    }

    public function testParserParsesRegisteredFunctionInSetClause(): void
    {
        $parser = new OqlParser();
        $parser->registerFunction('GETDATE');

        $ast = $parser->parse('UPDATE User u SET u.updatedAt = GETDATE() WHERE u.id = :id');

        $this->assertCount(1, $ast->setClauses);
        $this->assertInstanceOf(CustomFunctionCall::class, $ast->setClauses[0]->value);
        $this->assertSame('GETDATE', $ast->setClauses[0]->value->functionName);
    }

    public function testParserBuiltinRandStillWorks(): void
    {
        $parser = new OqlParser();

        $ast = $parser->parse('SELECT u FROM User u WHERE u.score = RAND()');

        $this->assertNotNull($ast->where);
    }

    public function testParserBuiltinConvertStillWorks(): void
    {
        $parser = new OqlParser();

        $ast = $parser->parse('SELECT u FROM User u WHERE CONVERT(u.name AS VARCHAR) = :name');

        $this->assertNotNull($ast->where);
    }

    public function testParserRegisterFunctionIsCaseInsensitive(): void
    {
        $parser = new OqlParser();
        $parser->registerFunction('getdate');

        // Should parse even though registered as lowercase but used as uppercase
        $ast = $parser->parse('SELECT u FROM User u WHERE u.createdAt = GETDATE()');

        $this->assertNotNull($ast->where);
    }

    public function testParserDoesNotDuplicateRegisteredFunction(): void
    {
        $parser = new OqlParser();
        $parser->registerFunction('NEWID');
        $parser->registerFunction('NEWID');
        $parser->registerFunction('newid');

        // Should still parse fine — no duplicates cause issues
        $ast = $parser->parse('SELECT u FROM User u WHERE u.token = NEWID()');

        $this->assertNotNull($ast->where);
    }

    // ── OqlToSqlTranslator ─────────────────────────────────────────

    private function createTranslator(): OqlToSqlTranslator
    {
        MetadataReader::clearMemoryCache();
        $dialect = new SybaseDialect();
        $metadataReader = new MetadataReader();

        return new OqlToSqlTranslator($dialect, $metadataReader, [OqlUserEntity::class]);
    }

    public function testTranslatorResolvesRegisteredFunction(): void
    {
        $translator = $this->createTranslator();
        $translator->registerFunction('NEWID', 'NEWID()');

        $parser = new OqlParser();
        $parser->registerFunction('NEWID');

        $ast = $parser->parse('SELECT u FROM OqlUserEntity u WHERE u.name = NEWID()');
        $result = $translator->translate($ast);

        $this->assertStringContainsString('NEWID()', $result['sql']);
    }

    public function testTranslatorResolvesCustomFunctionWithDifferentSqlTemplate(): void
    {
        $translator = $this->createTranslator();
        $translator->registerFunction('RAND2', 'ABS(RAND())');

        $parser = new OqlParser();
        $parser->registerFunction('RAND2');

        $ast = $parser->parse('SELECT u FROM OqlUserEntity u WHERE u.age = RAND2()');
        $result = $translator->translate($ast);

        $this->assertStringContainsString('ABS(RAND())', $result['sql']);
    }

    public function testTranslatorBuiltinRandStillWorks(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse('SELECT u FROM OqlUserEntity u WHERE u.age = RAND()');
        $result = $translator->translate($ast);

        $this->assertStringContainsString('RAND()', $result['sql']);
    }

    public function testTranslatorBuiltinConvertStillWorks(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse('SELECT u FROM OqlUserEntity u WHERE CONVERT(u.name AS VARCHAR) = :name');
        $result = $translator->translate($ast);

        $this->assertStringContainsString('CONVERT(VARCHAR', $result['sql']);
    }

    // ── SET clause with CONVERT + nested custom function ───────────

    public function testConvertWithNestedCustomFunctionInSetClause(): void
    {
        $translator = $this->createTranslator();
        $translator->registerFunction('RAND2', 'RAND2()');

        $parser = new OqlParser();
        $parser->registerFunction('RAND2');

        $ast = $parser->parse('UPDATE OqlUserEntity u SET u.age = CONVERT(RAND2() AS REAL) WHERE u.id = :id');
        $result = $translator->translate($ast);

        $this->assertStringContainsString('CONVERT(REAL, RAND2())', $result['sql']);
        $this->assertStringContainsString('SET', $result['sql']);
        $this->assertContains('id', $result['parameters']);
    }

    public function testUpdateWithSetNullAndInClause(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse("UPDATE OqlUserEntity u SET u.name = NULL WHERE u.id IN (:ids)");
        $result = $translator->translate($ast);

        $this->assertStringContainsString('SET', $result['sql']);
        $this->assertStringContainsString('NULL', $result['sql']);
        $this->assertStringContainsString('IN (:ids)', $result['sql']);
        $this->assertContains('ids', $result['parameters']);
    }

    public function testUpdateWithMultipleSetAndInClause(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse("UPDATE OqlUserEntity u SET u.name = NULL, u.age = NULL WHERE u.id IN (:ids)");
        $result = $translator->translate($ast);

        $sql = $result['sql'];
        $this->assertStringContainsString('SET', $sql);
        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringContainsString('IN (:ids)', $sql);
        // Should have 2 SET clauses separated by comma
        $this->assertSame(1, substr_count($sql, 'SET'));
    }

    public function testDeleteWithInClause(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse("DELETE FROM OqlUserEntity u WHERE u.id IN (:ids)");
        $result = $translator->translate($ast);

        $this->assertStringContainsString('DELETE FROM', $result['sql']);
        $this->assertStringContainsString('IN (:ids)', $result['sql']);
        $this->assertContains('ids', $result['parameters']);
    }

    public function testUpdateWithLiteralComparisonAndInClause(): void
    {
        $translator = $this->createTranslator();

        $parser = new OqlParser();

        $ast = $parser->parse("UPDATE OqlUserEntity u SET u.name = NULL WHERE u.name = 'E' AND u.id IN (:ids)");
        $result = $translator->translate($ast);

        $sql = $result['sql'];
        $this->assertStringContainsString("'E'", $sql);
        $this->assertStringContainsString('IN (:ids)', $sql);
        $this->assertStringContainsString('AND', $sql);
    }
}
