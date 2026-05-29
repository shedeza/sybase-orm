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
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Cache\CacheManager;

#[Entity(table: 'test_boolean_query')]
class BooleanQueryEntity
{
    #[Id]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[Column(name: 'active_bit', type: 'boolean')]
    private bool $active = false;
}

final class CriteriaTypeCastingTest extends TestCase
{
    public function testCriteriaValuesAreCastedToDatabaseValues(): void
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
        $em->setEntityClasses([BooleanQueryEntity::class]);
        $hydrator->setEntityManager($em);

        // Si busco por active => true, el valor en SQL debería ser 1 (BIT en Sybase)
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->anything(),
                $this->callback(function($params) {
                    return $params === [1]; // Comparación estricta
                })
            )
            ->willReturn($this->createMock(\PDOStatement::class));

        $repo = $em->getRepository(BooleanQueryEntity::class);
        $repo->findBy(['active' => true]);
    }
}
