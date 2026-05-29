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

#[Entity(table: 'test_mapped_columns')]
class MappedColumnEntity
{
    #[Id]
    #[Column(name: 'id_col', type: 'integer')]
    private ?int $id = null;

    #[Column(name: 'data_col', type: 'string')]
    private ?string $data = null;

    public function getId(): ?int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }
    public function getData(): ?string { return $this->data; }
    public function setData(string $data): void { $this->data = $data; }
}

final class MappedColumnQueryTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
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

        $this->em = new EntityManager(
            $conn,
            $reader,
            $dialect,
            $caster,
            $hydrator,
            $uow,
            $identityMap,
            $dispatcher,
            $cache
        );
        $this->em->setEntityClasses([MappedColumnEntity::class]);
        $hydrator->setEntityManager($this->em);
    }

    public function testFindByUsesCorrectColumnNames(): void
    {
        $conn = $this->em->getConnection();
        
        // Esperamos que el SQL use [id_col] en lugar de [id]
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('WHERE [e].[id_col] = ?'),
                $this->equalTo([1])
            )
            ->willReturn($this->createMock(\PDOStatement::class));

        $repo = $this->em->getRepository(MappedColumnEntity::class);
        $repo->findBy(['id' => 1]);
    }
}
