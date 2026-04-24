# Tech Stack & Build

## Language & Runtime
- PHP 8.1+ with `declare(strict_types=1)` in every file
- Required extension: `pdo_dblib`

## Framework
- Symfony Bundle (supports Symfony 6.x and 7.x)
- `symfony/framework-bundle` for DI and bundle integration
- `symfony/console` ^6.0|^7.0|^8.0 for CLI commands
- Symfony Flex recipe support via `manifest.json`

## Dependencies
- Production: `symfony/framework-bundle`, `symfony/console`, `psr/log`, `ext-pdo_dblib`
- Dev: `phpunit/phpunit` ^10.0

## Package Manager
- Composer (PSR-4 autoloading)
- Root namespace: `SybaseORM\` → `src/`
- Test namespace: `SybaseORM\Tests\` → `tests/`
- `extra.symfony` section in composer.json for Flex integration

## Common Commands

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/Attribute/ColumnTest.php

# Run tests with filter
vendor/bin/phpunit --filter testDefaults
```

## Testing
- PHPUnit 10 with XML config in `phpunit.xml`
- Single test suite named "Unit" covering `tests/` directory
- Source coverage configured for `src/` directory
- `failOnRisky` and `failOnWarning` enabled
- Test fixtures live in `tests/{Component}/Fixtures/` directories
- Tests use `final class` and extend `PHPUnit\Framework\TestCase`
- 2864 tests, 11749 assertions across all components
- Property-based tests using `@dataProvider` with 100+ iterations for thorough input coverage

## Code Style Conventions
- Every PHP file starts with `<?php` followed by `declare(strict_types=1);`
- All classes are `final` unless designed for inheritance (entity fixtures, `ConnectionManager`, `EntityRepository` may not be final)
- Constructor promotion with `readonly` properties is the standard pattern
- Interfaces are suffixed with `Interface` (e.g., `EntityManagerInterface`)
- PHPDoc `@template`, `@param`, and `@return` annotations used for generic typing
- Named arguments used in constructor calls and method invocations
- Some docblocks are written in Spanish (this is intentional, preserve the language when editing existing docs)
- Performance-sensitive code caches `ReflectionProperty` and `ReflectionClass` instances, pre-computes lookup maps, and uses O(1) indexed maps in ClassMetadata
- `Types` dictionary class (`SybaseORM\Type\Types`) provides constants for all supported column types (`Types::STRING`, `Types::INTEGER`, etc.)
- `TypeCaster` supports Sybase type aliases: `real`, `tinyint`, `smallint`, `bigint` in addition to standard types
- `EntityManager::registerOqlFunction()` registers custom SQL functions for OQL; invalidates query cache

## Connection Configuration
- Two modes: URL-based (`DATABASE_URL`) or individual parameters
- URL format: `sybase://user:pass@host:port/database?charset=UTF-8&persistent=true`
- URL mode uses a static factory method in `SybaseORMExtension` for runtime resolution (compatible with `%env()%`)
- Individual params mode passes config array directly to `ConnectionManager` constructor
- `ConnectionManager` validates `dbname` is non-empty at construction time; port validated lazily on first `getConnection()` call
- `ConnectionManager` maintains a prepared statement cache (`stmtCache`) for reusing `PDOStatement` instances; auto-cleared on reconnect
- `ConnectionManager` exposes `ping()` (connection health check) and `getServerVersion()` (Sybase ASE version string)
- `ConnectionUrlParser` handles DSN parsing with URL-encoded password support
- Query logging: `ConnectionManager` accepts optional PSR-3 `LoggerInterface` for charset conversion warnings

## Exception Hierarchy
- `SybaseORMException` (base) → `ConnectionLostException`, `PersistenceException`, `TransactionException`, `TypeConversionException`, `MigrationException`, `OqlParseException`
- Allows catching all ORM errors with a single `catch (SybaseORMException $e)`
