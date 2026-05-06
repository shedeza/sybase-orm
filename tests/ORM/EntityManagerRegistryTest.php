<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityManagerRegistry;

/**
 * Tests for EntityManagerRegistry multi-connection support.
 */
final class EntityManagerRegistryTest extends TestCase
{
    public function testGetManagerReturnsDefault(): void
    {
        $defaultEm = $this->createMock(EntityManagerInterface::class);
        $registry = new EntityManagerRegistry(['default' => $defaultEm], 'default');

        $this->assertSame($defaultEm, $registry->getManager());
        $this->assertSame($defaultEm, $registry->getManager('default'));
    }

    public function testGetManagerByName(): void
    {
        $defaultEm = $this->createMock(EntityManagerInterface::class);
        $reportingEm = $this->createMock(EntityManagerInterface::class);

        $registry = new EntityManagerRegistry([
            'default' => $defaultEm,
            'reporting' => $reportingEm,
        ], 'default');

        $this->assertSame($reportingEm, $registry->getManager('reporting'));
    }

    public function testGetManagerThrowsForUnknownConnection(): void
    {
        $registry = new EntityManagerRegistry(['default' => $this->createMock(EntityManagerInterface::class)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reporting');

        $registry->getManager('reporting');
    }

    public function testGetConnectionNames(): void
    {
        $registry = new EntityManagerRegistry([
            'default' => $this->createMock(EntityManagerInterface::class),
            'legacy' => $this->createMock(EntityManagerInterface::class),
        ]);

        $this->assertSame(['default', 'legacy'], $registry->getConnectionNames());
    }

    public function testAddManager(): void
    {
        $registry = new EntityManagerRegistry();
        $em = $this->createMock(EntityManagerInterface::class);

        $registry->addManager('new_conn', $em);

        $this->assertSame($em, $registry->getManager('new_conn'));
    }

    public function testClearAll(): void
    {
        $em1 = $this->createMock(EntityManagerInterface::class);
        $em1->expects($this->once())->method('clear');

        $em2 = $this->createMock(EntityManagerInterface::class);
        $em2->expects($this->once())->method('clear');

        $registry = new EntityManagerRegistry(['a' => $em1, 'b' => $em2]);
        $registry->clearAll();
    }
}
