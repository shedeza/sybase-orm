<?php

declare(strict_types=1);

namespace SybaseORM\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use SybaseORM\DependencyInjection\RepositoryAutowiringCompilerPass;
use SybaseORM\ORM\EntityManagerRegistry;

/**
 * Tests for RepositoryAutowiringCompilerPass.
 */
final class RepositoryAutowiringCompilerPassTest extends TestCase
{
    public function testSkipsWhenNoRegistry(): void
    {
        $container = new ContainerBuilder();
        $pass = new RepositoryAutowiringCompilerPass();

        // Should not throw
        $pass->process($container);

        $this->assertTrue(true);
    }

    public function testSkipsWhenNoEntityDirectories(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EntityManagerRegistry::class, new Definition(EntityManagerRegistry::class));

        $pass = new RepositoryAutowiringCompilerPass();
        $pass->process($container);

        $this->assertTrue(true);
    }

    public function testSkipsWhenEntityDirectoriesEmpty(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EntityManagerRegistry::class, new Definition(EntityManagerRegistry::class));
        $container->setParameter('sybase_orm.entity_directories', []);

        $pass = new RepositoryAutowiringCompilerPass();
        $pass->process($container);

        $this->assertTrue(true);
    }

    public function testSkipsNonExistentDirectories(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EntityManagerRegistry::class, new Definition(EntityManagerRegistry::class));
        $container->setParameter('sybase_orm.entity_directories', ['/nonexistent/path']);

        $pass = new RepositoryAutowiringCompilerPass();
        $pass->process($container);

        $this->assertTrue(true);
    }

    public function testDoesNotOverrideExistingDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EntityManagerRegistry::class, new Definition(EntityManagerRegistry::class));
        $container->setParameter('sybase_orm.entity_directories', ['/nonexistent']);

        // Pre-register a repository
        $existingDef = new Definition(\stdClass::class);
        $container->setDefinition('App\\Repository\\CustomRepo', $existingDef);

        $pass = new RepositoryAutowiringCompilerPass();
        $pass->process($container);

        // Should not have been overwritten
        $this->assertSame($existingDef, $container->getDefinition('App\\Repository\\CustomRepo'));
    }
}
