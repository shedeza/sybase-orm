<?php

declare(strict_types=1);

namespace SybaseORM\Bundle\DependencyInjection;

use Psr\Log\LoggerInterface;
use SybaseORM\Bundle\Command\CacheClearCommand;
use SybaseORM\Bundle\Command\InstallCommand;
use SybaseORM\Bundle\Command\MigrationsGenerateCommand;
use SybaseORM\Bundle\Command\MigrationsMigrateCommand;
use SybaseORM\Bundle\Command\ProxyGenerateCommand;
use SybaseORM\Bundle\Command\SchemaValidateCommand;
use SybaseORM\Bundle\DataCollector\SybaseQueryCollector;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\CacheManagerInterface;
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
use SybaseORM\ORM\EntityManagerRegistry;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\Type\TypeCaster;
use SybaseORM\Type\TypeCasterInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all SybaseORM services in the Symfony DI container.
 */
final class SybaseORMExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Normalize connections: if 'connection' (singular) is set, treat as 'default'
        $connections = $config['connections'] ?? [];
        if (!empty($config['connection'])) {
            $connections = array_merge(['default' => $config['connection']], $connections);
        }

        if (empty($connections)) {
            throw new \RuntimeException('SybaseORM requires at least one connection configured (sybase_orm.connection or sybase_orm.connections).');
        }

        // Register shared services (dialect, typecaster, metadata, hooks)
        $this->registerDialect($container);
        $this->registerTypeCaster($container);
        $this->registerMetadataReader($container, $config);
        $this->registerHookDispatcher($container);
        $this->registerProxyGenerator($container, $config);

        // Register per-connection services (connection manager, identity map, cache, hydrator, uow, em)
        $managerServiceIds = [];
        $isFirst = true;

        foreach ($connections as $name => $connectionConfig) {
            $this->registerConnectionServices($container, $config, (string) $name, $connectionConfig, $isFirst);
            $managerServiceIds[(string) $name] = 'sybase_orm.entity_manager.' . $name;
            $isFirst = false;
        }

        // Register EntityManagerRegistry
        $registryDef = new Definition(EntityManagerRegistry::class);
        $registryDef->setPublic(true);
        $managerRefs = [];
        foreach ($managerServiceIds as $name => $serviceId) {
            $managerRefs[$name] = new Reference($serviceId);
        }
        $registryDef->setArguments([$managerRefs, array_key_first($connections)]);
        $container->setDefinition(EntityManagerRegistry::class, $registryDef);

        // Register migration manager (uses default connection)
        $this->registerMigrationManager($container, $config);

        // Store config parameters for commands
        $container->setParameter('sybase_orm.entity_directories', $config['entity_directories']);
        $container->setParameter('sybase_orm.proxy_directory', $config['proxy_directory']);
        $container->setParameter('sybase_orm.migrations_directory', $config['migrations_directory']);

        // Register install command with project dir
        $installDef = new Definition(InstallCommand::class, ['%kernel.project_dir%']);
        $installDef->addTag('console.command');
        $container->setDefinition(InstallCommand::class, $installDef);

        // Register schema:validate command
        $schemaValidateDef = new Definition(SchemaValidateCommand::class, [
            new Reference(MetadataReaderInterface::class),
            new Reference(ConnectionManagerInterface::class),
            $config['entity_directories'],
        ]);
        $schemaValidateDef->addTag('console.command');
        $container->setDefinition(SchemaValidateCommand::class, $schemaValidateDef);

        // Register cache:clear command
        $cacheClearDef = new Definition(CacheClearCommand::class, [
            new Reference(CacheManagerInterface::class),
        ]);
        $cacheClearDef->addTag('console.command');
        $container->setDefinition(CacheClearCommand::class, $cacheClearDef);

        // Register migrations:generate command
        $migGenDef = new Definition(MigrationsGenerateCommand::class, [
            new Reference(MigrationManager::class),
            new Reference(MetadataReaderInterface::class),
            $config['entity_directories'],
        ]);
        $migGenDef->addTag('console.command');
        $container->setDefinition(MigrationsGenerateCommand::class, $migGenDef);

        // Register migrations:migrate command
        $migMigDef = new Definition(MigrationsMigrateCommand::class, [
            new Reference(MigrationManager::class),
        ]);
        $migMigDef->addTag('console.command');
        $container->setDefinition(MigrationsMigrateCommand::class, $migMigDef);

        // Register proxy:generate command
        $proxyGenDef = new Definition(ProxyGenerateCommand::class, [
            new Reference(ProxyGenerator::class),
            new Reference(MetadataReaderInterface::class),
            $config['entity_directories'],
        ]);
        $proxyGenDef->addTag('console.command');
        $container->setDefinition(ProxyGenerateCommand::class, $proxyGenDef);

        // Register DataCollector for Symfony Web Profiler (only if kernel.debug)
        $collectorDef = new Definition(SybaseQueryCollector::class);
        $collectorDef->addTag('data_collector', ['template' => null, 'id' => 'sybase_orm']);
        $collectorDef->setPublic(false);
        $container->setDefinition(SybaseQueryCollector::class, $collectorDef);

        // Register SymfonyEventDispatcherSubscriber if EventDispatcher is available
        if (interface_exists(\Psr\EventDispatcher\EventDispatcherInterface::class)) {
            $eventSubDef = new Definition(\SybaseORM\Hook\SymfonyEventDispatcherSubscriber::class, [
                new Reference(\Psr\EventDispatcher\EventDispatcherInterface::class, ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            ]);
            $eventSubDef->setPublic(false);
            $container->setDefinition(\SybaseORM\Hook\SymfonyEventDispatcherSubscriber::class, $eventSubDef);
        }
    }

    public function getAlias(): string
    {
        return 'sybase_orm';
    }

    /**
     * Factory method para crear ConnectionManager desde una URL.
     * Se ejecuta en runtime, cuando las variables de entorno ya están resueltas.
     */
    public static function createConnectionManagerFromUrl(string $url, bool $charsetConversion = false, ?LoggerInterface $logger = null): ConnectionManager
    {
        $config = ConnectionUrlParser::parse($url);

        if ($charsetConversion) {
            $config['charset_conversion'] = true;
        }

        return new ConnectionManager($config, $logger);
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

    private function registerConnectionServices(ContainerBuilder $container, array $globalConfig, string $name, array $connectionConfig, bool $isFirst): void
    {
        $suffix = '.' . $name;
        $loggerRef = new Reference(LoggerInterface::class, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);

        // 1. ConnectionManager
        if (isset($connectionConfig['url']) && $connectionConfig['url'] !== null) {
            $connDef = new Definition(ConnectionManager::class);
            $connDef->setFactory([self::class, 'createConnectionManagerFromUrl']);
            $connDef->setArguments([
                $connectionConfig['url'],
                $connectionConfig['charset_conversion'] ?? false,
                $loggerRef,
            ]);
        } else {
            $connConfig = [
                'host' => $connectionConfig['host'],
                'port' => $connectionConfig['port'],
                'dbname' => $connectionConfig['database'],
                'username' => $connectionConfig['username'],
                'password' => $connectionConfig['password'],
                'charset' => $connectionConfig['charset'],
                'persistent' => $connectionConfig['persistent'],
                'charset_conversion' => $connectionConfig['charset_conversion'] ?? false,
                'read_only' => $connectionConfig['read_only'] ?? false,
            ];
            $connDef = new Definition(ConnectionManager::class, [$connConfig, $loggerRef]);
        }
        $connDef->setPublic(false);
        $container->setDefinition('sybase_orm.connection_manager' . $suffix, $connDef);

        // 2. IdentityMap (per-connection)
        $imDef = new Definition(IdentityMap::class);
        $imDef->setPublic(false);
        $container->setDefinition('sybase_orm.identity_map' . $suffix, $imDef);

        // 3. CacheManager (per-connection)
        $cacheDef = new Definition(CacheManager::class, [
            new Reference('sybase_orm.identity_map' . $suffix),
            null,
            null,
        ]);
        $cacheDef->setPublic(false);
        $container->setDefinition('sybase_orm.cache_manager' . $suffix, $cacheDef);

        // 4. Hydrator (per-connection)
        $hydDef = new Definition(Hydrator::class, [
            new Reference(MetadataReaderInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference('sybase_orm.identity_map' . $suffix),
            new Reference('sybase_orm.unit_of_work' . $suffix),
            new Reference(ProxyGenerator::class), // Inyectar ProxyGenerator
        ]);

        // Agregar un Setter Injection o inyectar el EntityManagerRegistry
        $hydDef->addMethodCall('setEntityManager', [new Reference('sybase_orm.entity_manager' . $suffix)]);

        $hydDef->setPublic(false);
        $container->setDefinition('sybase_orm.hydrator' . $suffix, $hydDef);

        // 5. UnitOfWork (per-connection)
        $uowDef = new Definition(UnitOfWork::class, [
            new Reference('sybase_orm.connection_manager' . $suffix),
            new Reference(MetadataReaderInterface::class),
            new Reference(DialectInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference('sybase_orm.identity_map' . $suffix),
            new Reference(HookDispatcher::class),
        ]);
        $uowDef->setPublic(false);
        $container->setDefinition('sybase_orm.unit_of_work' . $suffix, $uowDef);

        // 6. EntityManager (per-connection)
        $emDef = new Definition(EntityManager::class, [
            new Reference('sybase_orm.connection_manager' . $suffix),
            new Reference(MetadataReaderInterface::class),
            new Reference(DialectInterface::class),
            new Reference(TypeCasterInterface::class),
            new Reference('sybase_orm.hydrator' . $suffix),
            new Reference('sybase_orm.unit_of_work' . $suffix),
            new Reference('sybase_orm.identity_map' . $suffix),
            new Reference(HookDispatcher::class),
            new Reference('sybase_orm.cache_manager' . $suffix),
            $loggerRef,
        ]);
        $emDef->setPublic(true);
        $emDef->addMethodCall('setEntityDirectories', [$globalConfig['entity_directories']]);
        $container->setDefinition('sybase_orm.entity_manager' . $suffix, $emDef);

        // 7. If this is the first (default) connection, register as the primary services
        if ($isFirst) {
            $container->setAlias(ConnectionManagerInterface::class, 'sybase_orm.connection_manager' . $suffix);
            $container->setAlias(ConnectionManager::class, 'sybase_orm.connection_manager' . $suffix);
            $container->setAlias(IdentityMapInterface::class, 'sybase_orm.identity_map' . $suffix);
            $container->setAlias(IdentityMap::class, 'sybase_orm.identity_map' . $suffix);
            $container->setAlias(CacheManagerInterface::class, 'sybase_orm.cache_manager' . $suffix);
            $container->setAlias(CacheManager::class, 'sybase_orm.cache_manager' . $suffix);
            $container->setAlias(HydratorInterface::class, 'sybase_orm.hydrator' . $suffix);
            $container->setAlias(Hydrator::class, 'sybase_orm.hydrator' . $suffix);
            $container->setAlias(UnitOfWorkInterface::class, 'sybase_orm.unit_of_work' . $suffix);
            $container->setAlias(UnitOfWork::class, 'sybase_orm.unit_of_work' . $suffix);
            $container->setAlias(EntityManagerInterface::class, 'sybase_orm.entity_manager' . $suffix)->setPublic(true);
            $container->setAlias(EntityManager::class, 'sybase_orm.entity_manager' . $suffix)->setPublic(true);
        }
    }
}
