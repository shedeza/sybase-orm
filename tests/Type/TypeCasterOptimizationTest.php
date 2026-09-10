<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\TypeCaster;

enum TestPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}

final class TypeCasterOptimizationTest extends TestCase
{
    private TypeCaster $typeCaster;

    protected function setUp(): void
    {
        $this->typeCaster = new TypeCaster();
    }

    public function testBuiltinTypesIncludeDateAndTime(): void
    {
        $this->assertTrue($this->typeCaster->isBuiltinType('bool'));
        $this->assertTrue($this->typeCaster->isBuiltinType('datetime'));
        $this->assertTrue($this->typeCaster->isBuiltinType('date'));
        $this->assertTrue($this->typeCaster->isBuiltinType('time'));
        $this->assertTrue($this->typeCaster->isBuiltinType('int'));
        $this->assertTrue($this->typeCaster->isBuiltinType('varchar'));
        $this->assertFalse($this->typeCaster->isBuiltinType('non_existent_type'));
    }

    public function testDateTimeToPhpValueWithDateOnlyAndFormat(): void
    {
        $dateStr = '2025-06-15';
        $dt = $this->typeCaster->toPhpValue($dateStr, 'datetime');

        $this->assertInstanceOf(\DateTime::class, $dt);
        $this->assertSame('2025-06-15', $dt->format('Y-m-d'));
    }

    public function testDateTimeToPhpValueWithTimeOnly(): void
    {
        $timeStr = '14:30:45';
        $dt = $this->typeCaster->toPhpValue($timeStr, 'datetime');

        $this->assertInstanceOf(\DateTime::class, $dt);
        $this->assertSame('14:30:45', $dt->format('H:i:s'));
    }

    public function testBackedEnumCachedConversion(): void
    {
        $dbVal = $this->typeCaster->toDatabaseValue(TestPriority::HIGH, TestPriority::class);
        $this->assertSame('high', $dbVal);

        $phpVal = $this->typeCaster->toPhpValue('medium', TestPriority::class);
        $this->assertSame(TestPriority::MEDIUM, $phpVal);
    }
}
