<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\ORM\EntityManager;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Type\TypeCaster;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Cache\CacheManager;

#[Entity(table: 'test_no_soft_delete')]
class NoSoftDeleteEntity
{
    #[Id]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id = null;
}

final class SoftDeleteAccidentalTest extends TestCase
{
    public function testNoSoftDeleteIsAppliedByDefaultIfAttributeMissing(): void
    {
        $conn = $this->createMock(ConnectionManagerInterface::class);
        $reader = new MetadataReader();
        $dialect = new SybaseDialect();
        $caster = new TypeCaster();
        $identityMap = new IdentityMap();
        $uow = new UnitOfWork($conn, $reader, $dialect, $caster, $identityMap);
        $hydrator = new Hydrator($reader, $caster, $identityMap, $uow);
        $cache = new CacheManager($identityMap);
        $dispatcher = new HookDispatcher($reader);

        $em = new EntityManager($conn, $reader, $dialect, $caster, $hydrator, $uow, $identityMap, $dispatcher, $cache);
        $em->setEntityClasses([NoSoftDeleteEntity::class]);

        $metadata = $reader->getClassMetadata(NoSoftDeleteEntity::class);
        $this->assertNull($metadata->softDeleteColumn, 'softDeleteColumn should be null');

        // El SQL NO debería contener "IS NULL"
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->logicalNot($this->stringContains('IS NULL')))
            ->willReturn($this->createMock(\PDOStatement::class));

        $repo = $em->getRepository(NoSoftDeleteEntity::class);
        $repo->findBy(['id' => 1]);
    }
}
