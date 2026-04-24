<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\Tests\ORM\Fixtures\CustomerEntity;

final class EntityRepositoryOqlTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = new EntityRepository($this->em, CustomerEntity::class);
    }

    // ── executeUpdate() ─────────────────────────────────────────────

    public function testExecuteUpdateDelegatesToEntityManager(): void
    {
        $oql = 'UPDATE CustomerEntity e SET e.name = :name WHERE e.id = :id';
        $params = ['name' => 'Updated', 'id' => 1];

        $this->em->expects($this->once())
            ->method('executeUpdate')
            ->with($oql, $params)
            ->willReturn(1);

        $result = $this->repo->executeUpdate($oql, $params);
        $this->assertSame(1, $result);
    }

    public function testExecuteUpdateReturnsZeroWhenNoRowsAffected(): void
    {
        $oql = 'DELETE FROM CustomerEntity e WHERE e.id = :id';
        $params = ['id' => 999];

        $this->em->expects($this->once())
            ->method('executeUpdate')
            ->with($oql, $params)
            ->willReturn(0);

        $result = $this->repo->executeUpdate($oql, $params);
        $this->assertSame(0, $result);
    }

    public function testExecuteUpdateWithEmptyParams(): void
    {
        $oql = 'DELETE FROM CustomerEntity e WHERE e.name IS NULL';

        $this->em->expects($this->once())
            ->method('executeUpdate')
            ->with($oql, [])
            ->willReturn(3);

        $result = $this->repo->executeUpdate($oql);
        $this->assertSame(3, $result);
    }

    // ── queryScalar() ───────────────────────────────────────────────

    public function testQueryScalarDelegatesToEntityManager(): void
    {
        $oql = 'SELECT COUNT(*) FROM CustomerEntity e';

        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with($oql, [])
            ->willReturn(42);

        $result = $this->repo->queryScalar($oql);
        $this->assertSame(42, $result);
    }

    public function testQueryScalarWithParams(): void
    {
        $oql = 'SELECT MAX(e.id) FROM CustomerEntity e WHERE e.name = :name';
        $params = ['name' => 'Alice'];

        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with($oql, $params)
            ->willReturn(10);

        $result = $this->repo->queryScalar($oql, $params);
        $this->assertSame(10, $result);
    }

    public function testQueryScalarReturnsNullWhenNoResult(): void
    {
        $oql = 'SELECT MIN(e.id) FROM CustomerEntity e WHERE e.name = :name';
        $params = ['name' => 'Nobody'];

        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with($oql, $params)
            ->willReturn(null);

        $result = $this->repo->queryScalar($oql, $params);
        $this->assertNull($result);
    }

    public function testQueryScalarReturnsStringValue(): void
    {
        $oql = 'SELECT e.name FROM CustomerEntity e WHERE e.id = :id';
        $params = ['id' => 1];

        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with($oql, $params)
            ->willReturn('Alice');

        $result = $this->repo->queryScalar($oql, $params);
        $this->assertSame('Alice', $result);
    }
}
