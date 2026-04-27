<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SybaseORM\Hook\HookDispatcher;

/**
 * Tests for HookDispatcher::getSupportedHookTypes().
 */
final class HookDispatcherSupportedTypesTest extends TestCase
{
    public function testGetSupportedHookTypesReturnsAllSixTypes(): void
    {
        $types = HookDispatcher::getSupportedHookTypes();

        $this->assertCount(6, $types);
        $this->assertContains('PrePersist', $types);
        $this->assertContains('PostPersist', $types);
        $this->assertContains('PreUpdate', $types);
        $this->assertContains('PostUpdate', $types);
        $this->assertContains('PreRemove', $types);
        $this->assertContains('PostRemove', $types);
    }
}
