<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\BetweenExpression;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\InExpression;
use SybaseORM\Query\AST\IsNullExpression;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\OqlParser;

/**
 * Tests for OqlParser covering all operators, FQCN entity names,
 * and edge cases introduced by the name-collision and NOT LIKE bug fixes.
 *
 * @covers \SybaseORM\Query\OqlParser
 */
final class OqlParserTest extends TestCase
{
    private OqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OqlParser();
    }

    // ── FQCN entity name (BUG-01 regression) ───────────────────────────────

    public function testParsesSimpleShortEntityName(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e');

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame('Producto', $ast->from->entityName);
        $this->assertSame('e', $ast->from->alias);
    }

    public function testParsesFqcnEntityName(): void
    {
        $ast = $this->parser->parse('SELECT e FROM App\Entity\Main\Producto e');

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame('App\Entity\Main\Producto', $ast->from->entityName);
        $this->assertSame('e', $ast->from->alias);
    }

    public function testParsesFqcnWithDeepNamespaceAndWhereClause(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM App\Entity\Historico\Producto e WHERE e.id = :id'
        );

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame('App\Entity\Historico\Producto', $ast->from->entityName);
        $this->assertNotNull($ast->where);
    }

    public function testParsesFqcnEntityWithMultipleConditions(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM App\Entity\Producto e WHERE e.activo = :activo AND e.precio > :precio'
        );

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame('App\Entity\Producto', $ast->from->entityName);
        $this->assertInstanceOf(LogicalExpression::class, $ast->where->condition);
    }

    // ── NOT LIKE (RISK-04 regression) ──────────────────────────────────────

    public function testParsesNotLikeOperator(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.nombre NOT LIKE :patron'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('NOT LIKE', $condition->operator);
        $this->assertInstanceOf(PropertyAccess::class, $condition->left);
        $this->assertSame('nombre', $condition->left->property);
        $this->assertInstanceOf(Parameter::class, $condition->right);
        $this->assertSame('patron', $condition->right->name);
    }

    public function testParsesLikeOperator(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e WHERE e.nombre LIKE :patron');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('LIKE', $condition->operator);
    }

    // ── IN / NOT IN ─────────────────────────────────────────────────────────

    public function testParsesInOperator(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e WHERE e.id IN (:ids)');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertFalse($condition->negated);
    }

    public function testParsesNotInOperator(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e WHERE e.id NOT IN (:ids)');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(InExpression::class, $condition);
        $this->assertTrue($condition->negated);
    }

    // ── IS NULL / IS NOT NULL ───────────────────────────────────────────────

    public function testParsesIsNull(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.deletedAt IS NULL'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(IsNullExpression::class, $condition);
        $this->assertFalse($condition->negated);
    }

    public function testParsesIsNotNull(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.deletedAt IS NOT NULL'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(IsNullExpression::class, $condition);
        $this->assertTrue($condition->negated);
    }

    // ── BETWEEN / NOT BETWEEN ───────────────────────────────────────────────

    public function testParsesBetween(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.precio BETWEEN :min AND :max'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(BetweenExpression::class, $condition);
        $this->assertFalse($condition->negated);
        $this->assertInstanceOf(Parameter::class, $condition->low);
        $this->assertSame('min', $condition->low->name);
        $this->assertInstanceOf(Parameter::class, $condition->high);
        $this->assertSame('max', $condition->high->name);
    }

    public function testParsesNotBetween(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.precio NOT BETWEEN :min AND :max'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(BetweenExpression::class, $condition);
        $this->assertTrue($condition->negated);
    }

    // ── Comparison operators ────────────────────────────────────────────────

    public function testParsesEqualityComparison(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e WHERE e.id = :id');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('=', $condition->operator);
    }

    /**
     * @dataProvider inequalityOperatorsProvider
     */
    public function testParsesInequalityOperators(string $op, string $expected): void
    {
        $ast = $this->parser->parse("SELECT e FROM Producto e WHERE e.precio {$op} :val");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame($expected, $condition->operator);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inequalityOperatorsProvider(): array
    {
        return [
            '!='  => ['!=',  '!='],
            '<>'  => ['<>',  '!='],  // normalized to != in parser
            '<'   => ['<',   '<'],
            '>'   => ['>',   '>'],
            '<='  => ['<=',  '<='],
            '>='  => ['>=',  '>='],
        ];
    }

    public function testParsesLiteralStringComparison(): void
    {
        $ast = $this->parser->parse("SELECT e FROM Producto e WHERE e.estado = 'activo'");

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(Literal::class, $condition->right);
        $this->assertSame('activo', $condition->right->value);
        $this->assertSame('string', $condition->right->type);
    }

    public function testParsesLiteralIntComparison(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e WHERE e.stock > 0');

        $condition = $ast->where->condition;
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertInstanceOf(Literal::class, $condition->right);
        $this->assertSame(0, $condition->right->value);
        // The parser uses 'integer' as the type tag for numeric literals
        $this->assertSame('integer', $condition->right->type);
    }

    // ── Logical combinations ────────────────────────────────────────────────

    public function testParsesAndCondition(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.activo = :a AND e.precio > :p'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(LogicalExpression::class, $condition);
        $this->assertSame('AND', $condition->operator);
    }

    public function testParsesOrCondition(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE e.tipo = :a OR e.tipo = :b'
        );

        $condition = $ast->where->condition;
        $this->assertInstanceOf(LogicalExpression::class, $condition);
        $this->assertSame('OR', $condition->operator);
    }

    // ── SELECT columns (uses $ast->selectExpressions per SelectStatement API) ──

    public function testParsesSelectSpecificColumns(): void
    {
        $ast = $this->parser->parse('SELECT e.id, e.nombre FROM Producto e');

        $this->assertIsArray($ast->selectExpressions);
        $this->assertCount(2, $ast->selectExpressions);
        // SelectExpression::$expression is a string for column references (e.g. 'e.id')
        $this->assertSame('e.id', $ast->selectExpressions[0]->expression);
        $this->assertSame('e.nombre', $ast->selectExpressions[1]->expression);
    }

    public function testParsesEntityAliasStar(): void
    {
        $ast = $this->parser->parse('SELECT e FROM Producto e');

        $this->assertIsArray($ast->selectExpressions);
        $this->assertCount(1, $ast->selectExpressions);
        // When SELECT uses entity alias alone (e.g. 'SELECT e'), expression is the alias string
        $this->assertSame('e', $ast->selectExpressions[0]->expression);
    }

    // ── ORDER BY ────────────────────────────────────────────────────────────

    public function testParsesOrderBy(): void
    {
        $ast = $this->parser->parse(
            'SELECT e FROM Producto e ORDER BY e.nombre ASC, e.precio DESC'
        );

        $this->assertNotNull($ast->orderBy);
        $this->assertCount(2, $ast->orderBy->items);
        $this->assertSame('nombre', $ast->orderBy->items[0]->property->property);
        $this->assertSame('ASC', $ast->orderBy->items[0]->direction);
        $this->assertSame('DESC', $ast->orderBy->items[1]->direction);
    }

    // ── GROUP BY / HAVING ($ast->havingClause per SelectStatement API) ──────

    public function testParsesGroupByAndHaving(): void
    {
        $ast = $this->parser->parse(
            'SELECT e.categoria FROM Producto e GROUP BY e.categoria HAVING e.categoria = :cat'
        );

        $this->assertNotNull($ast->groupBy);
        $this->assertCount(1, $ast->groupBy->properties);
        $this->assertNotNull($ast->havingClause);
    }

    // ── UPDATE (entityName/alias directly on UpdateStatement) ───────────────

    public function testParsesUpdateStatement(): void
    {
        $ast = $this->parser->parse(
            'UPDATE Producto e SET e.precio = :precio WHERE e.id = :id'
        );

        $this->assertInstanceOf(UpdateStatement::class, $ast);
        $this->assertSame('Producto', $ast->entityName);
        $this->assertSame('e', $ast->alias);
        $this->assertCount(1, $ast->setClauses);
        $this->assertNotNull($ast->where);
    }

    public function testParsesUpdateWithFqcn(): void
    {
        $ast = $this->parser->parse(
            'UPDATE App\Entity\Producto e SET e.activo = :val WHERE e.id = :id'
        );

        $this->assertInstanceOf(UpdateStatement::class, $ast);
        $this->assertSame('App\Entity\Producto', $ast->entityName);
    }

    // ── DELETE (entityName/alias directly on DeleteStatement) ───────────────

    public function testParsesDeleteStatement(): void
    {
        $ast = $this->parser->parse('DELETE FROM Producto e WHERE e.id = :id');

        $this->assertInstanceOf(DeleteStatement::class, $ast);
        $this->assertSame('Producto', $ast->entityName);
        $this->assertSame('e', $ast->alias);
        $this->assertNotNull($ast->where);
    }

    public function testParsesDeleteWithFqcn(): void
    {
        $ast = $this->parser->parse(
            'DELETE FROM App\Entity\Historico\Producto e WHERE e.activo = :val'
        );

        $this->assertInstanceOf(DeleteStatement::class, $ast);
        $this->assertSame('App\Entity\Historico\Producto', $ast->entityName);
    }

    // ── Error cases ─────────────────────────────────────────────────────────

    public function testThrowsOnInvalidOperator(): void
    {
        $this->expectException(OqlParseException::class);

        $this->parser->parse('SELECT e FROM Producto e WHERE e.id BADOP :id');
    }

    public function testThrowsOnMissingFromClause(): void
    {
        $this->expectException(OqlParseException::class);

        $this->parser->parse('SELECT e');
    }

    public function testThrowsOnUnterminatedInList(): void
    {
        $this->expectException(OqlParseException::class);

        $this->parser->parse('SELECT e FROM Producto e WHERE e.id IN (');
    }

    // ── Custom function registration ─────────────────────────────────────────

    public function testCustomFunctionIsRecognizedAfterRegistration(): void
    {
        $this->parser->registerFunction('MI_FUNCION');

        $ast = $this->parser->parse(
            'SELECT e FROM Producto e WHERE MI_FUNCION(e.id) = :val'
        );

        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertNotNull($ast->where);
    }
}
