<?php

declare(strict_types=1);

namespace SybaseORM\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\DependencyInjection\SybaseORMExtension;
use SybaseORM\Dialect\DialectInterface;use SybaseORM\Dialect\SybaseDialect;
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

final class SybaseORMExtensionTest extends TestCase
{
    private ContainerBuilder $container;
    private SybaseORMExtension $extension;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->extension = new SybaseORMExtension();
    }

    private function loadMinimalConfig(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'testdb',
                    'username' => 'sa',
                ],
            ],
        ], $this->container);
    }

    public function testExtensionAlias(): void
    {
        $this->assertSame('sybase_orm', $this->extension->getAlias());
    }

    public function testRegistersConnectionManager(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(ConnectionManager::class));
        $this->assertTrue($this->container->hasAlias(ConnectionManagerInterface::class));

        $definition = $this->container->findDefinition(ConnectionManager::class);
        $this->assertSame(ConnectionManager::class, $definition->getClass());

        $args = $definition->getArguments();
        $this->assertSame('localhost', $args[0]['host']);
        $this->assertSame(5000, $args[0]['port']);
        $this->assertSame('testdb', $args[0]['dbname']);
        $this->assertSame('sa', $args[0]['username']);
    }

    public function testRegistersDialect(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(SybaseDialect::class));
        $this->assertTrue($this->container->hasAlias(DialectInterface::class));
    }

    public function testRegistersTypeCaster(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(TypeCaster::class));
        $this->assertTrue($this->container->hasAlias(TypeCasterInterface::class));
    }

    public function testRegistersMetadataReader(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(MetadataReader::class));
        $this->assertTrue($this->container->hasAlias(MetadataReaderInterface::class));
    }

    public function testRegistersIdentityMap(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(IdentityMap::class));
        $this->assertTrue($this->container->hasAlias(IdentityMapInterface::class));
    }

    public function testRegistersCacheManager(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(CacheManager::class));
        $this->assertTrue($this->container->hasAlias(CacheManagerInterface::class));
    }

    public function testRegistersHydrator(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(Hydrator::class));
        $this->assertTrue($this->container->hasAlias(HydratorInterface::class));
    }

    public function testRegistersUnitOfWork(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(UnitOfWork::class));
        $this->assertTrue($this->container->hasAlias(UnitOfWorkInterface::class));
    }

    public function testRegistersHookDispatcher(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(HookDispatcher::class));
    }

    public function testRegistersProxyGenerator(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(ProxyGenerator::class));
    }

    public function testRegistersMigrationManager(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(MigrationManager::class));
    }

    public function testRegistersEntityManager(): void
    {
        $this->loadMinimalConfig();

        $this->assertTrue($this->container->has(EntityManager::class));
        $this->assertTrue($this->container->hasAlias(EntityManagerInterface::class));

        $definition = $this->container->findDefinition(EntityManager::class);
        $this->assertTrue($definition->isPublic());
    }

    public function testEntityManagerInterfaceAliasIsPublic(): void
    {
        $this->loadMinimalConfig();

        $alias = $this->container->getAlias(EntityManagerInterface::class);
        $this->assertTrue($alias->isPublic());
    }

    public function testStoresConfigParameters(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'testdb',
                    'username' => 'sa',
                ],
                'entity_directories' => ['/app/Entity'],
                'proxy_directory' => '/tmp/proxies',
                'migrations_directory' => '/app/migrations',
            ],
        ], $this->container);

        $this->assertSame(['/app/Entity'], $this->container->getParameter('sybase_orm.entity_directories'));
        $this->assertSame('/tmp/proxies', $this->container->getParameter('sybase_orm.proxy_directory'));
        $this->assertSame('/app/migrations', $this->container->getParameter('sybase_orm.migrations_directory'));
    }

    public function testConnectionConfigWithCustomPort(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'host' => 'db.example.com',
                    'port' => 4100,
                    'database' => 'prod',
                    'username' => 'admin',
                    'password' => 'secret',
                    'charset' => 'iso_1',
                    'persistent' => true,
                ],
            ],
        ], $this->container);

        $definition = $this->container->findDefinition(ConnectionManager::class);
        $args = $definition->getArguments();

        $this->assertSame('db.example.com', $args[0]['host']);
        $this->assertSame(4100, $args[0]['port']);
        $this->assertSame('prod', $args[0]['dbname']);
        $this->assertSame('admin', $args[0]['username']);
        $this->assertSame('secret', $args[0]['password']);
        $this->assertSame('iso_1', $args[0]['charset']);
        $this->assertTrue($args[0]['persistent']);
    }

    public function testProxyDirectoryPassedToProxyGenerator(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'testdb',
                    'username' => 'sa',
                ],
                'proxy_directory' => '/custom/proxies',
            ],
        ], $this->container);

        $definition = $this->container->findDefinition(ProxyGenerator::class);
        $args = $definition->getArguments();
        $this->assertSame('/custom/proxies', $args[0]);
    }

    public function testMigrationsDirectoryPassedToMigrationManager(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'testdb',
                    'username' => 'sa',
                ],
                'migrations_directory' => '/custom/migrations',
            ],
        ], $this->container);

        $definition = $this->container->findDefinition(MigrationManager::class);
        $args = $definition->getArguments();
        $this->assertSame('/custom/migrations', $args[3]);
    }

    public function testConnectionConfigFromUrl(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'url' => 'sybase://admin:secret@db.example.com:4100/production?charset=iso_1&persistent=true',
                ],
            ],
        ], $this->container);

        $definition = $this->container->findDefinition(ConnectionManager::class);

        // URL mode usa factory para resolver en runtime
        $factory = $definition->getFactory();
        $this->assertNotNull($factory);
        $this->assertSame(SybaseORMExtension::class, $factory[0]);
        $this->assertSame('createConnectionManagerFromUrl', $factory[1]);

        // Verificar que la URL se pasa como argumento
        $args = $definition->getArguments();
        $this->assertSame(
            'sybase://admin:secret@db.example.com:4100/production?charset=iso_1&persistent=true',
            $args[0],
        );
    }

    public function testUrlTakesPriorityOverIndividualParams(): void
    {
        $this->extension->load([
            [
                'connection' => [
                    'url' => 'sybase://url_user:url_pass@url_host:9999/url_db',
                    'host' => 'ignored_host',
                    'database' => 'ignored_db',
                    'username' => 'ignored_user',
                ],
            ],
        ], $this->container);

        $definition = $this->container->findDefinition(ConnectionManager::class);

        // Debe usar factory (URL mode), no parámetros directos
        $factory = $definition->getFactory();
        $this->assertNotNull($factory);
        $this->assertStringContainsString('url_host', $definition->getArguments()[0]);
    }

    public function testCreateConnectionManagerFromUrlFactory(): void
    {
        $cm = SybaseORMExtension::createConnectionManagerFromUrl(
            'sybase://admin:secret@db.example.com:4100/production?charset=iso_1&persistent=true',
        );

        $this->assertInstanceOf(ConnectionManager::class, $cm);
    }
}
