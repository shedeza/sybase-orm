<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\CustomTypeInterface;
use SybaseORM\Type\SqlWrappingTypeInterface;
use SybaseORM\Type\TypeCaster;

/**
 * Unit tests for SqlWrappingTypeInterface and TypeCaster::getDatabaseValueSQL().
 * Validates: Requirements 34.1–34.4
 */
final class SqlWrappingTypeTest extends TestCase
{
    public function testGetDatabaseValueSQLWithWrappingType(): void
    {
        $typeCaster = new TypeCaster();
        $typeCaster->registerType('real_convert', RealConvertType::class);

        $result = $typeCaster->getDatabaseValueSQL('?', 'real_convert');

        $this->assertSame('CONVERT(REAL, ?)', $result);
    }

    public function testGetDatabaseValueSQLWithNonWrappingType(): void
    {
        $typeCaster = new TypeCaster();
        $typeCaster->registerType('simple_type', SimpleCustomType::class);

        $result = $typeCaster->getDatabaseValueSQL('?', 'simple_type');

        $this->assertSame('?', $result);
    }

    public function testGetDatabaseValueSQLWithBuiltinType(): void
    {
        $typeCaster = new TypeCaster();

        $result = $typeCaster->getDatabaseValueSQL('?', 'string');

        $this->assertSame('?', $result);
    }

    public function testGetDatabaseValueSQLWithUnknownType(): void
    {
        $typeCaster = new TypeCaster();

        $result = $typeCaster->getDatabaseValueSQL('?', 'unknown_type');

        $this->assertSame('?', $result);
    }

    public function testGetDatabaseValueSQLWithCustomExpression(): void
    {
        $typeCaster = new TypeCaster();
        $typeCaster->registerType('real_convert', RealConvertType::class);

        $result = $typeCaster->getDatabaseValueSQL('column_name', 'real_convert');

        $this->assertSame('CONVERT(REAL, column_name)', $result);
    }

    public function testSqlWrappingTypeInterfaceExtendsCustomType(): void
    {
        $type = new RealConvertType();

        $this->assertInstanceOf(CustomTypeInterface::class, $type);
        $this->assertInstanceOf(SqlWrappingTypeInterface::class, $type);
    }
}

/**
 * Test fixture: a SqlWrapping type that wraps with CONVERT(REAL, ?)
 */
final class RealConvertType implements SqlWrappingTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        return (float) $value;
    }

    public function toPhpValue(mixed $value): mixed
    {
        return (float) $value;
    }

    public function convertToDatabaseValueSQL(string $sqlExpr): string
    {
        return 'CONVERT(REAL, ' . $sqlExpr . ')';
    }
}

/**
 * Test fixture: a simple custom type without SQL wrapping
 */
final class SimpleCustomType implements CustomTypeInterface
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
