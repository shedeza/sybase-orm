<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use Psr\Log\LoggerInterface;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Connection\ConnectionUrlParser;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\Type\TypeCaster;

/**
 * Factory for creating a fully configured EntityManager without a DI container.
 *
 * Provides container-free instantiation for non-Symfony usage (Laravel, Slim, standalone scripts).
 */
final class OrmFactory
{
    /**
     * Creates a fully configured EntityManager from a configuration array.
     *
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException When the "connection" key is missing from config.
     */
    public static function create(array $config, ?LoggerInterface $logger = null): EntityManagerInterface
    {
        if (!array_key_exists('connection', $config)) {
            throw new \InvalidArgumentException('OrmFactory requires a "connection" configuration key.');
        }

        // 1. Build ConnectionManager (from array or URL string)
        $connectionConfig = $config['connection'];
        if (is_string($connectionConfig)) {
            $connectionConfig = ConnectionUrlParser::parse($connectionConfig);
        }

        // Instrumentation (null-object pattern — zero overhead if not configured)
        $instrumentation = $config['instrumentation'] ?? null;
        if ($instrumentation !== null && !($instrumentation instanceof \SybaseORM\Instrumentation\OrmInstrumentationInterface)) {
            $instrumentation = null;
        }

        $connectionManager = new ConnectionManager($connectionConfig, $logger, $instrumentation);

        // 2. Instantiate SybaseDialect, TypeCaster, MetadataReader
        $dialect = new SybaseDialect();
        $typeCaster = new TypeCaster();
        $filePermissions = $config['file_permissions'] ?? 0o666;
        $directoryPermissions = $config['directory_permissions'] ?? 0o777;

        $metadataReader = new MetadataReader(
            cacheDir: $config['metadata_cache_dir'] ?? null,
            directoryPermissions: $directoryPermissions,
            filePermissions: $filePermissions,
        );

        // 3. Instantiate IdentityMap, CacheManager, HookDispatcher
        $identityMap = new IdentityMap();

        // Build second-level cache if configured
        $secondLevel = $config['second_level_cache'] ?? null;

        if ($secondLevel === null && isset($config['redis'])) {
            // Auto-create RedisCacheAdapter from redis config
            $redisConfig = $config['redis'];
            $redis = new \Redis();
            $redis->connect(
                $redisConfig['host'] ?? '127.0.0.1',
                $redisConfig['port'] ?? 6379,
                $redisConfig['timeout'] ?? 2.0,
            );

            if (isset($redisConfig['password']) && $redisConfig['password'] !== '') {
                $redis->auth($redisConfig['password']);
            }

            if (isset($redisConfig['database'])) {
                $redis->select((int) $redisConfig['database']);
            }

            $prefix = $redisConfig['prefix'] ?? 'sybase_orm:';
            $secondLevel = new \SybaseORM\Cache\RedisCacheAdapter($redis, $prefix);
        }

        $cacheManager = new CacheManager(
            identityMap: $identityMap,
            secondLevel: $secondLevel instanceof \SybaseORM\Cache\SecondLevelCacheInterface ? $secondLevel : null,
            logger: $logger,
        );
        $hookDispatcher = new HookDispatcher(
            metadataReader: $metadataReader,
        );

        // 4. Instantiate UnitOfWork, Hydrator
        $unitOfWork = new UnitOfWork(
            connectionManager: $connectionManager,
            metadataReader: $metadataReader,
            dialect: $dialect,
            typeCaster: $typeCaster,
            identityMap: $identityMap,
            hookDispatcher: $hookDispatcher,
        );

        $proxyDirectory = $config['proxy_directory'] ?? sys_get_temp_dir() . '/sybase-orm-proxies';
        $proxyGenerator = new ProxyGenerator($proxyDirectory, $directoryPermissions, $filePermissions);

        $hydrator = new Hydrator(
            metadataReader: $metadataReader,
            typeCaster: $typeCaster,
            identityMap: $identityMap,
            unitOfWork: $unitOfWork,
            proxyGenerator: $proxyGenerator,
        );

        // 5. Instantiate EntityManager, wire entity directories
        $entityManager = new EntityManager(
            connectionManager: $connectionManager,
            metadataReader: $metadataReader,
            dialect: $dialect,
            typeCaster: $typeCaster,
            hydrator: $hydrator,
            unitOfWork: $unitOfWork,
            identityMap: $identityMap,
            hookDispatcher: $hookDispatcher,
            cacheManager: $cacheManager,
            logger: $logger,
        );

        // Wire EntityManager into Hydrator for lazy-loading proxy creation
        $hydrator->setEntityManager($entityManager);

        // Wire entity directories if provided
        if (isset($config['entity_directories'])) {
            $entityManager->setEntityDirectories($config['entity_directories']);
        }

        // Wire entity classes if provided
        if (isset($config['entity_classes'])) {
            $entityManager->setEntityClasses($config['entity_classes']);
        }

        // 6. Return EntityManagerInterface
        return $entityManager;
    }

    /**
     * Creates an EntityManager from a DSN URL.
     *
     * @param string $url e.g. "sybase://sa:secret@host:5000/mydb?charset=UTF-8"
     * @param string[] $entityDirectories
     *
     * @throws \InvalidArgumentException When the URL cannot be parsed.
     */
    public static function createFromUrl(string $url, array $entityDirectories = [], ?LoggerInterface $logger = null): EntityManagerInterface
    {
        $config = [
            'connection' => ConnectionUrlParser::parse($url),
            'entity_directories' => $entityDirectories,
        ];

        return self::create($config, $logger);
    }
}
