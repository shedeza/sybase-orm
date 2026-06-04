# Sistema de Caché

El ORM implementa un sistema de caché de dos niveles para optimizar el rendimiento de acceso a datos. El primer nivel (Identity Map) está siempre activo y garantiza unicidad de instancias por sesión. El segundo nivel (opcional) almacena entidades y resultados de consultas en un almacén compartido como Redis.

## Caché de Primer Nivel (Identity Map)

El Identity Map es un caché en memoria que garantiza que una misma entidad (misma clase + mismo ID) tenga una única instancia dentro de una sesión del EntityManager.

### Comportamiento automático

El Identity Map opera de forma transparente sin requerir configuración:

```php
// Primera consulta: ejecuta SQL y almacena en Identity Map
$usuario = $em->find(User::class, 1);

// Segunda consulta con el mismo ID: retorna la instancia en memoria
$mismoUsuario = $em->find(User::class, 1);

// Ambas variables referencian el mismo objeto
var_dump($usuario === $mismoUsuario); // true
```

### Beneficios

- **Consistencia**: Evita tener múltiples instancias de la misma entidad con estados diferentes
- **Rendimiento**: Elimina consultas duplicadas a la base de datos dentro de la misma sesión
- **Integridad**: Al modificar una entidad, todos los puntos del código ven el mismo estado

### Métodos de control

```php
// Limpiar todo el Identity Map (desvincula todas las entidades)
$em->clear();

// Verificar cuántas entidades están en memoria
$identityMap->count();         // Total de entidades
$identityMap->countClass(User::class); // Solo de una clase
```

### Resolución de conflictos

Cuando se intenta almacenar un Proxy sobre una entidad real ya existente, el Identity Map preserva la entidad real para mantener la integridad de los datos:

```php
// Si User#1 ya está como entidad real, un Proxy no la sobreescribirá
$identityMap->put(User::class, 1, $proxyUser); // No tiene efecto
```

## Caché de Segundo Nivel con Redis

El segundo nivel es un caché compartido entre sesiones que permite almacenar entidades y resultados de consultas con un tiempo de vida (TTL) configurable.

### Interfaz SecondLevelCacheInterface

Cualquier adaptador de segundo nivel debe implementar esta interfaz:

```php
interface SecondLevelCacheInterface
{
    public function get(string $key): mixed;
    public function put(string $key, mixed $value, ?int $ttl = null): void;
    public function delete(string $key): void;
    public function has(string $key): bool;
    public function clear(): void;
}
```

### Configuración de RedisCacheAdapter

El adaptador incluido utiliza la extensión `ext-redis` de PHP:

```php
use SybaseORM\Cache\RedisCacheAdapter;
use SybaseORM\Cache\CacheManager;
use SybaseORM\ORM\IdentityMap;

// 1. Configurar la conexión Redis
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('tu_password'); // Si Redis requiere autenticación

// 2. Crear el adaptador con prefijo personalizado (opcional)
$redisCache = new RedisCacheAdapter($redis, 'mi_app:orm:');

// 3. Crear el CacheManager con ambos niveles
$identityMap = new IdentityMap();
$cacheManager = new CacheManager(
    identityMap: $identityMap,
    secondLevel: $redisCache,
    logger: $logger, // PSR-3 LoggerInterface (opcional)
);
```

### Parámetros del constructor de RedisCacheAdapter

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `$redis` | `\Redis` | Instancia de conexión Redis configurada |
| `$prefix` | `string` | Prefijo para las claves (por defecto: `sybase_orm:`) |

### Almacenamiento de datos

Las entidades se serializan con `serialize()` de PHP antes de almacenarse en Redis. Al recuperarlas, se deserializan automáticamente.

```php
// Las claves de entidad siguen el formato:
// {prefix}entity:{ClassName}:{id}

// Las claves de consulta siguen el formato:
// {prefix}query:{hash_md5}
```

## Consultas con Caché: queryCached()

El método `EntityManager::queryCached()` ejecuta una consulta OQL y almacena el resultado en el segundo nivel de caché. Si el resultado ya está en caché, lo devuelve sin consultar la base de datos.

### Firma del método

```php
public function queryCached(
    string $oql,
    array $params = [],
    int $ttl = 3600,
    int $hydrationMode = HydrationMode::HYDRATE_OBJECT
): array
```

### Parámetros

| Parámetro | Tipo | Por defecto | Descripción |
|-----------|------|-------------|-------------|
| `$oql` | `string` | — | Consulta OQL a ejecutar |
| `$params` | `array` | `[]` | Parámetros de la consulta |
| `$ttl` | `int` | `3600` | Tiempo de vida en segundos |
| `$hydrationMode` | `int` | `HYDRATE_OBJECT` | Modo de hidratación |

### Ejemplos de uso

```php
// Consulta con TTL por defecto (1 hora)
$productos = $em->queryCached(
    'SELECT p FROM App\Entity\Product p WHERE p.active = :active',
    ['active' => true]
);

// TTL personalizado: 5 minutos para datos que cambian frecuentemente
$pedidos = $em->queryCached(
    'SELECT o FROM App\Entity\Order o WHERE o.status = :status',
    ['status' => 'pending'],
    300
);

// TTL largo: 24 horas para catálogos estáticos
$categorias = $em->queryCached(
    'SELECT c FROM App\Entity\Category c ORDER BY c.name',
    [],
    86400
);
```

### Generación de clave de caché

La clave se genera automáticamente como un hash MD5 a partir de la combinación de:
- La consulta OQL
- Los parámetros serializados
- El modo de hidratación

Esto garantiza que consultas idénticas compartan la misma entrada de caché.

### Uso desde EntityRepository

```php
$repository = $em->getRepository(Product::class);
$result = $repository->queryCached(
    'SELECT p FROM App\Entity\Product p WHERE p.category = :cat',
    ['cat' => 'electronics'],
    1800 // 30 minutos
);
```

## Flujo de Búsqueda en el CacheManager

Cuando se solicita una entidad, el `CacheManager` busca en orden:

1. **Primer nivel (Identity Map)**: Búsqueda inmediata en memoria
2. **Segundo nivel (Redis)**: Si no está en el primer nivel, consulta Redis
3. **Base de datos**: Si no está en ningún caché, ejecuta la consulta SQL

Cuando se encuentra en el segundo nivel, la entidad se **promueve** al primer nivel para accesos posteriores en la misma sesión:

```php
// Internamente, el CacheManager opera así:
$entity = $identityMap->get($class, $id);    // 1. Primer nivel
if ($entity === null && $secondLevel) {
    $entity = $secondLevel->get($key);        // 2. Segundo nivel
    if ($entity !== null) {
        $identityMap->put($class, $id, $entity); // Promoción
    }
}
```

## Fallback Automático

Cuando el segundo nivel de caché no está disponible (Redis caído, error de conexión, timeout), el `CacheManager` realiza un fallback automático:

1. **Detecta la excepción** en cualquier operación contra el segundo nivel
2. **Desactiva el segundo nivel** para el resto de la sesión (`secondLevelAvailable = false`)
3. **Registra un warning** en el logger PSR-3 con el detalle del error
4. **Continúa operando** solo con el primer nivel (Identity Map)

```php
// El fallback es transparente para la aplicación
$usuario = $em->find(User::class, 1);
// Si Redis falla, la consulta va directo a la base de datos
// sin lanzar excepciones al código de la aplicación
```

### Verificar disponibilidad

```php
if ($cacheManager->isSecondLevelAvailable()) {
    // Segundo nivel operativo
} else {
    // Operando solo con Identity Map
}
```

### Comportamiento ante fallos

| Operación | Sin segundo nivel |
|-----------|-------------------|
| `get()` | Busca solo en Identity Map, luego base de datos |
| `put()` | Almacena solo en Identity Map |
| `invalidate()` | Elimina solo del Identity Map |
| `putQueryResult()` | No almacena (retorna sin acción) |
| `getQueryResult()` | Retorna `null` (fuerza re-ejecución de la consulta) |
| `clear()` | Limpia solo el Identity Map |

## Invalidación de Caché

Cuando una entidad se modifica o elimina, el `CacheManager` invalida las entradas correspondientes en ambos niveles:

```php
// Al hacer persist + flush, se invalida automáticamente
$usuario->setNombre('Nuevo Nombre');
$em->flush(); // Invalida la entrada de caché para este usuario

// Invalidación manual
$cacheManager->invalidate(User::class, 1);

// Limpiar todo el caché
$cacheManager->clear();
```

## Consideraciones de Seguridad

- **Autenticación Redis**: Siempre configure autenticación en Redis para prevenir accesos no autorizados
- **Aislamiento de red**: Redis debe estar en una red privada, no expuesto a internet
- **Prefijo de claves**: Use prefijos únicos por aplicación para evitar colisiones en Redis compartido

---

← [Anterior](./herencia-entidades.md) | [Índice](./README.md) | [Siguiente →](./sistema-migraciones.md)
