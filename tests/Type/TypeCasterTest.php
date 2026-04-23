<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\TypeConversionException;
use SybaseORM\Tests\Type\Fixtures\IntPriority;
use SybaseORM\Tests\Type\Fixtures\InvalidCustomType;
use SybaseORM\Tests\Type\Fixtures\Money;
use SybaseORM\Tests\Type\Fixtures\MoneyType;
use SybaseORM\Tests\Type\Fixtures\StringStatus;
use SybaseORM\Type\TypeCaster;

class TypeCasterTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    // ── Null handling ───────────────────────────────────────────

    public function testToDatabaseValueReturnsNullForNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'bool'));
        $this->assertNull($this->caster->toDatabaseValue(null, 'datetime'));
        $this->assertNull($this->caster->toDatabaseValue(null, 'int'));
        $this->assertNull($this->caster->toDatabaseValue(null, 'string'));
    }

    public function testToPhpValueReturnsNullForNull(): void
    {
        $this->assertNull($this->caster->toPhpValue(null, 'bool'));
        $this->assertNull($this->caster->toPhpValue(null, 'datetime'));
        $this->assertNull($this->caster->toPhpValue(null, 'int'));
        $this->assertNull($this->caster->toPhpValue(null, 'string'));
    }

    // ── Bool ↔ BIT ──────────────────────────────────────────────

    public function testBoolTrueToDatabaseValue(): void
    {
        $this->assertSame(1, $this->caster->toDatabaseValue(true, 'bool'));
        $this->assertSame(1, $this->caster->toDatabaseValue(true, 'boolean'));
    }

    public function testBoolFalseToDatabaseValue(): void
    {
        $this->assertSame(0, $this->caster->toDatabaseValue(false, 'bool'));
    }

    public function testBoolToPhpValueFromInt(): void
    {
        $this->assertTrue($this->caster->toPhpValue(1, 'bool'));
        $this->assertFalse($this->caster->toPhpValue(0, 'bool'));
    }

    public function testBoolToPhpValueFromString(): void
    {
        $this->assertTrue($this->caster->toPhpValue('1', 'bool'));
        $this->assertFalse($this->caster->toPhpValue('0', 'bool'));
    }

    public function testBoolToPhpValueFromBool(): void
    {
        $this->assertTrue($this->caster->toPhpValue(true, 'boolean'));
        $this->assertFalse($this->caster->toPhpValue(false, 'boolean'));
    }

    public function testBoolToDatabaseValueThrowsForInvalidValue(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('not-a-bool', 'bool');
    }

    public function testBoolToPhpValueThrowsForInvalidValue(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue(5, 'bool');
    }

    // ── DateTime ↔ Sybase format ────────────────────────────────

    public function testDateTimeToDatabaseValue(): void
    {
        $dt = new \DateTime('2024-03-15 10:30:45.123');
        $result = $this->caster->toDatabaseValue($dt, 'datetime');
        $this->assertSame('2024-03-15 10:30:45.123', $result);
    }

    public function testDateTimeImmutableToDatabaseValue(): void
    {
        $dt = new \DateTimeImmutable('2024-01-01 00:00:00.000');
        $result = $this->caster->toDatabaseValue($dt, 'datetime');
        $this->assertSame('2024-01-01 00:00:00.000', $result);
    }

    public function testDateTimeToPhpValueFromSybaseFormat(): void
    {
        $result = $this->caster->toPhpValue('2024-03-15 10:30:45.123', 'datetime');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2024-03-15', $result->format('Y-m-d'));
        $this->assertSame('10:30:45', $result->format('H:i:s'));
    }

    public function testDateTimeToPhpValueFromStandardFormat(): void
    {
        $result = $this->caster->toPhpValue('2024-03-15 10:30:45', 'datetime');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2024-03-15', $result->format('Y-m-d'));
    }

    public function testDateTimeToPhpValueFromDateTimeInterface(): void
    {
        $dt = new \DateTime('2024-06-01 12:00:00');
        $result = $this->caster->toPhpValue($dt, 'datetime');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2024-06-01', $result->format('Y-m-d'));
    }

    public function testDateTimeToDatabaseValueThrowsForNonDateTime(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('not-a-date', 'datetime');
    }

    public function testDateTimeToPhpValueThrowsForInvalidString(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue('completely-invalid', 'datetime');
    }

    // ── Int ─────────────────────────────────────────────────────

    public function testIntToDatabaseValue(): void
    {
        $this->assertSame(42, $this->caster->toDatabaseValue(42, 'int'));
        $this->assertSame(42, $this->caster->toDatabaseValue(42, 'integer'));
    }

    public function testIntToDatabaseValueFromNumericString(): void
    {
        $this->assertSame(42, $this->caster->toDatabaseValue('42', 'int'));
    }

    public function testIntToPhpValue(): void
    {
        $this->assertSame(42, $this->caster->toPhpValue(42, 'int'));
        $this->assertSame(42, $this->caster->toPhpValue('42', 'integer'));
    }

    public function testIntToDatabaseValueThrowsForArray(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue([], 'int');
    }

    // ── Float ───────────────────────────────────────────────────

    public function testFloatToDatabaseValue(): void
    {
        $this->assertSame(3.14, $this->caster->toDatabaseValue(3.14, 'float'));
        $this->assertSame(3.14, $this->caster->toDatabaseValue(3.14, 'double'));
        $this->assertSame(3.14, $this->caster->toDatabaseValue(3.14, 'decimal'));
    }

    public function testFloatToDatabaseValueFromInt(): void
    {
        $this->assertSame(5.0, $this->caster->toDatabaseValue(5, 'float'));
    }

    public function testFloatToPhpValue(): void
    {
        $this->assertSame(3.14, $this->caster->toPhpValue(3.14, 'float'));
        $this->assertSame(3.14, $this->caster->toPhpValue('3.14', 'double'));
    }

    public function testFloatToDatabaseValueThrowsForArray(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue([], 'float');
    }

    // ── String ──────────────────────────────────────────────────

    public function testStringToDatabaseValue(): void
    {
        $this->assertSame('hello', $this->caster->toDatabaseValue('hello', 'string'));
        $this->assertSame('hello', $this->caster->toDatabaseValue('hello', 'varchar'));
        $this->assertSame('hello', $this->caster->toDatabaseValue('hello', 'text'));
    }

    public function testStringToDatabaseValueFromScalar(): void
    {
        $this->assertSame('42', $this->caster->toDatabaseValue(42, 'string'));
        $this->assertSame('1', $this->caster->toDatabaseValue(true, 'string'));
    }

    public function testStringToPhpValue(): void
    {
        $this->assertSame('hello', $this->caster->toPhpValue('hello', 'string'));
        $this->assertSame('42', $this->caster->toPhpValue(42, 'varchar'));
    }

    public function testStringToDatabaseValueThrowsForArray(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue([], 'string');
    }

    // ── Unsupported type ────────────────────────────────────────

    public function testToDatabaseValueThrowsForUnsupportedType(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('value', 'unknown_type');
    }

    public function testToPhpValueThrowsForUnsupportedType(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue('value', 'unknown_type');
    }

    // ── TypeConversionException details ─────────────────────────

    public function testExceptionContainsTypeDetails(): void
    {
        try {
            $this->caster->toDatabaseValue('bad', 'bool');
            $this->fail('Expected TypeConversionException');
        } catch (TypeConversionException $e) {
            $this->assertSame('string', $e->getSourceType());
            $this->assertSame('bool', $e->getTargetType());
            $this->assertSame('bad', $e->getProblematicValue());
        }
    }

    // ── registerType validation ────────────────────────────────

    public function testRegisterTypeRejectsClassNotImplementingInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->caster->registerType('invalid', InvalidCustomType::class);
    }

    // ── BackedEnum ↔ scalar ─────────────────────────────────────

    public function testStringEnumToDatabaseValue(): void
    {
        $result = $this->caster->toDatabaseValue(StringStatus::Active, StringStatus::class);
        $this->assertSame('active', $result);
    }

    public function testIntEnumToDatabaseValue(): void
    {
        $result = $this->caster->toDatabaseValue(IntPriority::High, IntPriority::class);
        $this->assertSame(3, $result);
    }

    public function testStringEnumToPhpValue(): void
    {
        $result = $this->caster->toPhpValue('inactive', StringStatus::class);
        $this->assertSame(StringStatus::Inactive, $result);
    }

    public function testIntEnumToPhpValue(): void
    {
        $result = $this->caster->toPhpValue(2, IntPriority::class);
        $this->assertSame(IntPriority::Medium, $result);
    }

    public function testEnumToDatabaseValueThrowsForNonEnumValue(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('not-an-enum', StringStatus::class);
    }

    public function testEnumToPhpValueThrowsForInvalidScalar(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue('nonexistent', StringStatus::class);
    }

    public function testEnumToPhpValueThrowsForNonScalar(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue([], StringStatus::class);
    }

    public function testEnumNullReturnsNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, StringStatus::class));
        $this->assertNull($this->caster->toPhpValue(null, StringStatus::class));
    }

    // ── Custom Types (Value Objects) ────────────────────────────

    public function testCustomTypeToDatabaseValue(): void
    {
        $this->caster->registerType('money', MoneyType::class);

        $money = Money::fromCents(1999);
        $result = $this->caster->toDatabaseValue($money, 'money');

        $this->assertSame(1999, $result);
    }

    public function testCustomTypeToPhpValue(): void
    {
        $this->caster->registerType('money', MoneyType::class);

        $result = $this->caster->toPhpValue(1999, 'money');

        $this->assertInstanceOf(Money::class, $result);
        $this->assertSame(1999, $result->getAmountInCents());
    }

    public function testCustomTypeThrowsTypeConversionExceptionOnFailure(): void
    {
        $this->caster->registerType('money', MoneyType::class);

        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('not-money', 'money');
    }

    public function testCustomTypeToPhpValueThrowsOnInvalidInput(): void
    {
        $this->caster->registerType('money', MoneyType::class);

        $this->expectException(TypeConversionException::class);
        $this->caster->toPhpValue('not-an-int', 'money');
    }

    public function testCustomTypeNullReturnsNull(): void
    {
        $this->caster->registerType('money', MoneyType::class);

        $this->assertNull($this->caster->toDatabaseValue(null, 'money'));
        $this->assertNull($this->caster->toPhpValue(null, 'money'));
    }

    // ── Custom type takes priority over unknown type ────────────

    public function testUnknownTypeStillThrows(): void
    {
        $this->expectException(TypeConversionException::class);
        $this->caster->toDatabaseValue('value', 'totally_unknown');
    }
}
