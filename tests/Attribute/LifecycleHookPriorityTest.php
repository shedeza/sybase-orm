<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Metadata\MetadataReader;

/**
 * Tests for lifecycle hook priority ordering.
 */
final class LifecycleHookPriorityTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
    }

    public function testHooksExecuteInPriorityOrder(): void
    {
        $reader = new MetadataReader();
        $metadata = $reader->getClassMetadata(PriorityTestEntity::class);

        // Hooks should be sorted by priority descending
        $prePersistHooks = $metadata->lifecycleHooks['PrePersist'] ?? [];

        $this->assertSame(['highPriority', 'lowPriority'], $prePersistHooks);
    }

    public function testHooksDispatchInPriorityOrder(): void
    {
        $reader = new MetadataReader();
        $dispatcher = new HookDispatcher($reader);

        $entity = new PriorityTestEntity();
        $dispatcher->dispatch($entity, 'PrePersist');

        $this->assertSame(['high', 'low'], $entity->callOrder);
    }

    public function testDefaultPriorityIsZero(): void
    {
        $attr = new PrePersist();

        $this->assertSame(0, $attr->priority);
    }
}

#[Entity(table: 'priority_test')]
#[HasLifecycleHooks]
class PriorityTestEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    public array $callOrder = [];

    #[PrePersist(priority: 1)]
    public function highPriority(): void
    {
        $this->callOrder[] = 'high';
    }

    #[PrePersist(priority: -1)]
    public function lowPriority(): void
    {
        $this->callOrder[] = 'low';
    }
}
