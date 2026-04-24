<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type;

use PHPUnit\Framework\TestCase;
use SybaseORM\Type\TypeCaster;
use SybaseORM\Type\Types;

/**
 * Validates that all Types constants are recognized by the TypeCaster.
 */
final class TypesTest extends TestCase
{
    private TypeCaster $typeCaster;

    protected function setUp(): void
    {
        $this->typeCaster = new TypeCaster();
    }

    /**
     * @dataProvider allTypesProvider
     */
    public function testAllTypesAreRecognizedByTypeCaster(string $type, mixed $sampleValue): void
    {
        // toDatabaseValue should not throw for valid types
        $dbValue = $this->typeCaster->toDatabaseValue($sampleValue, $type);
        $this->assertNotNull($dbValue);

        // toPhpValue should not throw for valid types
        $phpValue = $this->typeCaster->toPhpValue($dbValue, $type);
        $this->assertNotNull($phpValue);
    }

    /** @return array<string, array{string, mixed}> */
    public static function allTypesProvider(): array
    {
        return [
            'STRING' => [Types::STRING, 'hello'],
            'VARCHAR' => [Types::VARCHAR, 'world'],
            'TEXT' => [Types::TEXT, 'long text'],
            'INTEGER' => [Types::INTEGER, 42],
            'INT' => [Types::INT, 7],
            'TINYINT' => [Types::TINYINT, 1],
            'SMALLINT' => [Types::SMALLINT, 100],
            'BIGINT' => [Types::BIGINT, 999999],
            'FLOAT' => [Types::FLOAT, 3.14],
            'DOUBLE' => [Types::DOUBLE, 2.718],
            'DECIMAL' => [Types::DECIMAL, 99.99],
            'REAL' => [Types::REAL, 0.5],
            'BOOLEAN' => [Types::BOOLEAN, true],
            'BOOL' => [Types::BOOL, false],
            'DATETIME' => [Types::DATETIME, new \DateTimeImmutable('2024-01-15 10:30:00')],
        ];
    }

    public function testTypesConstantsMatchExpectedStrings(): void
    {
        $this->assertSame('string', Types::STRING);
        $this->assertSame('varchar', Types::VARCHAR);
        $this->assertSame('text', Types::TEXT);
        $this->assertSame('integer', Types::INTEGER);
        $this->assertSame('int', Types::INT);
        $this->assertSame('tinyint', Types::TINYINT);
        $this->assertSame('smallint', Types::SMALLINT);
        $this->assertSame('bigint', Types::BIGINT);
        $this->assertSame('float', Types::FLOAT);
        $this->assertSame('double', Types::DOUBLE);
        $this->assertSame('decimal', Types::DECIMAL);
        $this->assertSame('real', Types::REAL);
        $this->assertSame('boolean', Types::BOOLEAN);
        $this->assertSame('bool', Types::BOOL);
        $this->assertSame('datetime', Types::DATETIME);
    }

    public function testNullPassesThroughForAllTypes(): void
    {
        $reflection = new \ReflectionClass(Types::class);
        foreach ($reflection->getConstants() as $name => $type) {
            $this->assertNull(
                $this->typeCaster->toDatabaseValue(null, $type),
                "toDatabaseValue(null, '$type') should return null",
            );
            $this->assertNull(
                $this->typeCaster->toPhpValue(null, $type),
                "toPhpValue(null, '$type') should return null",
            );
        }
    }

    public function testTypesClassCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(Types::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
