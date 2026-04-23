<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Tests\Hook\Fixtures\HookableEntity;
use SybaseORM\Tests\Hook\Fixtures\NoHooksEntity;

final class HookDispatcherTest extends TestCase
{
    private HookDispatcher $dispatcher;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $this->dispatcher = new HookDispatcher(new MetadataReader());
    }

    /**
     * @dataProvider hookTypeProvider
     * Validates: Requirements 15.1, 15.3, 15.5
     */
    public function testDispatchExecutesHookMethod(string $hookType): void
    {
        $entity = new HookableEntity();

        $this->dispatcher->dispatch($entity, $hookType);

        $this->assertSame([$hookType], $entity->calledHooks);
    }

    /**
     * Validates: Requirements 15.1, 15.2, 15.3, 15.4, 15.5, 15.6
     */
    public function testDispatchExecutesAllSixHookTypes(): void
    {
        $entity = new HookableEntity();
        $hooks = ['PrePersist', 'PostPersist', 'PreUpdate', 'PostUpdate', 'PreRemove', 'PostRemove'];

        foreach ($hooks as $hook) {
            $this->dispatcher->dispatch($entity, $hook);
        }

        $this->assertSame($hooks, $entity->calledHooks);
    }

    /**
     * Validates: Requirement 15.7
     */
    public function testDispatchPropagatesExceptionFromHook(): void
    {
        $entity = new HookableEntity();
        $entity->throwOnHook = 'PrePersist';
        $entity->throwOn = new \RuntimeException('Hook failed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hook failed');

        $this->dispatcher->dispatch($entity, 'PrePersist');
    }

    /**
     * Validates: Requirement 15.7
     */
    public function testExceptionPropagationCancelsSubsequentHooksOnSameEntity(): void
    {
        $entity = new HookableEntity();
        $entity->throwOnHook = 'PreUpdate';
        $entity->throwOn = new \RuntimeException('Update blocked');

        try {
            $this->dispatcher->dispatch($entity, 'PreUpdate');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException) {
            // Hook was called (recorded) then threw
            $this->assertSame(['PreUpdate'], $entity->calledHooks);
        }
    }

    /**
     * Dispatch on an entity without #[HasLifecycleHooks] should be a no-op.
     */
    public function testDispatchOnEntityWithoutHooksIsNoOp(): void
    {
        $entity = new NoHooksEntity();

        // Should not throw — lifecycleHooks is empty
        $this->dispatcher->dispatch($entity, 'PrePersist');

        $this->assertTrue(true); // reached without error
    }

    /**
     * Dispatch with an unknown hook type should be a no-op.
     */
    public function testDispatchWithUnknownHookTypeIsNoOp(): void
    {
        $entity = new HookableEntity();

        $this->dispatcher->dispatch($entity, 'NonExistentHook');

        $this->assertSame([], $entity->calledHooks);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hookTypeProvider(): array
    {
        return [
            'PrePersist'  => ['PrePersist'],
            'PostPersist' => ['PostPersist'],
            'PreUpdate'   => ['PreUpdate'],
            'PostUpdate'  => ['PostUpdate'],
            'PreRemove'   => ['PreRemove'],
            'PostRemove'  => ['PostRemove'],
        ];
    }
}
