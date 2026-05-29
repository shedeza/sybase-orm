<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Tests\ORM\Fixtures\CustomerEntity;
use SybaseORM\Tests\ORM\Fixtures\OrderEntity;
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

final class BidirectionalIdentityTest extends TestCase
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

        $hydrator->setEntityManager($this->em);
    }

    public function testBidirectionalRelationshipMaintainsSameInstance(): void
    {
        // Simulamos la carga de un Customer que tiene una colección de Orders.
        // Cuando se hidrate el Order, su propiedad 'customer' debe apuntar al MISMO objeto Customer.

        $customer = new CustomerEntity();
        $customer->setId(1);
        $customer->setName('Test Customer');

        // Registramos el customer en el IdentityMap como si acabara de ser cargado
        $reflector = new \ReflectionClass($this->em);
        $identityMap = $reflector->getProperty('identityMap')->getValue($this->em);
        $identityMap->put(CustomerEntity::class, 1, $customer);

        // Simulamos una fila de base de datos para un Order que pertenece a este Customer
        $orderRow = [
            'id' => 101,
            'customer_id' => 1,
            'amount' => 50.0
        ];

        $hydrator = $reflector->getProperty('hydrator')->getValue($this->em);
        /** @var OrderEntity $order */
        $order = $hydrator->hydrate($orderRow, OrderEntity::class);

        $this->assertInstanceOf(OrderEntity::class, $order);
        
        // El problema reportado: $order->getCustomer() debería ser EXACTAMENTE $customer
        // Pero actualmente Hydrator podría estar creando un Proxy y sobrescribiendo el IdentityMap
        // o simplemente no usando el IdentityMap para la relación.

        $this->assertSame($customer, $order->getCustomer(), 'La instancia de Customer en la relación debe ser la misma que en el IdentityMap');
    }
}
