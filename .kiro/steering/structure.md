# Project Structure

```
src/
├── Attribute/          # PHP 8.1 attributes for entity mapping
│                       # Entity (table + schema), Column, Id, GeneratedValue,
│                       # relationships (OneToOne, OneToMany, ManyToOne, ManyToMany, JoinColumn),
│                       # inheritance (InheritanceType, DiscriminatorColumn, DiscriminatorMap),
│                       # lifecycle hooks (HasLifecycleHooks, PrePersist, PostPersist, etc.)
├── Cache/              # Two-level caching: CacheManager (first level via IdentityMap),
│                       # SecondLevelCacheInterface, RedisCacheAdapter (second level)
├── Command/            # Symfony console commands:
│                       # sybase:install, sybase:migrations:generate,
│                       # sybase:migrations:migrate, sybase:proxy:generate, sybase:cache:clear
├── Connection/         # PDO dblib connection management (ConnectionManager with dbname validation,
│                       # optional PSR LoggerInterface for charset conversion warnings),
│                       # ConnectionUrlParser (DSN URL parsing),
│                       # transactions, isolation levels, differentiated error handling
├── DependencyInjection/# Symfony bundle DI: Configuration tree (URL + individual params)
│                       # + SybaseORMExtension (factory-based URL resolution for runtime env)
│                       # Registers all services with interface aliases
├── Dialect/            # SQL dialect abstraction: DialectInterface + SybaseDialect
│                       # (pagination, identity, NULL handling, schema-qualified quoting,
│                       # SELECT * without quoting asterisk)
├── Exception/          # SybaseORMException (base) → ConnectionLostException, PersistenceException,
│                       # TransactionException, TypeConversionException, OqlParseException,
│                       # MigrationException
├── Hook/               # HookDispatcher: reads lifecycle hooks from ClassMetadata,
│                       # invokes annotated methods, propagates exceptions
├── Hydrator/           # Hydrator: converts PDO result arrays → entity instances
│                       # via cached ReflectionClass, uses ClassMetadata.getColumnByName()
│                       # for O(1) column lookup, integrates TypeCaster + IdentityMap,
│                       # supports eager-loaded relationships via prefixed columns
├── Metadata/           # MetadataReader: reads PHP attributes → ClassMetadata
│                       # (with schema support, reads inherited private properties),
│                       # ColumnMetadata, RelationshipMetadata
│                       # In-memory (static array) + optional file-based caching
│                       # ClassMetadata has O(1) indexed maps for column lookup
├── Migration/          # MigrationManager: schema diff, migration file generation,
│                       # execution with version tracking, rollback support
├── ORM/                # Core ORM components:
│                       # EntityManager (cached OqlParser, pre-computed entity maps,
│                       #   queryIterator() for streaming results via Generator),
│                       # UnitOfWork (cached ReflectionProperty, FK propagation,
│                       #   circular dependency detection, full lifecycle hook dispatch),
│                       # IdentityMap (composite key derivation via deterministic string),
│                       # EntityRepository (cached shortName, findOneBy, count, exists),
│                       # InheritanceHandler (TPH/TPT/TPC),
│                       # HydrationMode (HYDRATE_OBJECT / HYDRATE_ARRAY)
├── Proxy/              # LazyLoadingProxy interface + ProxyGenerator
│                       # (generates proxy classes, caches on disk,
│                       # conditional __serialize override)
├── Query/              # Query layer:
│   ├── AST/            #   AST nodes: SelectStatement, FromClause, WhereClause,
│   │                   #   JoinClause, OrderByClause, GroupByClause, PropertyAccess,
│   │                   #   Comparison, LogicalExpression, Parameter, Literal,
│   │                   #   SelectExpression, OrderByItem, IsNullExpression,
│   │                   #   InExpression, FunctionCall, HavingClause
│   ├── OqlParser       #   OQL string → AST (tokenizer + recursive descent parser)
│   ├── OqlPrinter      #   AST → readable OQL text
│   ├── OqlToSqlTranslator # AST → Sybase SQL using dialect + metadata resolution
│   └── QueryBuilder    #   Fluent programmatic query construction (with HAVING support)
├── Type/               # TypeCaster: PHP ↔ Sybase type conversion
│                       # (bool↔BIT, DateTime↔Sybase format, BackedEnum↔scalar)
│                       # CustomTypeInterface for Value Objects (instances cached)
└── SybaseORMBundle.php # Bundle entry point (extends AbstractBundle)

config/
├── packages/
│   └── sybase_orm.yaml # Default bundle configuration (Flex recipe template)
└── routes/
    └── sybase_orm.yaml # Empty routes file (Flex convention)

tests/
├── {Component}/        # Mirrors src/ structure
│   ├── Fixtures/       # Test entity classes and helpers
│   └── *Test.php       # PHPUnit test classes

manifest.json           # Symfony Flex recipe
```

## Key Patterns
- Each `src/` subdirectory is a self-contained component with an interface + implementation pair
- Interfaces live alongside their implementations
- Test directories mirror source directories 1:1
- `.gitkeep` files are present in directories that may be empty
- The bundle config key is `sybase_orm`
- Connection config supports two modes: URL (`DATABASE_URL`) or individual params
- URL mode uses a static factory for runtime env var resolution
- Performance: UnitOfWork caches ReflectionProperty, Hydrator caches ReflectionClass, TypeCaster caches custom type instances, EntityManager caches OqlParser + pre-computes entity shortName maps, EntityRepository caches shortName, ClassMetadata uses O(1) indexed maps, MetadataReader uses constant map for lifecycle hook names
- IdentityMap supports composite key derivation: scalar ids cast to string, array ids are ksorted and pipe-joined for deterministic keys
- ConnectionManager supports transparent charset conversion (UTF-8 ↔ ISO-8859-1) via `charset_conversion` config option and `convertResultRow()` method
- Exception hierarchy: all ORM exceptions extend SybaseORMException
- MetadataReader reads inherited private properties from parent classes
- UnitOfWork detects circular dependencies and dispatches all 6 lifecycle hooks
