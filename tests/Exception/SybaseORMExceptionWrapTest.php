<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\SybaseORMException;

/**
 * Tests for SybaseORMException::wrap().
 */
final class SybaseORMExceptionWrapTest extends TestCase
{
    public function testWrapReturnsOriginalIfAlreadySybaseORMException(): void
    {
        $original = new SybaseORMException('test');

        $wrapped = SybaseORMException::wrap($original);

        $this->assertSame($original, $wrapped);
    }

    public function testWrapReturnsOriginalSubclassIfAlreadySybaseORMException(): void
    {
        $original = new PersistenceException('test');

        $wrapped = SybaseORMException::wrap($original);

        $this->assertSame($original, $wrapped);
    }

    public function testWrapCreatesNewExceptionForNonSybaseORMException(): void
    {
        $original = new \RuntimeException('some error', 42);

        $wrapped = SybaseORMException::wrap($original);

        $this->assertInstanceOf(SybaseORMException::class, $wrapped);
        $this->assertSame('some error', $wrapped->getMessage());
        $this->assertSame(42, $wrapped->getCode());
        $this->assertSame($original, $wrapped->getPrevious());
    }

    public function testWrapWithCustomMessage(): void
    {
        $original = new \RuntimeException('original');

        $wrapped = SybaseORMException::wrap($original, 'custom message');

        $this->assertSame('custom message', $wrapped->getMessage());
        $this->assertSame($original, $wrapped->getPrevious());
    }

    public function testWrapWithCustomMessageOnSybaseORMException(): void
    {
        $original = new SybaseORMException('original');

        $wrapped = SybaseORMException::wrap($original, 'custom message');

        $this->assertNotSame($original, $wrapped);
        $this->assertSame('custom message', $wrapped->getMessage());
        $this->assertSame($original, $wrapped->getPrevious());
    }
}
