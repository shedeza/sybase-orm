# Documento de Diseño: Sybase ASE ORM Bundle

## Visión General

Este documento describe el diseño técnico del Symfony Bundle ORM para Sybase ASE. El bundle implementa el patrón Data Mapper inspirado en Doctrine, proporcionando una capa de abstracción completa para trabajar con Sybase ASE de forma orientada a objetos.

El sistema se compone de 16 componentes principales organizados en capas: conexión, metadatos, consultas, persistencia, caché e integración con Symfony. La comunicación con Sybase ASE se realiza exclusivamente a través de PDO dblib, y el mapeo de entidades se define mediante PHP Attributes con soporte opcional para esquema de base de datos.

### Decisiones de Diseño Clave

1. **PDO dblib como único driver**: Sybase ASE no soporta PDO nativo; dblib (FreeTDS) es el puente estándar.
2. **PHP Attributes sobre anotaciones**: PHP 8.1+ Attributes son nativos, tipados y analizables estáticamente.
3. **@@identity para IDs generados**: Sybase ASE no soporta `RETURNING`; se usa `SELECT @@identity` inmediatamente después del INSERT en la misma conexión.
4. **TOP/ROW_NUMBER() para paginación**: Sybase ASE no soporta LIMIT/OFFSET.
5. **Liberación temprana de PDOStatement**: Sybase ASE tiene límites estrictos de cursores abiertos simultáneos.
6. **SET ANSINULL ON / SET QUOTED_IDENTIFIER ON**: Requeridos al inicio de cada conexión para comportamiento estándar de NULLs e identificadores.
7. **Dirty Checking por snapshot**: Se compara el estado actual de la entidad contra un snapshot tomado al momento de la carga para generar UPDATEs parciales.
8. **Schema opcional en Entity**: El attribute `#[Entity]` acepta un parámetro `schema` opcional. `ClassMetadata.getQualifiedTableName()` retorna `schema.table` o solo `table`. El `SybaseDialect.quoteIdentifier()` maneja nombres calificados: `billing.invoices` → `[billing].[invoices]`.
9. **Caché de ReflectionProperty en UnitOfWork**: Se cachean instancias de `ReflectionProperty` por clase+propiedad para evitar recrearlas en cada snapshot, changeset y operación de persistencia.
10. **Propagación automática de FK**: Cuando se inserta una entidad padre con ID generado (@@identity), el UnitOfWork propaga automáticamente el ID a las columnas FK de entidades dependientes antes de ejecutar sus INSERTs.
11. **Excepciones diferenciadas en ConnectionManager**: Errores de conexión perdida lanzan `ConnectionLostException`; errores SQL genéricos (syntax error, constraint violation) lanzan `PersistenceException`.
12. **Caché de instancias de tipos personalizados**: El TypeCaster cachea las instancias de `CustomTypeInterface` para evitar recrearlas en cada conversión.
13. **Proxy condicional de __serialize**: El ProxyGenerator solo genera override de `__serialize()` si la entidad padre lo define, evitando errores en runtime.
14. **URL de conexión estilo DATABASE_URL**: La configuración soporta una URL tipo `sybase://user:pass@host:port/db?charset=UTF-8`, parseada por `ConnectionUrlParser`. En el contenedor DI, el modo URL usa un factory method estático (`SybaseORMExtension::createConnectionManagerFromUrl()`) que se ejecuta en runtime, permitiendo que `%env(DATABASE_URL)%` se resuelva correctamente en producción.
15. **Caché de ReflectionClass en Hydrator**: El Hydrator cachea instancias de `ReflectionClass` por nombre de clase para evitar recrearlas en cada hidratación.
16. **Receta Symfony Flex**: El bundle incluye `manifest.json` para auto-registro del bundle, copia de configuración por defecto, y definición de `DATABASE_URL` en `.env`. Además, el comando `sybase:install` configura todo automáticamente sin depender de Flex.
17. **MetadataReader lee propiedades heredadas**: `getClassMetadata()` recorre toda la jerarquía de clases para incluir propiedades `private` del padre, resolviendo el problema donde subclases perdían columnas heredadas.
18. **Dispatch completo de hooks en UnitOfWork**: El UnitOfWork acepta `HookDispatcher` como dependencia opcional y dispara PreUpdate antes del SQL, PostPersist/PostUpdate/PostRemove después de cada operación SQL correspondiente.
19. **ClassMetadata con mapas indexados O(1)**: Pre-computa `columnsByProperty` y `columnsByName` en el constructor para búsqueda O(1) en `getColumn()` y `getColumnByName()`, eliminando búsquedas lineales.
20. **Validación temprana de dbname**: `ConnectionManager` valida que `dbname` no esté vacío en el constructor, lanzando `InvalidArgumentException` antes de intentar conectar.
21. **EntityManager cachea OqlParser**: Reutiliza una instancia de `OqlParser` en vez de crear una nueva en cada `query()`.
22. **MetadataReader usa mapa constante para hook names**: `LIFECYCLE_HOOK_NAMES` es un mapa `class → shortName` constante, eliminando `new ReflectionClass()` por cada hook attribute en cada lectura de metadatos.

## Arquitectura

### Diagrama de Arquitectura General

```mermaid
graph TB
    subgraph "Capa de Aplicación"
        Controller[Controlador Symfony]
        Service[Servicio de Negocio]
    end

    subgraph "Capa de ORM - API Pública"
        EM[Entity_Manager]
        QB[Query_Builder]
        OQL[OQL_Parser]
    end

    subgraph "Capa de ORM - Núcleo"
        UoW[Unit_of_Work]
        IM[Identity_Map]
        MR[Metadata_Reader]
        HD[Hook_Dispatcher]
        HY[Hydrator]
        PG[Proxy_Generator]
        TC[Type_Caster]
    end

    subgraph "Capa de ORM - Infraestructura"
        SD[Sybase_Dialect]
        CM[Connection_Manager]
        CA[Cache_Manager]
        MM[Migration_Manager]
        OP[OQL_Printer]
    end

    subgraph "Capa Externa"
        DB[(Sybase ASE)]
        Redis[(Redis / Cache Adapter)]
    end

    Controller --> EM
    Service --> EM
    Controller --> QB
    Service --> QB

    EM --> UoW
    EM --> IM
    EM --> MR
    EM --> HD
    EM --> HY
    EM --> CA

    QB --> SD
    QB --> MR
    OQL --> SD
    OQL --> MR
    OQL --> OP

    UoW --> SD
    UoW --> CM
    UoW --> TC
    UoW --> HD

    HY --> TC
    HY --> IM
    HY --> PG

    MM --> SD
    MM --> CM
    MM --> MR

    CM --> DB
    CA --> IM
    CA --> Redis

    PG --> MR

```

### Diagrama de Flujo: Ciclo de Vida de una Entidad

```mermaid
stateDiagram-v2
    [*] --> New: persist()
    [*] --> Managed: find() / query()
    New --> Managed: flush() → INSERT
    Managed --> Managed: modificar propiedades
    Managed --> Removed: remove()
    Removed --> [*]: flush() → DELETE
    Managed --> Detached: clear() / close()
    Detached --> Managed: merge()
```

### Capas del Sistema

| Capa | Componentes | Responsabilidad |
|------|------------|-----------------|
| API Pública | Entity_Manager, Query_Builder, OQL_Parser, Entity_Repository | Interfaz para el desarrollador |
| Núcleo | Unit_of_Work, Identity_Map, Metadata_Reader, Hook_Dispatcher, Hydrator, Proxy_Generator, Type_Caster, Inheritance_Handler | Lógica interna del ORM |
| Infraestructura | Sybase_Dialect, Connection_Manager, Cache_Manager, Migration_Manager, OQL_Printer | Comunicación con sistemas externos |
| Integración | ORM_Bundle (DI Extension, Configuration, Commands) | Integración con Symfony |

## Componentes e Interfaces

### 1. Entity_Manager

Punto de entrada principal del ORM. Coordina todos los componentes internos.

```php
<?php

namespace SybaseORM\ORM;

interface EntityManagerInterface
{
    /** Registra una entidad nueva para inserción en el próximo flush. */
    public function persist(object $entity): void;

    /** Marca una entidad para eliminación en el próximo flush. */
    public function remove(object $entity): void;

    /** Sincroniza todos los cambios pendientes con la base de datos. */
    public function flush(): void;

    /** Busca una entidad por su identificador primario. */
    public function find(string $entityClass, mixed $id): ?object;

    /** Crea un Query_Builder para la entidad especificada. */
    public function createQueryBuilder(string $entityClass): QueryBuilderInterface;

    /** Ejecuta una consulta OQL y retorna los resultados hidratados. */
    public function query(string $oql, array $params = []): array;

    /** Limpia el Identity_Map y desasocia todas las entidades. */
    public function clear(): void;

    /** Re-asocia una entidad detached al Entity_Manager. */
    public function merge(object $entity): object;

    /** Inicia una transacción explícita. */
    public function beginTransaction(): void;

    /** Confirma la transacción activa. */
    public function commit(): void;

    /** Revierte la transacción activa. */
    public function rollback(): void;

    /** Obtiene la referencia al repositorio de una entidad. */
    public function getRepository(string $entityClass): EntityRepository;
}
```

### 2. Unit_of_Work

Rastrea cambios en entidades y coordina la persistencia. Cachea ReflectionProperty para rendimiento y propaga IDs generados a entidades dependientes.

```php
<?php

namespace SybaseORM\ORM;

interface UnitOfWorkInterface
{
    /** Registra una entidad como nueva (pendiente de INSERT). */
    public function registerNew(object $entity): void;

    /** Marca una entidad para eliminación (pendiente de DELETE). */
    public function registerDeleted(object $entity): void;

    /** Toma un snapshot del estado actual de la entidad para Dirty Checking. */
    public function registerClean(object $entity): void;

    /** Ejecuta todos los cambios pendientes dentro de una transacción. */
    public function commit(): void;

    /**
     * Detecta propiedades modificadas comparando estado actual vs snapshot.
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function computeChangeset(object $entity): array;

    /** Limpia todos los registros de cambios y snapshots. */
    public function clear(): void;
}
```

### 3. Identity_Map

Garantiza unicidad de instancias por identificador dentro de una sesión.

```php
<?php

namespace SybaseORM\ORM;

interface IdentityMapInterface
{
    /** Almacena una entidad en el mapa. */
    public function put(string $entityClass, mixed $id, object $entity): void;

    /** Busca una entidad en el mapa. Retorna null si no existe. */
    public function get(string $entityClass, mixed $id): ?object;

    /** Verifica si una entidad existe en el mapa. */
    public function contains(string $entityClass, mixed $id): bool;

    /** Elimina una entidad del mapa. */
    public function remove(string $entityClass, mixed $id): void;

    /** Limpia todo el mapa. */
    public function clear(): void;
}
```

### 4. Metadata_Reader

Lee PHP Attributes y construye metadatos de mapeo. Soporta esquema opcional en el Attribute Entity.

```php
<?php

namespace SybaseORM\Metadata;

interface MetadataReaderInterface
{
    /** Lee y retorna los metadatos completos de una clase de entidad. */
    public function getClassMetadata(string $entityClass): ClassMetadata;

    /** Verifica si una clase tiene metadatos de entidad. */
    public function isEntity(string $className): bool;
}
```

ClassMetadata incluye: `entityClass`, `tableName`, `schema`, `columns`, `idField`, `relationships`, `inheritanceType`, `discriminatorColumn`, `discriminatorMap`, `lifecycleHooks`, y el método `getQualifiedTableName()` que retorna `schema.table` o solo `table`.

## Extensiones para Migración Insaculación (Requisitos 17–28)

### Decisiones de Diseño Adicionales

23. **`idFields` como array, `idField` preservado**: `ClassMetadata` gana una nueva propiedad array `$idFields`. El `$idField` existente permanece como alias computado apuntando al primer elemento, asegurando que todos los tests existentes pasen sin modificación.
24. **String de clave compuesta para IdentityMap**: Las claves compuestas se serializan a un string determinístico `"val1|val2|..."` usando separador pipe. Las claves escalares continúan usando `(string) $id`.
25. **Nuevos nodos AST como clases final readonly**: `IsNullExpression`, `InExpression`, `FunctionCall` y `HavingClause` siguen el patrón AST existente — `final class` con constructor promotion y propiedades `readonly`.
26. **Tipos union en WhereClause/LogicalExpression**: Los tipos union de condición se extienden para incluir `IsNullExpression` e `InExpression` junto a `Comparison` y `LogicalExpression`.
27. **JoinClause modo dual**: `JoinClause` gana propiedades opcionales `?string $entityName` y `?Comparison|LogicalExpression $withCondition`. Cuando `entityName` está establecido, el traductor lo resuelve a nombre de tabla y usa `withCondition` como cláusula ON.
28. **Constantes de modo de hidratación**: Clase simple con constantes (`HYDRATE_OBJECT = 1`, `HYDRATE_ARRAY = 2`) en lugar de PHP enum, por simplicidad y compatibilidad con patrones existentes.
29. **Conversión de charset en frontera de ConnectionManager**: La conversión ocurre en `executeQuery()` y `executeStatement()` (salida) y en `convertResultRow()` (entrada), manteniendo la lógica centralizada.
30. **Expansión de parámetros para cláusulas IN**: El EntityManager expande parámetros array en placeholders posicionales individuales antes de pasar al ConnectionManager.

### Componentes Modificados

#### ClassMetadata — Soporte Clave Compuesta

```php
final class ClassMetadata
{
    public readonly array $idFields;    // string[] — nombres de propiedad de todos los campos PK
    public readonly ?string $idField;   // compatibilidad — primer elemento o null

    public function getIdColumns(): array;  // ColumnMetadata[] para todos los campos PK
    public function getIdColumn(): ?ColumnMetadata;  // primer Id column (contrato sin cambios)
}
```

#### IdentityMap — Derivación de Clave Compuesta

```php
private function deriveKey(mixed $id): string
{
    if (is_array($id)) {
        ksort($id);
        return implode('|', array_map('strval', $id));
    }
    return (string) $id;
}
```

#### UnitOfWork — WHERE Compuesto

```php
private function buildCompositeWhereClause(ClassMetadata $metadata, object $entity): array
{
    // Retorna [$whereString, $values] con N condiciones AND-joined
}
```

#### Nuevos Nodos AST

- `IsNullExpression(PropertyAccess $property, bool $negated)`
- `InExpression(PropertyAccess $property, array $values, bool $negated)`
- `FunctionCall(string $functionName, PropertyAccess|string $argument, bool $distinct)`
- `HavingClause(Comparison|LogicalExpression|IsNullExpression|InExpression $condition)`

#### Nodos AST Modificados

- `SelectStatement` — `+?HavingClause $havingClause`, `+bool $distinct`
- `JoinClause` — `+?string $entityName`, `+Comparison|LogicalExpression|null $withCondition`
- `WhereClause` / `LogicalExpression` — tipos union extendidos con `IsNullExpression|InExpression`
- `SelectExpression` — `$expression` cambia de `string` a `string|FunctionCall`, `+?string $alias`

#### HydrationMode

```php
final class HydrationMode
{
    public const HYDRATE_OBJECT = 1;
    public const HYDRATE_ARRAY = 2;
}
```

#### ConnectionManager — Conversión de Charset

```php
private bool $charsetConversion; // desde config, default false
private function convertToDatabase(string $value): string;   // UTF-8 → ISO-8859-1//TRANSLIT
private function convertFromDatabase(string $value): string;  // ISO-8859-1 → UTF-8
private function convertParams(array $params): array;
public function convertResultRow(array $row): array;
```

### Propiedades de Corrección (Properties 1–7)

1. **Consistencia de Accesores de Columna Id en ClassMetadata** — `getIdColumns()` retorna exactamente las columnas con `isId=true`, `getIdColumn()` retorna el primer elemento.
2. **Completitud de Cláusula WHERE para Persistencia de Clave Compuesta** — Para N columnas PK, el WHERE generado contiene exactamente N condiciones de igualdad con AND.
3. **Round-Trip de Clave Compuesta en IdentityMap** — `put()` seguido de `get()` con los mismos valores retorna la misma instancia; claves diferentes no colisionan.
4. **Round-Trip OQL Parse–Print–Parse** — Para todo AST OQL válido incluyendo IS NULL, IN, agregados, HAVING, JOIN WITH, wildcards y aliases, imprimir y re-parsear produce un AST equivalente.
5. **Conteo de Placeholders en Expansión de Parámetros IN** — Para N elementos en un array, la expansión produce exactamente N placeholders posicionales.
6. **Inclusión de Cláusula HAVING en QueryBuilder** — Para toda condición HAVING no vacía, el SQL contiene HAVING en posición correcta relativa a GROUP BY y ORDER BY.
7. **Round-Trip de Conversión de Charset** — Para todo string representable en ISO-8859-1, convertir UTF-8→ISO-8859-1→UTF-8 produce un string idéntico al original.


## Extensiones OQL para Migración Insaculación (Requisitos 29–35)

### Decisiones de Diseño Adicionales

31. **Parser unificado**: `OqlParser::parse()` detecta la primera palabra clave (SELECT/UPDATE/DELETE) y delega. Tipo de retorno: `SelectStatement|UpdateStatement|DeleteStatement`.
32. **CustomFunctionCall separado de FunctionCall**: CONVERT usa `(expr AS type)` y RAND no tiene argumentos — estructura distinta de agregaciones. Nodo dedicado evita contaminar FunctionCall.
33. **Pass-through para funciones personalizadas**: El traductor emite CONVERT/RAND tal cual en SQL, son funciones nativas de Sybase ASE.
34. **Literal NULL en AST**: `Literal('NULL', 'null')` — evita cambiar la firma del constructor existente.
35. **SqlWrappingTypeInterface**: Extiende CustomTypeInterface. Solo tipos que la implementan obtienen envolvimiento SQL; los demás siguen con `?`.
36. **Logger opcional en ConnectionManager**: `?LoggerInterface $logger = null` para loguear fallos de iconv sin romper compatibilidad.

### Nuevos Nodos AST

- `UpdateStatement(string $entityName, string $alias, SetClause[] $setClauses, ?WhereClause $where)`
- `DeleteStatement(string $entityName, string $alias, ?WhereClause $where)`
- `SetClause(PropertyAccess $property, Parameter|Literal|CustomFunctionCall $value)`
- `CustomFunctionCall(string $functionName, array $arguments, ?string $castType)`

### Nuevos Métodos en EntityManager

- `executeUpdate(string $oql, array $params = []): int`
- `queryOne(string $oql, array $params = [], int $hydrationMode): ?object`
- `queryScalar(string $oql, array $params = []): mixed`
- `queryIterator(string $oql, array $params = [], int $hydrationMode): \Generator`

### Nuevos Métodos en EntityRepository

- `count(array $criteria = []): int`
- `exists(array $criteria): bool`

### Propiedades de Corrección (Properties 8–14)

8. **UPDATE round-trip** — parse ∘ print ≡ id para UpdateStatement
9. **DELETE round-trip** — parse ∘ print ≡ id para DeleteStatement
10. **UPDATE translation** — resuelve entidad/propiedad a tabla/columna correctamente
11. **DELETE translation** — resuelve entidad a tabla correctamente
12. **UPDATE parameter ordering** — parámetros SET antes de WHERE
13. **TypeCaster getDatabaseValueSQL** — retorna wrapping iff SqlWrappingTypeInterface
14. **Dialect value expressions** — sustituye expresiones correctamente en generateInsert/generateUpdate
