<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\HydrationMode;

/**
 * Tests for HydrationMode::isValid().
 */
final class HydrationModeTest extends TestCase
{
    public function testIsValidForHydrateObject(): void
    {
        $this->assertTrue(HydrationMode::isValid(HydrationMode::HYDRATE_OBJECT));
    }

    public function testIsValidForHydrateArray(): void
    {
        $this->assertTrue(HydrationMode::isValid(HydrationMode::HYDRATE_ARRAY));
    }

    public function testIsValidReturnsFalseForInvalidMode(): void
    {
        $this->assertFalse(HydrationMode::isValid(0));
        $this->assertFalse(HydrationMode::isValid(3));
        $this->assertFalse(HydrationMode::isValid(-1));
    }
}
