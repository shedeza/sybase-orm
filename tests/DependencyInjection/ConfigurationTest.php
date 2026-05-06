<?php

declare(strict_types=1);

namespace SybaseORM\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use SybaseORM\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testTreeBuilderRootNodeIsSybaseOrm(): void
    {
        $tree = $this->configuration->getConfigTreeBuilder();
        $this->assertSame('sybase_orm', $tree->buildTree()->getName());
    }

    // --- Configuración con parámetros individuales ---

    public function testMinimalValidConfigurationWithParams(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => '192.168.1.100',
                    'database' => 'mydb',
                    'username' => 'sa',
                ],
            ],
        ]);

        $this->assertNull($config['connection']['url']);
        $this->assertSame('192.168.1.100', $config['connection']['host']);
        $this->assertSame(5000, $config['connection']['port']);
        $this->assertSame('mydb', $config['connection']['database']);
        $this->assertSame('sa', $config['connection']['username']);
        $this->assertSame('', $config['connection']['password']);
        $this->assertSame('UTF-8', $config['connection']['charset']);
        $this->assertFalse($config['connection']['persistent']);
    }

    public function testFullConfigurationWithParams(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'sybase.local',
                    'port' => 4100,
                    'database' => 'production',
                    'username' => 'admin',
                    'password' => 'secret',
                    'charset' => 'iso_1',
                    'persistent' => true,
                ],
                'entity_directories' => ['/app/src/Entity', '/app/src/Model'],
                'proxy_directory' => '/tmp/proxies',
                'migrations_directory' => '/app/migrations',
                'cache' => [
                    'enabled' => true,
                    'adapter' => 'redis',
                    'dsn' => 'redis://localhost:6379',
                    'default_ttl' => 7200,
                ],
            ],
        ]);

        $this->assertSame('sybase.local', $config['connection']['host']);
        $this->assertSame(4100, $config['connection']['port']);
        $this->assertSame('production', $config['connection']['database']);
        $this->assertSame('admin', $config['connection']['username']);
        $this->assertSame('secret', $config['connection']['password']);
        $this->assertSame('iso_1', $config['connection']['charset']);
        $this->assertTrue($config['connection']['persistent']);

        $this->assertSame(['/app/src/Entity', '/app/src/Model'], $config['entity_directories']);
        $this->assertSame('/tmp/proxies', $config['proxy_directory']);
        $this->assertSame('/app/migrations', $config['migrations_directory']);

        $this->assertTrue($config['cache']['enabled']);
        $this->assertSame('redis', $config['cache']['adapter']);
        $this->assertSame('redis://localhost:6379', $config['cache']['dsn']);
        $this->assertSame(7200, $config['cache']['default_ttl']);
    }

    // --- Configuración con URL ---

    public function testConfigurationWithUrl(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'url' => 'sybase://sa:secret@192.168.1.100:5000/mi_base?charset=UTF-8',
                ],
            ],
        ]);

        $this->assertSame(
            'sybase://sa:secret@192.168.1.100:5000/mi_base?charset=UTF-8',
            $config['connection']['url'],
        );
    }

    public function testConfigurationWithUrlEnvPlaceholder(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'url' => '%env(DATABASE_URL)%',
                ],
            ],
        ]);

        $this->assertSame('%env(DATABASE_URL)%', $config['connection']['url']);
    }

    // --- Defaults ---

    public function testDefaultValues(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'test',
                    'username' => 'sa',
                ],
            ],
        ]);

        $this->assertSame(['%kernel.project_dir%/src/Entity'], $config['entity_directories']);
        $this->assertSame('%kernel.cache_dir%/sybase_orm/proxies', $config['proxy_directory']);
        $this->assertSame('%kernel.project_dir%/sybase_ase/migrations', $config['migrations_directory']);
        $this->assertFalse($config['cache']['enabled']);
        $this->assertSame('redis', $config['cache']['adapter']);
        $this->assertNull($config['cache']['dsn']);
        $this->assertSame(3600, $config['cache']['default_ttl']);
    }

    // --- Validación ---

    public function testEmptyConfigIsValid(): void
    {
        // connection is now optional at config level (validated in Extension)
        $config = $this->processor->processConfiguration($this->configuration, [[]]);

        $this->assertArrayNotHasKey('connection', $config);
    }

    public function testThrowsWhenNoUrlAndMissingHost(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'database' => 'mydb',
                    'username' => 'sa',
                ],
            ],
        ]);
    }

    public function testThrowsWhenNoUrlAndMissingDatabase(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'username' => 'sa',
                ],
            ],
        ]);
    }

    public function testThrowsWhenNoUrlAndMissingUsername(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'mydb',
                ],
            ],
        ]);
    }

    // --- Soporte env ---

    public function testPortAcceptsStringForEnvSupport(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'port' => '4100',
                    'database' => 'test',
                    'username' => 'sa',
                ],
            ],
        ]);

        $this->assertSame('4100', $config['connection']['port']);
    }

    public function testAllParamsAcceptEnvPlaceholders(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => '%env(SYBASE_HOST)%',
                    'port' => '%env(int:SYBASE_PORT)%',
                    'database' => '%env(SYBASE_DATABASE)%',
                    'username' => '%env(SYBASE_USERNAME)%',
                    'password' => '%env(SYBASE_PASSWORD)%',
                ],
            ],
        ]);

        $this->assertSame('%env(SYBASE_HOST)%', $config['connection']['host']);
        $this->assertSame('%env(int:SYBASE_PORT)%', $config['connection']['port']);
        $this->assertSame('%env(SYBASE_DATABASE)%', $config['connection']['database']);
        $this->assertSame('%env(SYBASE_USERNAME)%', $config['connection']['username']);
        $this->assertSame('%env(SYBASE_PASSWORD)%', $config['connection']['password']);
    }

    // --- Task 13.3: charset_conversion configuration ---

    public function testCharsetConversionOptionAccepted(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'test',
                    'username' => 'sa',
                    'charset_conversion' => true,
                ],
            ],
        ]);

        $this->assertTrue($config['connection']['charset_conversion']);
    }

    public function testCharsetConversionDefaultsToFalse(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'connection' => [
                    'host' => 'localhost',
                    'database' => 'test',
                    'username' => 'sa',
                ],
            ],
        ]);

        $this->assertFalse($config['connection']['charset_conversion']);
    }
}
