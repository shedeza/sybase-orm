<?php

declare(strict_types=1);

namespace SybaseORM\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Command\InstallCommand;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Connection\ConnectionUrlParser;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Migration\MigrationManager;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\Type\TypeCaster;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Registers all SybaseORM services in the Symfony DI container.
 */
final class SybaseORMExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerConnectionManager($container, $config);
        $this->registerDialect($container);
        $this->registerTypeCaster($container);
        $this->registerMetadataReader($container, $config);
        $this->registerIdentityMap($container);
        $this->registerCacheManager($container);
        $this->registerHydrator($container);
        $this->registerUnitOfWork($container);
        $this->registerHookDispatcher($container);
        $this->registerProxyGenerator($container, $config);
        $this->registerMigrationManager($container, $config);
        $this->registerEntityManager($container, $config);

        // Store config parameters for commands
        $container->setParameter('sybase_orm.entity_directories', $config['entity_directories']);
        $container->setParameter('sybase_orm.proxy_directory', $config['proxy_directory']);
        $container->setParameter('sybase_orm.migrations_directory', $config['migrations_directory']);

        // Register install command with project dir
        $installDef = new Definition(InstallCommand::class, ['%kernel.project_dir%']);
        $installDef->addTag('console.command');
        $container->setDefinition(InstallCommand::class, $installDef);
    }

    public function getAlias(): string
    {
        return 'sybase_orm';
    }

    private function registerConnectionManager(ContainerBuilder $container, array $config): void
    {
        $connectionConfig = $config['connection'];

        if ($connectionConfig['url'] !== null) {
            // URL mode: registrar un factory que parsee la URL en runtime
            // para soportar %env(DATABASE_URL)% que se resuelve después del compile
            $definition = new Definition(ConnectionManager::class);
            $definition->setFactory([self::class, 'createConnectionManagerFromUrl']);
            $definition->setArguments([$connectionConfig['url']]);
        } else {
            // Parámetros individuales
            $connConfig = [
                'host' => $connectionConfig['host'],
                'port' => (int) $connectionConfig['port'],
                'dbname' => $connectionConfig['database'],
                'username' => $connectionConfig['username'],
                'password' => $connectionConfig['password'],
                'charset' => $connectionConfig['charset'],
                'persistent' => $connectionConfig['persistent'],
            ];
            $definition = new Definition(ConnectionManager::class, [$connConfig]);
        }

        $definition->setPublic(false);

        $container->setDefinition(ConnectionManager::class, $definition);
        $container->setAlias(ConnectionManagerInterface::class, ConnectionManager::class);
    }

    /**
     * Factory method para crear ConnectionManager desde una URL.
     * Se ejecuta en runtime, cuando las variables de entorno ya están resueltas.
     */
    public static function createConnectionManagerFromUrl(string $url): ConnectionManager
    {
        $config = ConnectionUrlParser::parse($url);

        return new ConnectionManager($config);
    }

    private function registerDialect(ContainerBuilder $container): void
    {
        $definition = new Definition(SybaseDialect::class);
        $definition->setPublic(false);

        $container->setDefinition(SybaseDialect::class, $definition);
        $container->setAlias(DialectInterface::class, SybaseDialect::class);
    }

    private function registerTypeCaster(ContainerBuilder $container): void
    {
        $definition = new Definition(TypeCaster::class);
        $definition->setPublic(false);

        $container->setDefinition(TypeCaster::class, $definition);
        $container->setAlias(TypeCasterInterface::class, TypeCaster::class);
    }

    private function registerMetadataReader(ContainerBuilder $container, array $config): void
    {
        $cacheDir = $config['proxy_directory'];

        $definition = new Definition(MetadataReader::class, [$cacheDir]);
        $definition->setPublic(false);

        $container->setDefinition(MetadataReader::class, $definition);
        $container->setAlias(MetadataReaderInterface::class, MetadataReader::class);
    }

    private function registerIdentityMap(ContainerBuilder $container): void
    {
        $definition = new Definition(IdentityMap::class);
        $definition->setPublic(false);

        $container->setDefinition(IdentityMap::class, $definition);
        $container->setAlias(IdentityMapInterface::class, IdentityMap::class);
    }

    private function registerCacheManager(ContainerBuilder $container): void
    {
        $definition = new Definition(CacheManager::class, [
            new Reference(IdentityMapInterface::class),
            null,
            null,
        ]);
        $definition->setPublic(false);

        $container->setDefinition(CacheManager::class, $definition);
        $container->setAlias(CacheManagerInterface::class, CacheManager::class);
    }

    private function registerHydrator(ContainerBuilder $container): void
    {
        $definition = new Definition(Hydrator::class, [
            new Reference(MetadataReaderInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference(IdentityMapInterface::class),
        ]);
        $definition->setPublic(false);

        $container->setDefinition(Hydrator::class, $definition);
        $container->setAlias(HydratorInterface::class, Hydrator::class);
    }

    private function registerUnitOfWork(ContainerBuilder $container): void
    {
        $definition = new Definition(UnitOfWork::class, [
            new Reference(ConnectionManagerInterface::class),
            new Reference(MetadataReaderInterface::class),
            new Reference(DialectInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference(IdentityMapInterface::class),
            new Reference(HookDispatcher::class),
        ]);
        $definition->setPublic(false);

        $container->setDefinition(UnitOfWork::class, $definition);
        $container->setAlias(UnitOfWorkInterface::class, UnitOfWork::class);
    }

    private function registerHookDispatcher(ContainerBuilder $container): void
    {
        $definition = new Definition(HookDispatcher::class, [
            new Reference(MetadataReaderInterface::class),
        ]);
        $definition->setPublic(false);

        $container->setDefinition(HookDispatcher::class, $definition);
    }

    private function registerProxyGenerator(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(ProxyGenerator::class, [
            $config['proxy_directory'],
            new Reference(IdentityMapInterface::class),
        ]);
        $definition->setPublic(false);

        $container->setDefinition(ProxyGenerator::class, $definition);
    }

    private function registerMigrationManager(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(MigrationManager::class, [
            new Reference(ConnectionManagerInterface::class),
            new Reference(MetadataReaderInterface::class),
            new Reference(DialectInterface::class),
            $config['migrations_directory'],
        ]);
        $definition->setPublic(false);

        $container->setDefinition(MigrationManager::class, $definition);
    }

    private function registerEntityManager(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(EntityManager::class, [
            new Reference(ConnectionManagerInterface::class),
            new Reference(MetadataReaderInterface::class),
            new Reference(DialectInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference(HydratorInterface::class),
            new Reference(UnitOfWorkInterface::class),
            new Reference(IdentityMapInterface::class),
            new Reference(HookDispatcher::class),
            new Reference(CacheManagerInterface::class),
        ]);
        $definition->setPublic(true);
        $definition->setAutowired(true);

        $container->setDefinition(EntityManager::class, $definition);
        $container->setAlias(EntityManagerInterface::class, EntityManager::class)
            ->setPublic(true);
    }
}
