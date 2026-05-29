<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\Query\QueryBuilderInterface;
use SybaseORM\Tests\ORM\Fixtures\CustomerEntity;

final class EntityRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);

        $metadata = new \SybaseORM\Metadata\ClassMetadata(
            entityClass: CustomerEntity::class,
            tableName: 'customers'
        );
        $metadataReader = $this->createMock(\SybaseORM\Metadata\MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->with(CustomerEntity::class)->willReturn($metadata);
        $this->em->method('getMetadataReader')->willReturn($metadataReader);

        $this->repo = new EntityRepository($this->em, CustomerEntity::class);
    }

    // ── save() ──────────────────────────────────────────────────────

    public function testSavePersistsAndFlushes(): void
    {
        $entity = new CustomerEntity();
        $entity->setName('Alice');

        $this->em->expects($this->once())
            ->method('persist')
            ->with($entity);

        $this->em->expects($this->once())
            ->method('flush');

        $this->repo->save($entity);
    }

    // ── saveMany() ──────────────────────────────────────────────────

    public function testSaveManyPersistsAllAndFlushesOnce(): void
    {
        $e1 = new CustomerEntity();
        $e1->setName('Alice');
        $e2 = new CustomerEntity();
        $e2->setName('Bob');

        $this->em->expects($this->exactly(2))
            ->method('persist');

        $this->em->expects($this->once())
            ->method('flush');

        $this->repo->saveMany([$e1, $e2]);
    }

    // ── delete() ────────────────────────────────────────────────────

    public function testDeleteRemovesAndFlushes(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);

        $this->em->expects($this->once())
            ->method('remove')
            ->with($entity);

        $this->em->expects($this->once())
            ->method('flush');

        $this->repo->delete($entity);
    }

    // ── deleteMany() ────────────────────────────────────────────────

    public function testDeleteManyRemovesAllAndFlushesOnce(): void
    {
        $e1 = new CustomerEntity();
        $e1->setId(1);
        $e2 = new CustomerEntity();
        $e2->setId(2);

        $this->em->expects($this->exactly(2))
            ->method('remove');

        $this->em->expects($this->once())
            ->method('flush');

        $this->repo->deleteMany([$e1, $e2]);
    }

    // ── merge() ─────────────────────────────────────────────────────

    public function testMergeDelegatesToEntityManager(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);

        $managed = new CustomerEntity();
        $managed->setId(1);

        $this->em->expects($this->once())
            ->method('merge')
            ->with($entity)
            ->willReturn($managed);

        $result = $this->repo->merge($entity);
        $this->assertSame($managed, $result);
    }

    // ── find() ──────────────────────────────────────────────────────

    public function testFindDelegatesToEntityManager(): void
    {
        $entity = new CustomerEntity();

        $this->em->expects($this->once())
            ->method('find')
            ->with(CustomerEntity::class, 42)
            ->willReturn($entity);

        $result = $this->repo->find(42);
        $this->assertSame($entity, $result);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->assertNull($this->repo->find(999));
    }

    public function testFindWithArrayDelegatesToFindOneByForCompositeKeys(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);
        $entity->setName('Alice');

        $compositeKey = ['id' => 1, 'name' => 'Alice'];

        // find() con array NO debe llamar a EntityManager::find()
        $this->em->expects($this->never())
            ->method('find');

        // Debe delegar a queryOne() vía findOneBy
        $this->em->expects($this->once())
            ->method('queryOne')
            ->willReturn($entity);

        $result = $this->repo->find($compositeKey);
        $this->assertSame($entity, $result);
    }

    public function testFindWithArrayReturnsNullWhenNoMatch(): void
    {
        $compositeKey = ['id' => 999, 'name' => 'Nobody'];

        $this->em->expects($this->never())
            ->method('find');

        $this->em->expects($this->once())
            ->method('queryOne')
            ->willReturn(null);

        $result = $this->repo->find($compositeKey);
        $this->assertNull($result);
    }

    // ── query() ─────────────────────────────────────────────────────

    public function testQueryDelegatesToEntityManager(): void
    {
        $oql = 'SELECT e FROM CustomerEntity e WHERE e.name = :name';
        $params = ['name' => 'Alice'];

        $this->em->expects($this->once())
            ->method('query')
            ->with($oql, $params)
            ->willReturn([]);

        $this->repo->query($oql, $params);
    }

    // ── createQueryBuilder() ────────────────────────────────────────

    public function testCreateQueryBuilderDelegatesToEntityManager(): void
    {
        $qb = $this->createMock(QueryBuilderInterface::class);

        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->with(CustomerEntity::class)
            ->willReturn($qb);

        $result = $this->repo->createQueryBuilder();
        $this->assertSame($qb, $result);
    }

    // ── Transacciones ───────────────────────────────────────────────

    public function testBeginTransactionDelegates(): void
    {
        $this->em->expects($this->once())->method('beginTransaction');
        $this->repo->beginTransaction();
    }

    public function testCommitDelegates(): void
    {
        $this->em->expects($this->once())->method('commit');
        $this->repo->commit();
    }

    public function testRollbackDelegates(): void
    {
        $this->em->expects($this->once())->method('rollback');
        $this->repo->rollback();
    }

    // ── getEntityClass() ────────────────────────────────────────────

    public function testGetEntityClassReturnsCorrectClass(): void
    {
        $this->assertSame(CustomerEntity::class, $this->repo->getEntityClass());
    }

    // ── getEntityManager() accesible desde subclases ────────────────

    public function testGetEntityManagerAccessibleFromSubclass(): void
    {
        $customRepo = new class($this->em, CustomerEntity::class) extends EntityRepository {
            public function exposeEntityManager(): EntityManagerInterface
            {
                return $this->getEntityManager();
            }
        };

        $this->assertSame($this->em, $customRepo->exposeEntityManager());
    }

    // ── findOneBy() ─────────────────────────────────────────────────

    public function testFindOneByDelegatesToQueryOne(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);
        $entity->setName('Alice');

        $this->em->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'WHERE') && str_contains($oql, 'e.name')),
                $this->callback(fn(array $params) => $params['p0'] === 'Alice'),
            )
            ->willReturn($entity);

        $result = $this->repo->findOneBy(['name' => 'Alice']);
        $this->assertSame($entity, $result);
    }

    public function testFindOneByReturnsNullWhenNotFound(): void
    {
        $this->em->expects($this->once())
            ->method('queryOne')
            ->willReturn(null);

        $result = $this->repo->findOneBy(['name' => 'Nobody']);
        $this->assertNull($result);
    }

    public function testFindOneByWithEmptyCriteriaQueriesAll(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);

        $this->em->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'SELECT e FROM') && !str_contains($oql, 'WHERE')),
            )
            ->willReturn($entity);

        $result = $this->repo->findOneBy([]);
        $this->assertSame($entity, $result);
    }

    public function testFindOneByWithMultipleCriteria(): void
    {
        $entity = new CustomerEntity();
        $entity->setId(1);
        $entity->setName('Alice');

        $this->em->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'e.id = :p0') && str_contains($oql, 'e.name = :p1')),
                $this->callback(fn(array $params) => $params['p0'] === 1 && $params['p1'] === 'Alice'),
            )
            ->willReturn($entity);

        $result = $this->repo->findOneBy(['id' => 1, 'name' => 'Alice']);
        $this->assertSame($entity, $result);
    }

    // ── exists() ────────────────────────────────────────────────────

    public function testExistsReturnsTrueWhenEntityFound(): void
    {
        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'COUNT(*)') && str_contains($oql, 'WHERE')),
                $this->callback(fn(array $params) => $params['e0'] === 'Alice'),
            )
            ->willReturn(1);

        $this->assertTrue($this->repo->exists(['name' => 'Alice']));
    }

    public function testExistsReturnsFalseWhenNotFound(): void
    {
        $this->em->expects($this->once())
            ->method('queryScalar')
            ->willReturn(0);

        $this->assertFalse($this->repo->exists(['name' => 'Nobody']));
    }

    public function testExistsReturnsFalseWhenScalarReturnsNull(): void
    {
        $this->em->expects($this->once())
            ->method('queryScalar')
            ->willReturn(null);

        $this->assertFalse($this->repo->exists(['name' => 'Nobody']));
    }

    public function testExistsWithEmptyCriteriaChecksAll(): void
    {
        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'COUNT(*)') && !str_contains($oql, 'WHERE')),
                [],
            )
            ->willReturn(5);

        $this->assertTrue($this->repo->exists([]));
    }

    public function testExistsWithMultipleCriteria(): void
    {
        $this->em->expects($this->once())
            ->method('queryScalar')
            ->with(
                $this->callback(fn(string $oql) => str_contains($oql, 'e.id = :e0') && str_contains($oql, 'e.name = :e1')),
                $this->callback(fn(array $params) => $params['e0'] === 1 && $params['e1'] === 'Alice'),
            )
            ->willReturn(1);

        $this->assertTrue($this->repo->exists(['id' => 1, 'name' => 'Alice']));
    }
}
