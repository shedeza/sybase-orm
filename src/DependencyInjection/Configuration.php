<?php

declare(strict_types=1);

namespace SybaseORM\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Define el árbol de configuración del bundle SybaseORM.
 *
 * Soporta dos modos de configuración de conexión:
 * 1. URL única: sybase_orm.connection.url (estilo DATABASE_URL)
 * 2. Parámetros individuales: host, port, database, username, password
 *
 * Cuando se proporciona `url`, los parámetros individuales se ignoran.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sybase_orm');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('connection')
                    ->isRequired()
                    ->children()
                        ->scalarNode('url')
                            ->defaultNull()
                            ->info('URL de conexión completa. Formato: sybase://usuario:password@host:puerto/base_de_datos?charset=UTF-8&persistent=true. Soporta %env(DATABASE_URL)%. Cuando se define, los parámetros individuales (host, port, etc.) se ignoran.')
                        ->end()
                        ->scalarNode('host')
                            ->defaultNull()
                            ->info('Host del servidor Sybase ASE. Soporta %env(SYBASE_HOST)%.')
                        ->end()
                        ->scalarNode('port')
                            ->defaultValue(5000)
                            ->info('Puerto del servidor. Soporta %env(int:SYBASE_PORT)%.')
                        ->end()
                        ->scalarNode('database')
                            ->defaultNull()
                            ->info('Nombre de la base de datos. Soporta %env(SYBASE_DATABASE)%.')
                        ->end()
                        ->scalarNode('username')
                            ->defaultNull()
                            ->info('Usuario de conexión. Soporta %env(SYBASE_USERNAME)%.')
                        ->end()
                        ->scalarNode('password')
                            ->defaultValue('')
                            ->info('Contraseña de conexión. Soporta %env(SYBASE_PASSWORD)%.')
                        ->end()
                        ->scalarNode('charset')
                            ->defaultValue('UTF-8')
                        ->end()
                        ->booleanNode('persistent')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(function (array $v) {
                            // Si no hay URL, host/database/username son obligatorios
                            return $v['url'] === null
                                && ($v['host'] === null || $v['database'] === null || $v['username'] === null);
                        })
                        ->thenInvalid('La conexión requiere "url" o los parámetros "host", "database" y "username".')
                    ->end()
                ->end()
                ->arrayNode('entity_directories')
                    ->scalarPrototype()->end()
                    ->defaultValue(['%kernel.project_dir%/src/Entity'])
                ->end()
                ->scalarNode('proxy_directory')
                    ->defaultValue('%kernel.cache_dir%/sybase_orm/proxies')
                ->end()
                ->scalarNode('migrations_directory')
                    ->defaultValue('%kernel.project_dir%/sybase_ase/migrations')
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('adapter')
                            ->defaultValue('redis')
                        ->end()
                        ->scalarNode('dsn')
                            ->defaultNull()
                        ->end()
                        ->integerNode('default_ttl')
                            ->defaultValue(3600)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
