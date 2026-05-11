<?php

declare(strict_types=1);

namespace SybaseORM\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use SybaseORM\Metadata\EntityDiscovery;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\EntityManagerRegistry;

/**
 * Compiler pass that auto-registers custom repository classes as autowireable services.
 *
 * Scans configured entity directories, reads #[Entity(repositoryClass: ...)] metadata,
 * and registers each custom repository as a factory service via EntityManagerRegistry::getRepository().
 *
 * This enables direct injection of custom repositories in services:
 *
 *     class MyService {
 *         public function __construct(private ProductoRepository $repo) {}
 *     }
 */
final class RepositoryAutowiringCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(EntityManagerRegistry::class)) {
            return;
        }

        if (!$container->hasParameter('sybase_orm.entity_directories')) {
            return;
        }

        /** @var string[] $entityDirs */
        $entityDirs = $container->getParameter('sybase_orm.entity_directories');

        if (empty($entityDirs)) {
            return;
        }

        // Use a fresh MetadataReader without file cache (compile-time only)
        $metadataReader = new MetadataReader();
        $discovery = new EntityDiscovery($metadataReader);

        $entityClasses = $discovery->discoverEntityClasses($entityDirs);

        foreach ($entityClasses as $entityClass) {
            try {
                $metadata = $metadataReader->getClassMetadata($entityClass);
            } catch (\Throwable) {
                // Skip entities that can't be read (e.g., missing dependencies at compile time)
                continue;
            }

            if ($metadata->repositoryClass === null) {
                continue;
            }

            // Don't override if already registered (user may have custom definition)
            if ($container->has($metadata->repositoryClass)) {
                continue;
            }

            $repoDef = new Definition($metadata->repositoryClass);
            $repoDef->setFactory([new Reference(EntityManagerRegistry::class), 'getRepository']);
            $repoDef->setArguments([$entityClass]);
            $repoDef->setPublic(false);
            $repoDef->setAutowired(false);

            $container->setDefinition($metadata->repositoryClass, $repoDef);
        }

        // Clean up static cache used during compilation
        MetadataReader::clearMemoryCache();
    }
}
