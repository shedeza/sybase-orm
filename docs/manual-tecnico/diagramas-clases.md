# Diagramas de Clases

Diagramas Mermaid de las clases principales por componente del ORM.
## Connection

```mermaid
classDiagram
    class ConnectionManagerInterface {
        <<interface>>
        +getConnection() PDO
        +executeQuery(sql, params) PDOStatement
        +executeStatement(sql, params) int
        +beginTransaction() void
        +commit() void
        +rollback() void
        +ping() bool
    }

    class ConnectionManager {
        +createSavepoint() string
        +rollbackToSavepoint(name) void
        +releaseSavepoint(name) void
        +reconnect() void
        +isReadOnly() bool
        +isDeadlock(e)$ bool
    }

    class ConnectionUrlParser {
        +parse(url) array
    }
    class RetryConnectionManager {
        +executeQuery(sql, params) PDOStatement
        +executeStatement(sql, params) int
    }
    class SqlParameterExpander

    ConnectionManagerInterface <|.. ConnectionManager
    ConnectionManagerInterface <|.. RetryConnectionManager
    RetryConnectionManager --> ConnectionManager
    ConnectionManager --> SqlParameterExpander
    ConnectionManager --> ConnectionUrlParser
```

## Metadata

```mermaid
classDiagram
    class MetadataReaderInterface {
        <<interface>>
        +getClassMetadata(entityClass) ClassMetadata
        +isEntity(className) bool
    }

    class MetadataReader {
        +getClassMetadata(entityClass) ClassMetadata
        +isEntity(className) bool
    }

    class ClassMetadata {
        +entityClass string
        +tableName string
        +columns ColumnMetadata[]
        +relationships RelationshipMetadata[]
        +lifecycleHooks array
        +embeddeds EmbeddedMetadata[]
        +getColumn(propertyName) ColumnMetadata?
        +getQualifiedTableName() string
    }

    class ColumnMetadata {
        +propertyName string
        +columnName string
        +type string
        +nullable bool
        +isId bool
    }

    class RelationshipMetadata {
        +propertyName string
        +type string
        +targetEntity string
    }

    class EmbeddedMetadata {
        +propertyName string
        +embeddableClass string
    }

    MetadataReaderInterface <|.. MetadataReader
    MetadataReader --> ClassMetadata : crea
    ClassMetadata *-- ColumnMetadata
    ClassMetadata *-- RelationshipMetadata
    ClassMetadata *-- EmbeddedMetadata
```

## ORM

```mermaid
classDiagram
    class EntityManagerInterface {
        <<interface>>
        +persist(entity) void
        +remove(entity) void
        +flush() void
        +find(entityClass, id) object?
        +query(oql, params) array
        +queryCached(oql, params, ttl) array
        +createQueryBuilder(entityClass) QueryBuilderInterface
        +transactional(callback) mixed
        +getRepository(entityClass) EntityRepository
    }

    class EntityManager {
        -connectionManager ConnectionManagerInterface
        -metadataReader MetadataReaderInterface
        -unitOfWork UnitOfWorkInterface
        -identityMap IdentityMapInterface
        -cacheManager CacheManagerInterface
        -hookDispatcher HookDispatcher
        -proxyGenerator ProxyGenerator
    }

    class UnitOfWorkInterface {
        <<interface>>
        +registerNew(entity) void
        +registerDeleted(entity) void
        +registerClean(entity) void
        +commit() void
        +computeChangeset(entity) array
    }

    class IdentityMapInterface {
        <<interface>>
        +put(entityClass, id, entity) void
        +get(entityClass, id) object?
        +contains(entityClass, id) bool
        +clear() void
    }

    class EntityRepository {
        +find(id) object?
        +findAll() array
        +findBy(criteria) array
        +save(entity) void
        +delete(entity) void
        +count(criteria) int
    }

    class EntityManagerRegistry {
        +get(name) EntityManagerInterface
    }

    class OrmFactory {
        +create(config) EntityManager
    }

    EntityManagerInterface <|.. EntityManager
    EntityManager --> UnitOfWorkInterface
    EntityManager --> IdentityMapInterface
    EntityManager --> EntityRepository : crea
    EntityManagerRegistry --> EntityManagerInterface
    OrmFactory --> EntityManager : crea
```

## Query

```mermaid
classDiagram
    class QueryBuilderInterface {
        <<interface>>
        +select(columns) static
        +from(from, alias?) static
        +where(condition, params) static
        +join(join, alias, condition) static
        +orderBy(column, direction) static
        +limit(limit) static
        +offset(offset) static
        +with(relations) static
        +getSQL() string
    }

    class QueryBuilder
    class OqlParser {
        +parse(oql) Statement
        +registerFunction(name) void
    }
    class OqlToSqlTranslator {
        +translate(statement) array
        +registerFunction(name, sql) void
    }
    class OqlPrinter

    QueryBuilderInterface <|.. QueryBuilder
    OqlParser --> OqlToSqlTranslator : AST
    OqlToSqlTranslator --> DialectInterface
```

## Cache

```mermaid
classDiagram
    class CacheManagerInterface {
        <<interface>>
        +get(entityClass, id) object?
        +put(entityClass, id, entity) void
        +invalidate(entityClass, id) void
        +putQueryResult(queryKey, result, ttl?) void
        +getQueryResult(queryKey) array?
        +clear() void
        +isSecondLevelAvailable() bool
    }

    class CacheManager
    class SecondLevelCacheInterface {
        <<interface>>
        +get(key) mixed
        +put(key, value, ttl?) void
        +delete(key) void
        +has(key) bool
        +clear() void
    }
    class RedisCacheAdapter

    CacheManagerInterface <|.. CacheManager
    SecondLevelCacheInterface <|.. RedisCacheAdapter
    CacheManager --> IdentityMapInterface
    CacheManager --> SecondLevelCacheInterface
```

## Proxy

```mermaid
classDiagram
    class LazyLoadingProxy {
        <<interface>>
        +__isInitialized() bool
        +__initialize() void
        +__setInitializer(initializer?) void
        +__getInitializer() Closure?
    }

    class ProxyGenerator {
        +getProxyClassName(entityClass) string
        +generateProxyClass(entityClass) string
        +createProxy(entityClass, initializer) object
    }

    ProxyGenerator --> LazyLoadingProxy : genera
    ProxyGenerator --> MetadataReaderInterface
```

## Hook

```mermaid
classDiagram
    class EventSubscriberInterface {
        <<interface>>
        +getSubscribedEvents() string[]
        +onEvent(entity, hookType) void
    }

    class HookDispatcher {
        +dispatch(entity, hookType) void
        +dispatchAll(entity, hookTypes) void
        +addSubscriber(subscriber) void
        +getSupportedHookTypes()$ string[]
    }

    class EntityChangedEvent {
        +entity object
        +hookType string
    }
    class SymfonyEventDispatcherSubscriber

    EventSubscriberInterface <|.. SymfonyEventDispatcherSubscriber
    HookDispatcher --> EventSubscriberInterface
    HookDispatcher --> MetadataReaderInterface
    HookDispatcher --> EntityChangedEvent : emite
```

## Relaciones entre Componentes

```mermaid
graph LR
    EM[EntityManager] --> CM[ConnectionManager]
    EM --> MR[MetadataReader]
    EM --> UoW[UnitOfWork]
    EM --> IM[IdentityMap]
    EM --> Cache[CacheManager]
    EM --> PG[ProxyGenerator]
    EM --> HD[HookDispatcher]
    QB[QueryBuilder] --> Parser[OqlParser]
    Parser --> Translator[OqlToSqlTranslator]
    Translator --> MR
    UoW --> CM
    UoW --> HD
    Cache --> IM
    PG --> MR
    HD --> MR
```

---

← [Anterior](./flujo-hidratacion.md) | [Índice](./README.md) | [Siguiente →](./guias-contribucion.md)
