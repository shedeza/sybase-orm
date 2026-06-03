# shedeza/sybase-orm-bundle

Symfony bundle providing full framework integration for the [shedeza/sybase-orm](https://github.com/shedeza/sybase-orm) library. Registers ORM services in the dependency injection container, provides console commands, profiler integration, and Flex recipe support.

## Installation

```bash
composer require shedeza/sybase-orm-bundle
```

## Bundle Registration

### Symfony Flex (automatic)

If your project uses Symfony Flex, the bundle is registered automatically. No manual configuration is needed.

### Without Flex (manual)

Add the bundle to your `config/bundles.php`:

```php
<?php

return [
    // ...
    SybaseORM\Bundle\SybaseORMBundle::class => ['all' => true],
];
```

## Configuration Reference

The bundle uses the `sybase_orm` configuration key. Below is the full reference with all available options:

```yaml
# config/packages/sybase_orm.yaml
sybase_orm:

    # Single connection (simple setup)
    connection:
        url: '%env(DATABASE_URL)%'          # DSN URL (alternative to individual params)
        host: '127.0.0.1'                   # Database server host
        port: 5000                          # Database server port
        database: 'my_database'             # Database name
        username: 'sa'                      # Authentication username
        password: 'secret'                  # Authentication password
        charset: 'UTF-8'                    # Connection character set
        persistent: false                   # Use persistent connections
        charset_conversion: false           # Enable UTF-8 ↔ ISO-8859-1 conversion
        read_only: false                    # Mark connection as read-only

    # Multiple named connections
    connections:
        default:
            url: '%env(DATABASE_URL)%'
        reporting:
            host: 'reporting-server'
            port: 5000
            database: 'reports'
            username: 'reader'
            password: 'secret'
            read_only: true

    # Entity mapping directories
    entity_directories:
        - '%kernel.project_dir%/src/Entity'   # Default

    # Directory for generated proxy classes
    proxy_directory: '%kernel.cache_dir%/sybase_orm/proxies'

    # Directory for migration files
    migrations_directory: '%kernel.project_dir%/migrations'

    # Cache configuration
    cache:
        enabled: true                        # Enable metadata/query caching
        adapter: 'cache.app'                 # Symfony cache adapter service ID
        dsn: 'redis://localhost:6379'        # Cache DSN (for standalone adapters)
        default_ttl: 3600                    # Default cache TTL in seconds
```

> **Note:** Use either `connection` for a single connection or `connections` for multiple named connections, not both. When using `url`, individual parameters (host, port, etc.) are ignored.

## Console Commands

| Command | Description |
|---------|-------------|
| `sybase:install` | Installs and configures SybaseORM in the current project |
| `sybase:migrations:generate` | Generates a migration file from entity changes |
| `sybase:migrations:migrate` | Executes pending database migrations |
| `sybase:proxy:generate` | Generates lazy-loading proxy classes |
| `sybase:cache:clear` | Clears the ORM metadata and query cache |
| `sybase:schema:validate` | Validates entity mapping against the database schema |

## Services

The bundle registers the following services in the DI container, available for autowiring:

| Interface | Concrete Class |
|-----------|---------------|
| `SybaseORM\ORM\EntityManagerInterface` | `SybaseORM\ORM\EntityManager` |
| `SybaseORM\Connection\ConnectionManagerInterface` | `SybaseORM\Connection\ConnectionManager` |
| `SybaseORM\Metadata\MetadataReaderInterface` | `SybaseORM\Metadata\MetadataReader` |
| `SybaseORM\Dialect\DialectInterface` | `SybaseORM\Dialect\SybaseDialect` |
| `SybaseORM\ORM\UnitOfWorkInterface` | `SybaseORM\ORM\UnitOfWork` |
| `SybaseORM\ORM\IdentityMapInterface` | `SybaseORM\ORM\IdentityMap` |
| `SybaseORM\Hydrator\HydratorInterface` | `SybaseORM\Hydrator\Hydrator` |
| `SybaseORM\Type\TypeCasterInterface` | `SybaseORM\Type\TypeCaster` |
| `SybaseORM\Cache\CacheManagerInterface` | `SybaseORM\Cache\CacheManager` |

Additional services registered without interface aliases:

- `SybaseORM\Hook\HookDispatcher`
- `SybaseORM\Proxy\ProxyGenerator`
- `SybaseORM\Migration\MigrationManager`

Entity repositories are automatically autowired via the `RepositoryAutowiringCompilerPass`.

## Upgrading from v2.x

If you are upgrading from the monolithic `shedeza/sybase-orm` v2.x package, see [MIGRATION.md](../../MIGRATION.md) for a step-by-step guide covering Composer dependency changes and namespace updates.

## License

MIT
