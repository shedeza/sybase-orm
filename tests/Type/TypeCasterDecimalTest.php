<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\TypeCaster;

/**
 * Tests for DECIMAL precision-preserving type conversion.
 */
final class TypeCasterDecimalTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    public function testDecimalToDatabasePreservesStringPrecision(): void
    {
        $result = $this->caster->toDatabaseValue('99999999999999.9999', 'decimal');

        $this->assertIsString($result);
        $this->assertSame('99999999999999.9999', $result);
    }

    public function testDecimalToPhpReturnsString(): void
    {
        $result = $this->caster->toPhpValue('123.456', 'decimal');

        $this->assertIsString($result);
        $this->assertSame('123.456', $result);
    }

    public function testDecimalFromIntPreservesValue(): void
    {
        $result = $this->caster->toDatabaseValue(42, 'decimal');

        $this->assertIsString($result);
        $this->assertSame('42', $result);
    }

    public function testNumericBehavesLikeDecimal(): void
    {
        $result = $this->caster->toDatabaseValue('100.50', 'numeric');

        $this->assertIsString($result);
        $this->assertSame('100.50', $result);
    }

    public function testDecimalNullPassesThrough(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'decimal'));
        $this->assertNull($this->caster->toPhpValue(null, 'decimal'));
    }
}
