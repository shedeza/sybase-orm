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
     * @param array{
     *     connection: array{host?: string, port?: int, dbname: string, username?: string, password?: string, charset?: string, persistent?: bool, charset_conversion?: bool, read_only?: bool}|string,
     *     entity_directories?: string[],
     *     entity_classes?: string[],
     *     proxy_directory?: string,
     *     metadata_cache_dir?: string|null,
     * } $config
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

        $connectionManager = new ConnectionManager($connectionConfig, $logger);

        // 2. Instantiate SybaseDialect, TypeCaster, MetadataReader
        $dialect = new SybaseDialect();
        $typeCaster = new TypeCaster();
        $metadataReader = new MetadataReader(
            cacheDir: $config['metadata_cache_dir'] ?? null,
        );

        // 3. Instantiate IdentityMap, CacheManager, HookDispatcher
        $identityMap = new IdentityMap();
        $cacheManager = new CacheManager(
            identityMap: $identityMap,
            secondLevel: null,
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
        $proxyGenerator = new ProxyGenerator($proxyDirectory);

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
