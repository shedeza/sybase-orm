# Organización del Código

El proyecto `shedeza/sybase-orm` sigue una estructura modular organizada por responsabilidad. Cada directorio dentro de `src/` corresponde a un namespace PHP bajo el prefijo `SybaseORM\` y agrupa clases con un propósito cohesivo.

## Estructura General

```
src/
├── Attribute/       → Atributos PHP para mapeo de entidades
├── Cache/           → Sistema de caché de segundo nivel
├── Collection/      → Colecciones de entidades
├── Connection/      → Gestión de conexiones a base de datos
├── Console/         → Comandos CLI (migraciones, caché, validación)
├── Dialect/         → Dialectos SQL específicos del motor
├── Exception/       → Jerarquía de excepciones del ORM
├── Hook/            → Sistema de eventos y lifecycle hooks
├── Hydrator/        → Transformación de resultados a objetos
├── Instrumentation/ → Sistema de profiling e instrumentación
├── Metadata/        → Lectura y almacenamiento de metadatos de entidades
├── Migration/       → Sistema de migraciones de esquema
├── ORM/             → Núcleo del ORM (EntityManager, UnitOfWork, IdentityMap)
├── PHPStan/         → Reglas personalizadas de análisis estático
├── Proxy/           → Generación de proxies para lazy loading
├── Query/           → Parser OQL, QueryBuilder y AST
├── Testing/         → Herramientas de testing (EntityFactory)
└── Type/            → Sistema de tipos y conversión de valores
```

## Namespaces y Archivos

### SybaseORM\Attribute

Define los atributos PHP 8 utilizados para mapear entidades, columnas, relaciones y comportamientos.

| Archivo | Propósito |
|---------|-----------|
| `Column.php` | Mapeo de propiedad a columna de tabla |
| `DiscriminatorColumn.php` | Columna discriminadora para herencia |
| `DiscriminatorMap.php` | Mapa de valores discriminadores a clases |
| `Embeddable.php` | Marca una clase como value object embebible |
| `Embedded.php` | Incrusta un value object en una entidad |
| `Entity.php` | Marca una clase como entidad persistente |
| `GeneratedValue.php` | Estrategia de generación de identidad |
| `HasLifecycleHooks.php` | Activa hooks de ciclo de vida en la entidad |
| `Id.php` | Marca la propiedad como clave primaria |
| `InheritanceType.php` | Estrategia de herencia (single-table) |
| `JoinColumn.php` | Columna de join para relaciones |
| `JoinColumns.php` | Múltiples columnas de join |
| `ManyToMany.php` | Relación muchos a muchos |
| `ManyToOne.php` | Relación muchos a uno |
| `OneToMany.php` | Relación uno a muchos |
| `OneToOne.php` | Relación uno a uno |
| `PostPersist.php` | Hook ejecutado después de insertar |
| `PostRemove.php` | Hook ejecutado después de eliminar |
| `PostUpdate.php` | Hook ejecutado después de actualizar |
| `PrePersist.php` | Hook ejecutado antes de insertar |
| `PreRemove.php` | Hook ejecutado antes de eliminar |
| `PreUpdate.php` | Hook ejecutado antes de actualizar |
| `SoftDelete.php` | Activación de eliminación lógica |
| `Timestamps.php` | Gestión automática de campos created_at/updated_at |
| `GlobalScope.php` | Define un query scope global aplicado automáticamente |
| `Accessor.php` | Define un accessor (getter personalizado) para una propiedad |
| `Mutator.php` | Define un mutator (setter personalizado) para una propiedad |
| `ReadOnly.php` / `Immutable` | Marca una entidad como de solo lectura (no permite persist/update) |
| `EntityListener.php` | Registra un listener externo para eventos de la entidad |
| `CacheRegion.php` | Define la región de caché específica para la entidad |

### SybaseORM\Cache

Implementación del caché de segundo nivel con soporte para Redis.

| Archivo | Propósito |
|---------|-----------|
| `CacheManager.php` | Coordinador principal del sistema de caché |
| `CacheManagerInterface.php` | Contrato del administrador de caché |
| `RedisCacheAdapter.php` | Adaptador de caché usando Redis |
| `SecondLevelCacheInterface.php` | Contrato del caché de segundo nivel |

### SybaseORM\Collection

Colecciones tipadas para manejar conjuntos de entidades.

| Archivo | Propósito |
|---------|-----------|
| `ArrayCollection.php` | Implementación basada en array |
| `Collection.php` | Interfaz de colección |

### SybaseORM\Connection

Capa de conexión a base de datos: gestión del ciclo de vida, parsing de URLs y binding de parámetros.

| Archivo | Propósito |
|---------|-----------|
| `ConnectionManager.php` | Gestión de conexiones PDO, pooling y reconexión |
| `ConnectionManagerInterface.php` | Contrato del administrador de conexiones |
| `ConnectionUrlParser.php` | Parsing de URLs DSN de conexión |
| `ExplainableConnectionInterface.php` | Contrato para conexiones que soportan EXPLAIN/SHOWPLAN |
| `SqlParameterExpander.php` | Expansión de arrays para cláusulas IN |
| `RetryConnectionManager.php` | Decorator con reintentos automáticos ante pérdida de conexión |

### SybaseORM\Dialect

Dialecto SQL específico para Sybase ASE.

| Archivo | Propósito |
|---------|-----------|
| `DialectInterface.php` | Contrato del dialecto SQL |
| `SybaseDialect.php` | Implementación del dialecto Sybase ASE |

### SybaseORM\Exception

Jerarquía de excepciones del ORM con tipos específicos por dominio de error.

| Archivo | Propósito |
|---------|-----------|
| `SybaseORMException.php` | Excepción base del ORM |
| `ConnectionLostException.php` | Conexión perdida con el servidor |
| `MigrationException.php` | Error en ejecución de migraciones |
| `OqlParseException.php` | Error de sintaxis en consultas OQL |
| `PersistenceException.php` | Error en operaciones de persistencia |
| `TransactionException.php` | Error en gestión de transacciones |
| `TypeConversionException.php` | Error en conversión de tipos PHP ↔ DB |

### SybaseORM\Hook

Sistema de eventos para hooks de ciclo de vida e integración con dispatchers externos.

| Archivo | Propósito |
|---------|-----------|
| `EntityChangedEvent.php` | Evento emitido al cambiar una entidad |
| `EventSubscriberInterface.php` | Contrato para subscribers de eventos |
| `HookDispatcher.php` | Dispatcher interno de hooks |
| `SymfonyEventDispatcherSubscriber.php` | Puente con Symfony EventDispatcher |

### SybaseORM\Hydrator

Transformación de result sets de base de datos en objetos PHP.

| Archivo | Propósito |
|---------|-----------|
| `Hydrator.php` | Implementación principal del hidratador |
| `HydratorInterface.php` | Contrato del hidratador |

### SybaseORM\Metadata

Lectura de metadatos de entidades desde atributos PHP y almacenamiento en estructuras internas.

| Archivo | Propósito |
|---------|-----------|
| `ClassMetadata.php` | Metadatos completos de una clase/entidad |
| `ColumnMetadata.php` | Metadatos de una columna individual |
| `EmbeddedMetadata.php` | Metadatos de un value object embebido |
| `EntityDiscovery.php` | Descubrimiento automático de entidades |
| `MetadataReader.php` | Lector de atributos PHP para extraer metadatos |
| `MetadataReaderInterface.php` | Contrato del lector de metadatos |
| `RelationshipMetadata.php` | Metadatos de una relación entre entidades |

### SybaseORM\Migration

Sistema de migraciones para evolución controlada del esquema de base de datos.

| Archivo | Propósito |
|---------|-----------|
| `MigrationManager.php` | Gestión de migraciones: generar, ejecutar, rollback |

### SybaseORM\ORM

Núcleo del ORM que implementa los patrones Unit of Work, Identity Map y Repository.

| Archivo | Propósito |
|---------|-----------|
| `EntityManager.php` | Fachada principal del ORM |
| `EntityManagerInterface.php` | Contrato del EntityManager |
| `EntityManagerRegistry.php` | Registro de múltiples EntityManagers |
| `EntityRepository.php` | Repositorio base con operaciones CRUD |
| `HydrationMode.php` | Enum de modos de hidratación |
| `IdentityMap.php` | Mapa de identidad (caché de primer nivel) |
| `IdentityMapInterface.php` | Contrato del Identity Map |
| `InheritanceHandler.php` | Resolución de herencia al hidratar |
| `OrmFactory.php` | Factory para crear instancias del ORM |
| `PersistentCollection.php` | Colección con carga lazy de relaciones |
| `UnitOfWork.php` | Patrón Unit of Work: changeset y flush |
| `UnitOfWorkInterface.php` | Contrato del Unit of Work |

### SybaseORM\Proxy

Generación de clases proxy para lazy loading de relaciones.

| Archivo | Propósito |
|---------|-----------|
| `LazyLoadingProxy.php` | Proxy que carga la entidad al acceder |
| `ProxyGenerator.php` | Generador de clases proxy en disco |

### SybaseORM\Query

Sistema de consultas: parser OQL, AST, traductor a SQL y QueryBuilder.

| Archivo | Propósito |
|---------|-----------|
| `OqlParser.php` | Parser del lenguaje OQL |
| `OqlPrinter.php` | Impresión/debug de consultas OQL |
| `OqlToSqlTranslator.php` | Traducción de AST OQL a SQL nativo |
| `QueryBuilder.php` | Constructor fluido de consultas |
| `QueryBuilderInterface.php` | Contrato del QueryBuilder |

#### Subdirectorio Query/AST

Nodos del árbol de sintaxis abstracta (AST) para representar consultas OQL parseadas.

| Archivo | Propósito |
|---------|-----------|
| `Comparison.php` | Comparación (=, !=, <, >, etc.) |
| `CustomFunctionCall.php` | Llamada a función OQL personalizada |
| `DeleteStatement.php` | Sentencia DELETE |
| `FromClause.php` | Cláusula FROM |
| `FunctionCall.php` | Llamada a función OQL nativa |
| `GroupByClause.php` | Cláusula GROUP BY |
| `HavingClause.php` | Cláusula HAVING |
| `InExpression.php` | Expresión IN (...) |
| `IsNullExpression.php` | Expresión IS NULL / IS NOT NULL |
| `JoinClause.php` | Cláusula JOIN |
| `Literal.php` | Valor literal (string, número) |
| `LogicalExpression.php` | Expresión lógica (AND, OR) |
| `OrderByClause.php` | Cláusula ORDER BY |
| `OrderByItem.php` | Ítem individual de ORDER BY |
| `Parameter.php` | Parámetro nombrado (:nombre) |
| `PropertyAccess.php` | Acceso a propiedad de entidad (alias.propiedad) |
| `SelectExpression.php` | Expresión en SELECT |
| `SelectStatement.php` | Sentencia SELECT completa |
| `SetClause.php` | Cláusula SET para UPDATE |
| `UpdateStatement.php` | Sentencia UPDATE |
| `WhereClause.php` | Cláusula WHERE |

### SybaseORM\Type

Sistema de tipos para conversión de valores entre PHP y la base de datos.

| Archivo | Propósito |
|---------|-----------|
| `CustomTypeInterface.php` | Contrato para tipos personalizados |
| `SqlWrappingTypeInterface.php` | Contrato para tipos que envuelven SQL |
| `TypeCaster.php` | Conversión de tipos PHP ↔ DB |
| `TypeCasterInterface.php` | Contrato del convertidor de tipos |
| `Types.php` | Registro de tipos disponibles |

### SybaseORM\PHPStan

Reglas personalizadas de análisis estático para el proyecto.

| Archivo | Propósito |
|---------|-----------|
| `NoSymfonyImportsRule.php` | Regla que prohíbe imports directos de Symfony |

### SybaseORM\Instrumentation

Sistema de profiling e instrumentación para monitorear el rendimiento de consultas y operaciones del ORM.

| Archivo | Propósito |
|---------|-----------|
| `OrmInstrumentationInterface.php` | Contrato de instrumentación |
| `NullInstrumentation.php` | Implementación noop (sin overhead en producción) |
| `InstrumentationCollector.php` | Recolector de métricas: tiempos de query, flush, hydration |

### SybaseORM\Console

Sistema CLI con arquitectura de comandos individuales.

| Archivo | Propósito |
|---------|-----------|
| `CommandInterface.php` | Contrato para todos los comandos CLI |
| `CommandRunner.php` | Dispatcher: rutea argv al comando apropiado, muestra help |
| `IO.php` | Helper de salida: output, error, success, warning, table |

#### Subdirectorio Console/Command

| Archivo | Propósito |
|---------|-----------|
| `MigrateCommand.php` | Ejecutar migraciones pendientes |
| `MigrateRollbackCommand.php` | Revertir la última migración |
| `MigrateStatusCommand.php` | Mostrar estado de migraciones |
| `MigrateGenerateCommand.php` | Generar migración desde diff de entidades |
| `MigratePreviewCommand.php` | Previsualizar SQL sin ejecutar |
| `MigrateFreshCommand.php` | Drop all + re-ejecutar migraciones (dev) |
| `MigrateResetCommand.php` | Rollback de todas las migraciones |
| `MakeMigrationCommand.php` | Crear archivo de migración vacío |
| `MakeEntityCommand.php` | Generar clase entidad skeleton |
| `SchemaValidateCommand.php` | Validar mapping vs esquema DB |
| `CacheClearCommand.php` | Limpiar cachés de proxies y metadatos |
| `OrmInfoCommand.php` | Mostrar entidades mapeadas con info de tablas |

### SybaseORM\Testing

Herramientas para testing de entidades y repositorios.

| Archivo | Propósito |
|---------|-----------|
| `EntityFactory.php` | Factory para generar entidades con datos de prueba |

### bin/ (Entry Points)

| Archivo | Propósito |
|---------|-----------|
| `bin/sybase-orm` | Ejecutable CLI principal del ORM (migraciones, caché, validación de esquema) |

## Convenciones del Proyecto

- **Autoloading PSR-4**: el namespace `SybaseORM\` mapea al directorio `src/`
- **Un archivo por clase**: cada archivo contiene exactamente una clase, interfaz o enum
- **Interfaces con sufijo**: los contratos usan el sufijo `Interface` (ej: `ConnectionManagerInterface`)
- **Separación por capa**: cada directorio agrupa clases de una misma responsabilidad arquitectónica

---

← [Anterior](./arquitectura-general.md) | [Índice](./README.md) | [Siguiente →](./patron-unit-of-work.md)
