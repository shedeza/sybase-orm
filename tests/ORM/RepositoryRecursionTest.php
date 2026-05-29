<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Tests\ORM\Fixtures\CustomerEntity;

final class RepositoryRecursionTest extends TestCase
{
    public function testFindAllDoesNotRecurseInfinitely(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $reader = $this->createMock(MetadataReaderInterface::class);
        
        $metadata = new ClassMetadata(
            entityClass: CustomerEntity::class,
            tableName: 'customers'
        );
        
        $reader->method('getClassMetadata')->willReturn($metadata);
        $em->method('getMetadataReader')->willReturn($reader);
        
        // Esperamos que se llame a query una vez con el OQL correcto
        $em->expects($this->once())
            ->method('query')
            ->with($this->stringContains('SELECT e FROM CustomerEntity e'))
            ->willReturn([]);

        $repo = new EntityRepository($em, CustomerEntity::class);
        
        $results = $repo->findAll();
        $this->assertIsArray($results);
    }
}
