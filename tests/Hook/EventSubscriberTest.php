<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Hook\EventSubscriberInterface;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Metadata\MetadataReader;

/**
 * Tests for EventSubscriberInterface support in HookDispatcher.
 */
final class EventSubscriberTest extends TestCase
{
    private HookDispatcher $dispatcher;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $this->dispatcher = new HookDispatcher(new MetadataReader());
    }

    public function testSubscriberReceivesEvents(): void
    {
        $subscriber = new TestAuditSubscriber();
        $this->dispatcher->addSubscriber($subscriber);

        $entity = new SubscriberTestEntity();
        $this->dispatcher->dispatch($entity, 'PrePersist');

        $this->assertCount(1, $subscriber->events);
        $this->assertSame('PrePersist', $subscriber->events[0]['hookType']);
        $this->assertSame($entity, $subscriber->events[0]['entity']);
    }

    public function testSubscriberOnlyReceivesSubscribedEvents(): void
    {
        $subscriber = new TestAuditSubscriber(); // subscribes to PrePersist, PostUpdate
        $this->dispatcher->addSubscriber($subscriber);

        $entity = new SubscriberTestEntity();
        $this->dispatcher->dispatch($entity, 'PostRemove');

        $this->assertCount(0, $subscriber->events);
    }

    public function testMultipleSubscribers(): void
    {
        $sub1 = new TestAuditSubscriber();
        $sub2 = new TestAuditSubscriber();
        $this->dispatcher->addSubscriber($sub1);
        $this->dispatcher->addSubscriber($sub2);

        $entity = new SubscriberTestEntity();
        $this->dispatcher->dispatch($entity, 'PrePersist');

        $this->assertCount(1, $sub1->events);
        $this->assertCount(1, $sub2->events);
    }

    public function testGetSubscribersReturnsAll(): void
    {
        $sub1 = new TestAuditSubscriber();
        $sub2 = new TestAuditSubscriber();
        $this->dispatcher->addSubscriber($sub1);
        $this->dispatcher->addSubscriber($sub2);

        $this->assertCount(2, $this->dispatcher->getSubscribers());
    }
}

#[Entity(table: 'subscriber_test')]
#[HasLifecycleHooks]
class SubscriberTestEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    public array $calledHooks = [];

    #[PrePersist]
    public function onPrePersist(): void
    {
        $this->calledHooks[] = 'PrePersist';
    }
}

class TestAuditSubscriber implements EventSubscriberInterface
{
    public array $events = [];

    public function getSubscribedEvents(): array
    {
        return ['PrePersist', 'PostUpdate'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        $this->events[] = ['entity' => $entity, 'hookType' => $hookType];
    }
}
