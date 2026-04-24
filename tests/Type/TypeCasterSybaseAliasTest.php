<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\TypeCaster;

/**
 * Tests for Sybase ASE type aliases: real, tinyint, smallint, bigint.
 */
final class TypeCasterSybaseAliasTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    // ── 'real' type alias (maps to float) ───────────────────────────

    public function testRealToDatabaseValueFromFloat(): void
    {
        $this->assertSame(3.14, $this->caster->toDatabaseValue(3.14, 'real'));
    }

    public function testRealToDatabaseValueFromInt(): void
    {
        $this->assertSame(5.0, $this->caster->toDatabaseValue(5, 'real'));
    }

    public function testRealToDatabaseValueFromNumericString(): void
    {
        $this->assertSame(2.5, $this->caster->toDatabaseValue('2.5', 'real'));
    }

    public function testRealToPhpValueFromFloat(): void
    {
        $this->assertSame(3.14, $this->caster->toPhpValue(3.14, 'real'));
    }

    public function testRealToPhpValueFromInt(): void
    {
        $this->assertSame(5.0, $this->caster->toPhpValue(5, 'real'));
    }

    public function testRealToPhpValueFromNumericString(): void
    {
        $this->assertSame(2.5, $this->caster->toPhpValue('2.5', 'real'));
    }

    public function testRealNullReturnsNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'real'));
        $this->assertNull($this->caster->toPhpValue(null, 'real'));
    }

    // ── 'tinyint' type alias (maps to int) ──────────────────────────

    public function testTinyintToDatabaseValueFromInt(): void
    {
        $this->assertSame(42, $this->caster->toDatabaseValue(42, 'tinyint'));
    }

    public function testTinyintToDatabaseValueFromNumericString(): void
    {
        $this->assertSame(255, $this->caster->toDatabaseValue('255', 'tinyint'));
    }

    public function testTinyintToPhpValueFromInt(): void
    {
        $this->assertSame(42, $this->caster->toPhpValue(42, 'tinyint'));
    }

    public function testTinyintToPhpValueFromString(): void
    {
        $this->assertSame(255, $this->caster->toPhpValue('255', 'tinyint'));
    }

    public function testTinyintNullReturnsNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'tinyint'));
        $this->assertNull($this->caster->toPhpValue(null, 'tinyint'));
    }

    // ── 'smallint' type alias (maps to int) ─────────────────────────

    public function testSmallintToDatabaseValueFromInt(): void
    {
        $this->assertSame(32000, $this->caster->toDatabaseValue(32000, 'smallint'));
    }

    public function testSmallintToDatabaseValueFromNumericString(): void
    {
        $this->assertSame(100, $this->caster->toDatabaseValue('100', 'smallint'));
    }

    public function testSmallintToPhpValueFromInt(): void
    {
        $this->assertSame(32000, $this->caster->toPhpValue(32000, 'smallint'));
    }

    public function testSmallintToPhpValueFromString(): void
    {
        $this->assertSame(100, $this->caster->toPhpValue('100', 'smallint'));
    }

    public function testSmallintNullReturnsNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'smallint'));
        $this->assertNull($this->caster->toPhpValue(null, 'smallint'));
    }

    // ── 'bigint' type alias (maps to int) ───────────────────────────

    public function testBigintToDatabaseValueFromInt(): void
    {
        $this->assertSame(9999999999, $this->caster->toDatabaseValue(9999999999, 'bigint'));
    }

    public function testBigintToPhpValueFromInt(): void
    {
        $this->assertSame(9999999999, $this->caster->toPhpValue(9999999999, 'bigint'));
    }

    public function testBigintToPhpValueFromString(): void
    {
        $this->assertSame(9999999999, $this->caster->toPhpValue('9999999999', 'bigint'));
    }

    public function testBigintNullReturnsNull(): void
    {
        $this->assertNull($this->caster->toDatabaseValue(null, 'bigint'));
        $this->assertNull($this->caster->toPhpValue(null, 'bigint'));
    }

    // ── Cross-type consistency ──────────────────────────────────────

    public function testRealBehavesLikeFloat(): void
    {
        $floatResult = $this->caster->toDatabaseValue(1.5, 'float');
        $realResult = $this->caster->toDatabaseValue(1.5, 'real');

        $this->assertSame($floatResult, $realResult);
    }

    public function testTinyintBehavesLikeInt(): void
    {
        $intResult = $this->caster->toDatabaseValue(10, 'int');
        $tinyintResult = $this->caster->toDatabaseValue(10, 'tinyint');

        $this->assertSame($intResult, $tinyintResult);
    }

    public function testSmallintBehavesLikeInt(): void
    {
        $intResult = $this->caster->toDatabaseValue(10, 'int');
        $smallintResult = $this->caster->toDatabaseValue(10, 'smallint');

        $this->assertSame($intResult, $smallintResult);
    }

    public function testBigintBehavesLikeInt(): void
    {
        $intResult = $this->caster->toDatabaseValue(10, 'int');
        $bigintResult = $this->caster->toDatabaseValue(10, 'bigint');

        $this->assertSame($intResult, $bigintResult);
    }

    // ── getDatabaseValueSQL for 'real' ──────────────────────────────

    public function testGetDatabaseValueSqlForRealWrapsWithConvert(): void
    {
        $result = $this->caster->getDatabaseValueSQL('?', 'real');

        $this->assertSame('CONVERT(REAL, ?)', $result);
    }
}
