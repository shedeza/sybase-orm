<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Tests for UnitOfWork::clearClass().
 */
final class UnitOfWorkClearClassTest extends TestCase
{
    public function testClearClassRemovesOnlyTargetClass(): void
    {
        $userMeta = new ClassMetadata(
            entityClass: UowClearUser::class,
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
            ],
            idFields: ['id'],
        );

        $postMeta = new ClassMetadata(
            entityClass: UowClearPost::class,
            tableName: 'posts',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
            ],
            idFields: ['id'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')
            ->willReturnCallback(fn(string $class) => match ($class) {
                UowClearUser::class => $userMeta,
                UowClearPost::class => $postMeta,
                default => throw new \RuntimeException("Unknown: $class"),
            });

        $uow = new UnitOfWork(
            $this->createMock(ConnectionManagerInterface::class),
            $metadataReader,
            $this->createMock(DialectInterface::class),
            $this->createMock(TypeCasterInterface::class),
            new IdentityMap(),
        );

        $user = new UowClearUser();
        $user->id = 1;
        $post = new UowClearPost();
        $post->id = 1;

        $uow->registerClean($user);
        $uow->registerClean($post);

        $this->assertTrue($uow->isManaged($user));
        $this->assertTrue($uow->isManaged($post));

        $uow->clearClass(UowClearUser::class);

        $this->assertFalse($uow->isManaged($user));
        $this->assertTrue($uow->isManaged($post));
    }
}

class UowClearUser
{
    public ?int $id = null;
}

class UowClearPost
{
    public ?int $id = null;
}
