<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\CustomTypeInterface;
use SybaseORM\Type\TypeCaster;

final class TypeCasterRegistryTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    public function testIsBuiltinTypeReturnsTrueForKnownTypes(): void
    {
        $builtins = ['bool', 'boolean', 'datetime', 'int', 'integer', 'tinyint', 'smallint', 'bigint', 'float', 'double', 'decimal', 'real', 'string', 'varchar', 'text'];

        foreach ($builtins as $type) {
            $this->assertTrue($this->caster->isBuiltinType($type), "Expected '$type' to be a builtin type");
        }
    }

    public function testIsBuiltinTypeReturnsFalseForCustomTypes(): void
    {
        $this->assertFalse($this->caster->isBuiltinType('money'));
        $this->assertFalse($this->caster->isBuiltinType('json'));
    }

    public function testIsRegisteredTypeReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->caster->isRegisteredType('money'));
    }

    public function testIsRegisteredTypeReturnsTrueAfterRegistration(): void
    {
        $this->caster->registerType('money', DummyCustomType::class);

        $this->assertTrue($this->caster->isRegisteredType('money'));
    }

    public function testGetRegisteredTypeNamesReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->caster->getRegisteredTypeNames());
    }

    public function testGetRegisteredTypeNamesReturnsRegisteredNames(): void
    {
        $this->caster->registerType('money', DummyCustomType::class);
        $this->caster->registerType('json', DummyCustomType::class);

        $names = $this->caster->getRegisteredTypeNames();
        sort($names);

        $this->assertSame(['json', 'money'], $names);
    }
}

/**
 * @internal
 */
final class DummyCustomType implements CustomTypeInterface
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
