# Product Overview

SybaseORM is a Symfony Bundle that provides a custom ORM for Sybase ASE (Adaptive Server Enterprise) databases. It follows Doctrine-inspired patterns but is purpose-built for Sybase ASE's specific SQL dialect and limitations (e.g., `TOP` instead of `LIMIT`, `@@identity`, `ANSINULL` handling).

## Core Capabilities

- Entity mapping via PHP 8.1+ attributes (`#[Entity]`, `#[Column]`, `#[Id]`, etc.) with optional schema support (`#[Entity(table: 'invoices', schema: 'billing')]`)
- Metadata reading from attributes using Reflection API with in-memory and file caching; reads inherited private properties from parent classes
- Unit of Work pattern with dirty checking via property snapshots, cached ReflectionProperty, automatic FK propagation on cascade inserts, circular dependency detection in topological sort, and full lifecycle hook dispatch (PrePersist, PostPersist, PreUpdate, PostUpdate, PreRemove, PostRemove)
- Identity Map to guarantee one instance per entity per session
- OQL (Object Query Language) — a custom query language parsed into AST nodes (cached parser instance), then translated to Sybase-compatible SQL
- QueryBuilder for programmatic query construction with fluent API
- Lazy-loading proxies generated at runtime (only overrides `__serialize` when the entity defines it)
- Relationship mapping: OneToOne, OneToMany, ManyToOne, ManyToMany with cascade persist support
- Inheritance strategies: TPH (Table Per Hierarchy), TPT (Table Per Type), TPC (Table Per Concrete Class) with discriminator columns
- Lifecycle hooks: PrePersist, PostPersist, PreUpdate, PostUpdate, PreRemove, PostRemove — dispatched at the correct moment during flush
- Type casting between PHP types and Sybase ASE types, with BackedEnum support and custom type registration (instances cached)
- Two-level caching: Identity Map (first level, always active) + optional Redis second-level cache with graceful fallback
- Connection management via PDO dblib with transaction support, isolation levels, dbname validation, and differentiated exception handling (ConnectionLostException vs PersistenceException), all inheriting from SybaseORMException base
- Connection URL support: `sybase://user:pass@host:port/database?charset=UTF-8`, parsed at runtime via factory for full `%env()%` compatibility
- Schema migration generation and execution with schema-qualified table name support
- EntityRepository with `find()`, `findAll()`, `findBy()`, `findOneBy()` methods (cached shortName)
- ClassMetadata with O(1) column lookup via pre-computed indexed maps (`getColumn()`, `getColumnByName()`)
- Hydrator with cached ReflectionClass instances, uses ClassMetadata indexed maps for efficient hydration
- Symfony DI integration with bundle configuration under `sybase_orm` key, factory-based URL resolution
- Symfony Flex recipe (`manifest.json`) + `sybase:install` command for automatic setup
- Console commands: `sybase:install`, `sybase:migrations:generate`, `sybase:migrations:migrate`, `sybase:proxy:generate`, `sybase:cache:clear`

## Target Database

Sybase ASE, connected via the `pdo_dblib` PHP extension. The dialect layer abstracts Sybase-specific SQL (schema-qualified identifiers like `[billing].[invoices]`, TOP/ROW_NUMBER pagination, @@identity, `SELECT *` without quoting the asterisk) so it could theoretically be swapped for another DBMS via `DialectInterface`.

## Test Coverage

510 tests, 1113 assertions — covering all components from attributes through Symfony integration.
