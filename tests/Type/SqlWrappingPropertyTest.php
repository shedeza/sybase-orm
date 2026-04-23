<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Type\CustomTypeInterface;
use SybaseORM\Type\SqlWrappingTypeInterface;
use SybaseORM\Type\TypeCaster;

/**
 * Property-based tests for SqlWrapping types and Dialect value expressions.
 * Validates: Properties 13–14
 *
 * Uses @dataProvider with 100+ iterations for thorough input coverage.
 */
final class SqlWrappingPropertyTest extends TestCase
{
    // ── Property 13: TypeCaster getDatabaseValueSQL ─────────────────

    /**
     * **Validates: Requirements 34.3**
     *
     * For all registered types, getDatabaseValueSQL returns wrapping iff the type
     * implements SqlWrappingTypeInterface. Otherwise returns the expression unchanged.
     *
     * @dataProvider typeCasterSqlProvider
     */
    public function testTypeCasterGetDatabaseValueSQL(string $typeName, string $typeClass, string $sqlExpr, bool $expectWrapped): void
    {
        $typeCaster = new TypeCaster();
        $typeCaster->registerType($typeName, $typeClass);

        $result = $typeCaster->getDatabaseValueSQL($sqlExpr, $typeName);

        if ($expectWrapped) {
            $this->assertNotSame($sqlExpr, $result, 'SqlWrapping type should modify the expression');
            $this->assertStringContainsString($sqlExpr, $result, 'Wrapped expression should contain original');
        } else {
            $this->assertSame($sqlExpr, $result, 'Non-wrapping type should return expression unchanged');
        }
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function typeCasterSqlProvider(): iterable
    {
        $expressions = ['?', 'col1', 'table.col', 'FUNC(?)'];

        $count = 0;
        foreach ($expressions as $expr) {
            // SqlWrapping type
            yield "wrapping-{$count}" => [
                'wrapping_type_' . $count,
                PropertyTestWrappingType::class,
                $expr,
                true,
            ];
            $count++;

            // Non-wrapping type
            yield "non-wrapping-{$count}" => [
                'simple_type_' . $count,
                PropertyTestSimpleType::class,
                $expr,
                false,
            ];
            $count++;
        }

        // Generate more cases with varied type names
        $typeNames = [];
        for ($i = 0; $i < 50; $i++) {
            $typeNames[] = 'type_' . $i;
        }

        foreach ($typeNames as $name) {
            yield "wrapping-bulk-{$count}" => [
                $name . '_w',
                PropertyTestWrappingType::class,
                '?',
                true,
            ];
            $count++;

            yield "non-wrapping-bulk-{$count}" => [
                $name . '_s',
                PropertyTestSimpleType::class,
                '?',
                false,
            ];
            $count++;
        }
    }

    // ── Property 14: Dialect value expressions ──────────────────────

    /**
     * **Validates: Requirements 34.4**
     *
     * For all column/expression combinations, the dialect correctly substitutes
     * value expressions where non-null and uses '?' where null.
     *
     * @dataProvider dialectValueExpressionProvider
     */
    public function testDialectValueExpressions(array $columns, array $valueExpressions, int $expectedWrappedCount): void
    {
        $dialect = new SybaseDialect();

        $values = array_fill(0, count($columns), '?');
        $sql = $dialect->generateInsert('test_table', $columns, $values, null, $valueExpressions);

        // Count how many wrapped expressions appear in the SQL
        $wrappedCount = 0;
        foreach ($valueExpressions as $expr) {
            if ($expr !== null && str_contains($sql, $expr)) {
                $wrappedCount++;
            }
        }

        $this->assertSame($expectedWrappedCount, $wrappedCount);

        // Verify all columns are present
        foreach ($columns as $col) {
            $this->assertStringContainsString('[' . $col . ']', $sql);
        }
    }

    /**
     * @return iterable<string, array{string[], array<?string>, int}>
     */
    public static function dialectValueExpressionProvider(): iterable
    {
        $count = 0;

        // All null expressions
        for ($numCols = 1; $numCols <= 5; $numCols++) {
            $columns = [];
            $expressions = [];
            for ($j = 0; $j < $numCols; $j++) {
                $columns[] = 'col' . $j;
                $expressions[] = null;
            }
            yield "all-null-{$count}" => [$columns, $expressions, 0];
            $count++;
        }

        // All wrapped expressions
        for ($numCols = 1; $numCols <= 5; $numCols++) {
            $columns = [];
            $expressions = [];
            for ($j = 0; $j < $numCols; $j++) {
                $columns[] = 'col' . $j;
                $expressions[] = 'CONVERT(REAL, ?)';
            }
            yield "all-wrapped-{$count}" => [$columns, $expressions, $numCols];
            $count++;
        }

        // Mixed expressions
        for ($numCols = 2; $numCols <= 6; $numCols++) {
            $columns = [];
            $expressions = [];
            $wrappedCount = 0;
            for ($j = 0; $j < $numCols; $j++) {
                $columns[] = 'col' . $j;
                if ($j % 2 === 0) {
                    $expressions[] = 'CONVERT(INT, ?)';
                    $wrappedCount++;
                } else {
                    $expressions[] = null;
                }
            }
            yield "mixed-{$count}" => [$columns, $expressions, $wrappedCount];
            $count++;
        }

        // Generate bulk cases
        for ($i = 0; $i < 90; $i++) {
            $numCols = ($i % 5) + 1;
            $columns = [];
            $expressions = [];
            $wrappedCount = 0;
            for ($j = 0; $j < $numCols; $j++) {
                $columns[] = 'field_' . $i . '_' . $j;
                if (($i + $j) % 3 === 0) {
                    $expressions[] = 'FUNC_' . $i . '(?)';
                    $wrappedCount++;
                } else {
                    $expressions[] = null;
                }
            }
            yield "bulk-{$count}" => [$columns, $expressions, $wrappedCount];
            $count++;
        }
    }
}

/**
 * Test fixture: SqlWrapping type for property tests
 */
final class PropertyTestWrappingType implements SqlWrappingTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        return $value;
    }

    public function toPhpValue(mixed $value): mixed
    {
        return $value;
    }

    public function convertToDatabaseValueSQL(string $sqlExpr): string
    {
        return 'WRAPPED(' . $sqlExpr . ')';
    }
}

/**
 * Test fixture: simple custom type for property tests (no SQL wrapping)
 */
final class PropertyTestSimpleType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        return $value;
    }

    public function toPhpValue(mixed $value): mixed
    {
        return $value;
    }
}
