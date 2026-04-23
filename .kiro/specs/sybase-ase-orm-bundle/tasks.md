# Plan de Implementación: Sybase ASE ORM Bundle

## Visión General

Implementación incremental de un Symfony Bundle ORM para Sybase ASE basado en patrones Doctrine. Se construye desde las capas de infraestructura (conexión, metadatos, tipos) hacia las capas superiores (consultas, persistencia, caché) y finalmente la integración con Symfony. Cada tarea construye sobre las anteriores, asegurando que no quede código huérfano.

## Tareas

- [x] 1. Scaffolding del proyecto y estructura base del bundle
  - [x] 1.1 Crear estructura de directorios y composer.json
    - Crear `composer.json` con namespace `SybaseORM\`, dependencias (`php ^8.1`, `symfony/framework-bundle`, `ext-pdo_dblib`), y autoload PSR-4
    - Crear directorios: `src/Attribute/`, `src/Metadata/`, `src/ORM/`, `src/Query/`, `src/Type/`, `src/Connection/`, `src/Dialect/`, `src/Hydrator/`, `src/Proxy/`, `src/Cache/`, `src/Migration/`, `src/Hook/`, `src/DependencyInjection/`, `src/Command/`, `src/Exception/`
    - _Requisitos: 16.1_

  - [x] 1.2 Crear interfaces principales y clases de excepción
    - Crear `EntityManagerInterface`, `UnitOfWorkInterface`, `IdentityMapInterface`, `MetadataReaderInterface`, `QueryBuilderInterface`, `TypeCasterInterface`, `ConnectionManagerInterface`, `SybaseDialectInterface`, `HydratorInterface`, `CacheManagerInterface`
    - Crear excepciones: `TypeConversionException`, `ConnectionLostException`, `PersistenceException`, `TransactionException`, `OqlParseException`, `MigrationException`
    - _Requisitos: 2.6, 3.5, 7.4, 14.6, 11.6_

  - [x] 1.3 Crear clase del Bundle y registrar en Symfony
    - Crear `SybaseORMBundle` extendiendo `AbstractBundle`
    - _Requisitos: 16.1_

- [x] 2. PHP Attributes para mapeo de entidades
  - [x] 2.1 Implementar Attributes de entidad y columna
    - Crear `#[Entity]` con parámetro opcional `table`
    - Crear `#[Column]` con parámetros: `name`, `type`, `nullable`, `length`, `precision`, `scale`
    - Crear `#[Id]` con parámetro opcional `strategy` (por defecto `identity`)
    - Crear `#[GeneratedValue]` con estrategia `IDENTITY`
    - _Requisitos: 1.1, 1.2, 1.3, 1.4_

  - [x] 2.2 Implementar Attributes de relaciones
    - Crear `#[OneToOne]` con parámetros: `targetEntity`, `mappedBy`, `inversedBy`, `cascade`, `fetch`
    - Crear `#[OneToMany]` con parámetros: `targetEntity`, `mappedBy`, `cascade`, `fetch`
    - Crear `#[ManyToOne]` con parámetros: `targetEntity`, `inversedBy`, `cascade`, `fetch`
    - Crear `#[ManyToMany]` con parámetros: `targetEntity`, `mappedBy`, `inversedBy`, `joinTable`, `cascade`, `fetch`
    - Crear `#[JoinColumn]` con parámetros: `name`, `referencedColumnName`
    - _Requisitos: 8.1, 8.2, 8.3_

  - [x] 2.3 Implementar Attributes de herencia
    - Crear `#[InheritanceType]` con estrategia: `TPH`, `TPT`, `TPC`
    - Crear `#[DiscriminatorColumn]` con parámetros: `name`, `type`
    - Crear `#[DiscriminatorMap]` con mapa de valores a clases
    - _Requisitos: 9.1, 9.2, 9.3_

  - [x] 2.4 Implementar Attributes de hooks de ciclo de vida
    - Crear `#[HasLifecycleHooks]` para marcar entidades con hooks
    - Crear `#[PrePersist]`, `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]`, `#[PostRemove]`
    - _Requisitos: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6_

- [x] 3. Metadata Reader con caché
  - [x] 3.1 Implementar ClassMetadata y modelo de metadatos
    - Crear clase `ClassMetadata` con propiedades: `tableName`, `columns` (array de `ColumnMetadata`), `idField`, `relationships`, `inheritanceType`, `discriminatorColumn`, `discriminatorMap`, `lifecycleHooks`
    - Crear clases auxiliares: `ColumnMetadata`, `RelationshipMetadata`
    - _Requisitos: 1.1_

  - [x] 3.2 Implementar MetadataReader usando Reflection API
    - Leer `#[Entity]`, `#[Column]`, `#[Id]`, relaciones y herencia de las clases
    - Implementar convención snake_case para nombres de tabla y columna cuando no se especifican
    - _Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [x] 3.3 Implementar caché de metadatos
    - Cachear metadatos en memoria (array estático) por clase
    - Soporte opcional para caché en archivo (serialización)
    - _Requisitos: 1.6_

  - [x] 3.4 Escribir tests unitarios para MetadataReader
    - Verificar extracción de tabla, columnas, relaciones, herencia
    - Verificar convención snake_case
    - Verificar caché de metadatos
    - _Requisitos: 1.1, 1.2, 1.3, 1.6_

- [x] 4. Sistema de Type Caster
  - [x] 4.1 Implementar TypeCaster con tipos básicos
    - Implementar conversión `bool` ↔ BIT (1/0)
    - Implementar conversión `DateTime` ↔ formato Sybase `"YYYY-MM-DD HH:MM:SS.mmm"`
    - Implementar conversión `int`, `float`, `string` directa
    - _Requisitos: 2.1, 2.2_

  - [x] 4.2 Implementar soporte para enums y Value Objects
    - Implementar conversión de `BackedEnum` ↔ valor escalar
    - Implementar registro de tipos personalizados (Value Objects) con métodos `toDatabaseValue()` y `toPhpValue()`
    - Lanzar `TypeConversionException` cuando la conversión falla
    - _Requisitos: 2.3, 2.4, 2.5, 2.6_

  - [x] 4.3 Escribir tests unitarios para TypeCaster
    - Verificar conversiones bidireccionales de todos los tipos
    - Verificar excepción en conversión inválida
    - _Requisitos: 2.1, 2.2, 2.3, 2.5, 2.6_

- [x] 5. Checkpoint - Verificar que la capa de metadatos y tipos funciona
  - Asegurar que todos los tests pasan, preguntar al usuario si surgen dudas.

- [x] 6. Connection Manager
  - [x] 6.1 Implementar ConnectionManager con PDO dblib
    - Crear conexión PDO con DSN `dblib:host=...;dbname=...;charset=...`
    - Ejecutar `SET ANSINULL ON` y `SET QUOTED_IDENTIFIER ON` al establecer conexión
    - Implementar liberación temprana de PDOStatement (closeCursor/unset)
    - _Requisitos: 3.1, 3.2, 3.3_

  - [x] 6.2 Implementar conexiones persistentes y manejo de errores
    - Soporte para conexiones persistentes via `PDO::ATTR_PERSISTENT`
    - Lanzar `ConnectionLostException` cuando se pierde la conexión
    - Leer parámetros de conexión desde configuración del bundle
    - _Requisitos: 3.4, 3.5, 3.6_

  - [x] 6.3 Implementar gestión de transacciones en ConnectionManager
    - Métodos `beginTransaction()`, `commit()`, `rollback()`
    - Soporte para niveles de aislamiento (READ UNCOMMITTED, READ COMMITTED, REPEATABLE READ, SERIALIZABLE)
    - Lanzar `TransactionException` si commit/rollback sin transacción activa
    - _Requisitos: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6_

- [x] 7. Sybase Dialect
  - [x] 7.1 Implementar SybaseDialect para generación de SQL
    - Generar SELECT con TOP para primera página
    - Generar subconsultas con ROW_NUMBER() para páginas subsiguientes
    - Omitir columna identity en INSERT
    - Generar `SELECT @@identity` para obtener último ID
    - Generar identificadores compatibles con Sybase ASE
    - Respetar comportamiento ANSINULL
    - _Requisitos: 11.1, 11.2, 11.3, 11.4, 11.5_

  - [x] 7.2 Implementar interfaz reemplazable del dialecto
    - Definir `DialectInterface` con métodos para cada tipo de generación SQL
    - Permitir inyección de dialectos alternativos
    - _Requisitos: 11.6_

  - [x] 7.3 Escribir tests unitarios para SybaseDialect
    - Verificar generación de paginación TOP y ROW_NUMBER()
    - Verificar omisión de identity en INSERT
    - Verificar generación de @@identity
    - _Requisitos: 11.1, 11.2, 11.3_

- [x] 8. Query Builder
  - [x] 8.1 Implementar QueryBuilder con API fluida
    - Métodos encadenables: `select()`, `from()`, `where()`, `join()`, `orderBy()`, `groupBy()`
    - Combinación de condiciones WHERE con AND/OR
    - Parametrización automática de valores del usuario
    - _Requisitos: 5.1, 5.2, 5.5_

  - [x] 8.2 Implementar paginación y eager loading en QueryBuilder
    - Método `limit()` / `offset()` que delega al SybaseDialect para TOP/ROW_NUMBER()
    - Método `with()` para eager loading que genera JOINs o WHERE IN
    - _Requisitos: 5.3, 5.4_

  - [x] 8.3 Escribir tests unitarios para QueryBuilder
    - Verificar generación de SQL con distintas combinaciones de cláusulas
    - Verificar parametrización de valores
    - Verificar paginación
    - _Requisitos: 5.1, 5.2, 5.3_

- [x] 9. OQL Parser y Printer
  - [x] 9.1 Definir nodos AST para OQL
    - Crear clases de nodos: `SelectStatement`, `FromClause`, `WhereClause`, `JoinClause`, `OrderByClause`, `GroupByClause`, `PropertyAccess`, `Comparison`, `LogicalExpression`, `Parameter`, `Literal`
    - _Requisitos: 4.1_

  - [x] 9.2 Implementar OQL Parser (texto → AST)
    - Tokenizar y parsear consultas OQL
    - Resolver nombres de entidades y propiedades a tablas y columnas via MetadataReader
    - _Requisitos: 4.1, 4.3_

  - [x] 9.3 Implementar OQL Printer (AST → texto)
    - Recorrer el AST y generar representación textual OQL válida
    - _Requisitos: 4.5_

  - [x] 9.4 Implementar traducción OQL AST → SQL Sybase ASE
    - Visitor que recorre el AST y genera SQL usando SybaseDialect
    - Parametrización de valores del usuario
    - _Requisitos: 4.2, 4.4_

  - [x] 9.5 Escribir test de propiedad para round-trip OQL
    - **Propiedad 1: Round-trip OQL (ida y vuelta)**
    - Para todo AST de OQL válido, parsear(printer(ast)) == ast
    - **Valida: Requisito 4.6**

  - [x] 9.6 Escribir tests unitarios para OQL Parser y Printer
    - Verificar parsing de consultas SELECT, WHERE, JOIN, ORDER BY
    - Verificar resolución de nombres de entidad a tabla
    - _Requisitos: 4.1, 4.3, 4.5_

- [x] 10. Checkpoint - Verificar capa de consultas
  - Asegurar que todos los tests pasan, preguntar al usuario si surgen dudas.

- [x] 11. Hydrator
  - [x] 11.1 Implementar Hydrator con Reflection API
    - Convertir filas de base de datos (arrays) en instancias de entidad
    - Usar Reflection API para asignar valores a propiedades privadas
    - Integrar TypeCaster para conversión de tipos
    - Ignorar columnas no mapeadas sin error
    - _Requisitos: 6.1, 6.2, 6.5_

  - [x] 11.2 Implementar hidratación con eager loading e Identity Map
    - Hidratar entidades relacionadas cuando se cargan con eager loading
    - Consultar Identity Map antes de crear nueva instancia
    - _Requisitos: 6.3, 6.4_

  - [x] 11.3 Escribir tests unitarios para Hydrator
    - Verificar hidratación básica con tipos
    - Verificar integración con Identity Map
    - Verificar columnas no mapeadas ignoradas
    - _Requisitos: 6.1, 6.2, 6.4, 6.5_

- [x] 12. Identity Map
  - [x] 12.1 Implementar IdentityMap
    - Almacenar entidades indexadas por clase + id
    - Métodos: `put()`, `get()`, `contains()`, `remove()`, `clear()`
    - _Requisitos: 7.6, 13.1_

  - [x] 12.2 Escribir tests unitarios para IdentityMap
    - Verificar unicidad de instancias
    - Verificar operaciones CRUD del mapa
    - _Requisitos: 7.6_

- [x] 13. Unit of Work
  - [x] 13.1 Implementar registro de entidades y dirty checking
    - Métodos `registerNew()`, `registerDeleted()`, `registerClean()`
    - Snapshot de estado al registrar como clean
    - `computeChangeset()` comparando estado actual vs snapshot via Reflection
    - _Requisitos: 7.1, 7.2, 7.5_

  - [x] 13.2 Implementar commit (flush) con transacción
    - Ejecutar INSERTs, UPDATEs (parciales), DELETEs dentro de transacción
    - Recuperar @@identity después de cada INSERT
    - ROLLBACK y lanzar `PersistenceException` en caso de error
    - _Requisitos: 7.3, 7.4, 7.7_

  - [x] 13.3 Implementar persistencia en cascada
    - Ordenar operaciones respetando dependencias de claves foráneas
    - Persistir entidades relacionadas marcadas con cascade
    - _Requisitos: 8.6_

  - [x] 13.4 Escribir tests unitarios para Unit of Work
    - Verificar dirty checking detecta cambios
    - Verificar orden de operaciones (INSERT → UPDATE → DELETE)
    - Verificar rollback en error
    - _Requisitos: 7.1, 7.3, 7.4, 7.5_

- [x] 14. Proxy Generator para Lazy Loading
  - [x] 14.1 Implementar ProxyGenerator
    - Generar clases proxy que hereden de la entidad
    - Interceptar acceso a propiedades para disparar carga
    - Cargar datos pendientes antes de serialización
    - Generar proxies en directorio configurable con caché
    - _Requisitos: 10.1, 10.2, 10.3, 10.4_

  - [x] 14.2 Integrar Proxy con Identity Map y relaciones lazy
    - Registrar proxy inicializado en Identity Map
    - Conectar con relaciones configuradas como LAZY
    - _Requisitos: 10.5, 8.4_

  - [x] 14.3 Escribir tests unitarios para ProxyGenerator
    - Verificar generación de clase proxy
    - Verificar carga lazy al acceder a propiedad
    - Verificar serialización fuerza carga
    - _Requisitos: 10.1, 10.2, 10.3_

- [x] 15. Checkpoint - Verificar capa de persistencia
  - Asegurar que todos los tests pasan, preguntar al usuario si surgen dudas.

- [x] 16. Hook Dispatcher
  - [x] 16.1 Implementar HookDispatcher
    - Leer métodos anotados con hooks de ciclo de vida desde ClassMetadata
    - Ejecutar hooks en el momento correcto: PrePersist/PostPersist, PreUpdate/PostUpdate, PreRemove/PostRemove
    - Propagar excepciones de hooks y cancelar operación
    - _Requisitos: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7_

  - [x] 16.2 Escribir tests unitarios para HookDispatcher
    - Verificar ejecución de cada tipo de hook
    - Verificar propagación de excepciones
    - _Requisitos: 15.1, 15.3, 15.5, 15.7_

- [x] 17. Estrategias de herencia
  - [x] 17.1 Implementar Table Per Hierarchy (TPH)
    - Almacenar toda la jerarquía en una tabla con columna discriminadora
    - Hidratar subclase correcta según valor del discriminador
    - _Requisitos: 9.1, 9.4_

  - [x] 17.2 Implementar Table Per Type (TPT)
    - Tabla base + tabla por clase derivada unidas por PK
    - Generar JOINs automáticos al consultar clase base
    - _Requisitos: 9.2, 9.5_

  - [x] 17.3 Implementar Table Per Concrete Class (TPC)
    - Tabla independiente por clase concreta con todas las columnas heredadas
    - _Requisitos: 9.3_

  - [x] 17.4 Escribir tests unitarios para estrategias de herencia
    - Verificar TPH con discriminador
    - Verificar TPT con JOINs
    - Verificar TPC con tablas independientes
    - _Requisitos: 9.1, 9.2, 9.3, 9.4, 9.5_

- [x] 18. Cache Manager
  - [x] 18.1 Implementar caché de primer nivel (Identity Map)
    - Integrar Identity Map como caché de primer nivel
    - Retornar instancia cacheada sin consulta a BD cuando existe
    - _Requisitos: 13.1, 13.2_

  - [x] 18.2 Implementar caché de segundo nivel con adaptador Redis
    - Almacenar resultados de consultas frecuentes compartidos entre sesiones
    - Soporte para TTL por entidad o consulta
    - Invalidar caché al modificar entidad en flush()
    - Fallback a solo primer nivel si Redis no disponible (con warning en log)
    - _Requisitos: 13.3, 13.4, 13.5, 13.6_

  - [x] 18.3 Escribir tests unitarios para CacheManager
    - Verificar hit/miss de primer nivel
    - Verificar invalidación en flush
    - Verificar fallback sin Redis
    - _Requisitos: 13.1, 13.2, 13.4, 13.6_

- [x] 19. Entity Manager
  - [x] 19.1 Implementar EntityManager como orquestador
    - Implementar `persist()`, `remove()`, `flush()`, `find()`, `clear()`, `merge()`
    - Implementar `createQueryBuilder()`, `query()` (OQL)
    - Implementar `beginTransaction()`, `commit()`, `rollback()`
    - Implementar `getRepository()`
    - Coordinar UnitOfWork, IdentityMap, MetadataReader, Hydrator, HookDispatcher, CacheManager
    - _Requisitos: 7.1, 7.2, 7.3, 7.6, 14.1, 14.6_

  - [x] 19.2 Escribir tests unitarios para EntityManager
    - Verificar ciclo de vida completo: persist → flush → find → modify → flush → remove → flush
    - Verificar transacciones explícitas
    - _Requisitos: 7.1, 7.3, 14.1, 14.6_

- [x] 20. Checkpoint - Verificar integración de componentes del ORM
  - Asegurar que todos los tests pasan, preguntar al usuario si surgen dudas.

- [x] 21. Migration Manager
  - [x] 21.1 Implementar MigrationManager
    - Comparar metadatos de entidades con esquema actual para detectar diferencias
    - Generar archivos de migración con SQL Sybase ASE (via SybaseDialect)
    - Ejecutar migraciones y registrar versión en tabla de control
    - Soporte para revertir migraciones (sentencias inversas)
    - ROLLBACK y reportar error si migración falla
    - _Requisitos: 12.1, 12.2, 12.3, 12.4, 12.5_

  - [x] 21.2 Escribir tests unitarios para MigrationManager
    - Verificar generación de SQL de migración
    - Verificar registro de versiones
    - Verificar rollback en error
    - _Requisitos: 12.1, 12.2, 12.5_

- [x] 22. Integración con Symfony
  - [x] 22.1 Implementar DI Extension y Configuration
    - Crear `SybaseORMExtension` que registre servicios en el contenedor
    - Crear clase `Configuration` con tree builder para parámetros: conexión (host, puerto, db, usuario, contraseña, charset), directorios de entidades, directorio de proxies, configuración de caché
    - Registrar EntityManager, ConnectionManager y demás servicios con autowiring
    - _Requisitos: 16.1, 16.2, 16.3, 16.4_

  - [x] 22.2 Implementar comandos de consola
    - Comando `sybase:migrations:generate` para generar migraciones
    - Comando `sybase:migrations:migrate` para ejecutar migraciones
    - Comando `sybase:proxy:generate` para generar proxies
    - Comando `sybase:cache:clear` para limpiar caché
    - _Requisitos: 16.5_

  - [x] 22.3 Escribir tests unitarios para la integración Symfony
    - Verificar carga del bundle y registro de servicios
    - Verificar configuración desde YAML
    - _Requisitos: 16.1, 16.2, 16.3_

- [x] 23. Checkpoint final - Verificar integración completa
  - Asegurar que todos los tests pasan, preguntar al usuario si surgen dudas.

## Notas

- Todas las 23 tareas han sido completadas
- Cada tarea referencia requisitos específicos para trazabilidad
- Los checkpoints validaron la integración incremental en cada capa
- El test de propiedad (tarea 9.5) valida la propiedad de round-trip OQL definida en el requisito 4.6
- 510 tests unitarios con 1113 assertions cubren todos los componentes
- Mejoras post-implementación aplicadas:
  - Soporte de esquema en Entity attribute (`#[Entity(table: 'invoices', schema: 'billing')]`)
  - Caché de ReflectionProperty en UnitOfWork y ReflectionClass en Hydrator para rendimiento
  - Propagación automática de FK generados a entidades dependientes
  - Detección de dependencias circulares en topological sort del UnitOfWork
  - Dispatch completo de hooks de ciclo de vida (PreUpdate, PostPersist, PostUpdate, PostRemove) en UnitOfWork
  - Jerarquía de excepciones con SybaseORMException como base
  - Excepciones diferenciadas en ConnectionManager (ConnectionLostException vs PersistenceException)
  - Validación de dbname no vacío en ConnectionManager
  - EntityManager.merge() usa PersistenceException en vez de RuntimeException genérica
  - Caché de instancias de tipos personalizados en TypeCaster
  - ProxyGenerator condicional para __serialize()
  - SybaseDialect con soporte de identificadores calificados con esquema y SELECT * sin quotear asterisco
  - EntityRepository con findOneBy() y shortName cacheado
  - EntityManager con OqlParser cacheado y mapa pre-computado shortName→FQCN
  - ClassMetadata con mapas indexados O(1) para búsqueda de columnas (getColumn, getColumnByName)
  - MetadataReader lee propiedades privadas heredadas de clases padre
  - MetadataReader usa mapa constante para nombres de hooks (sin Reflection por iteración)
  - Hydrator usa ClassMetadata.getColumnByName() para hidratación eficiente
  - ConnectionUrlParser para URLs tipo `sybase://user:pass@host:port/db?charset=UTF-8`
  - Configuración dual: URL (`DATABASE_URL`) o parámetros individuales
  - Factory method en SybaseORMExtension para resolución de URL en runtime
  - Receta Symfony Flex + comando `sybase:install` para configuración automática
  - Directorio de migraciones en `sybase_ase/migrations/`
