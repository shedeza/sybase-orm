<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\TypeConversionException;

/**
 * Tests for TypeConversionException factory methods.
 */
final class TypeConversionExceptionFactoryTest extends TestCase
{
    public function testForUnsupportedType(): void
    {
        $ex = TypeConversionException::forUnsupportedType('json', 'hello');

        $this->assertInstanceOf(TypeConversionException::class, $ex);
        $this->assertStringContainsString('json', $ex->getMessage());
        $this->assertStringContainsString('string', $ex->getMessage());
        $this->assertSame('string', $ex->getSourceType());
        $this->assertSame('json', $ex->getTargetType());
        $this->assertSame('hello', $ex->getProblematicValue());
    }
}
