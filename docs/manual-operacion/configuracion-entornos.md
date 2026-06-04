# Configuración por Entornos

El ORM requiere configuraciones distintas según el entorno de ejecución. Este documento detalla las diferencias clave entre desarrollo, staging y producción para las áreas de caché, logging y conexiones.

## Tabla Comparativa de Configuración

| Aspecto | Desarrollo | Staging | Producción |
|---------|-----------|---------|------------|
| **Caché segundo nivel** | Deshabilitado (`null`) | Redis (TTL corto) | Redis (TTL optimizado) |
| **Logger nivel** | `debug` | `warning` | `error` |
| **Conexiones persistentes** | `false` | `false` | `true` |
| **charset_conversion** | `false` | `true` | `true` |
| **read_only** | `false` | `false` | Según réplica |
| **proxy_directory** | `/tmp/sybase-orm-proxies` | Directorio dedicado | Directorio pre-generado |
| **metadata_cache_dir** | `null` (sin caché) | Directorio temporal | Directorio persistente |
| **Statement cache (LRU)** | Activo (256 max) | Activo (256 max) | Activo (256 max) |

## Configuración de Desarrollo

En desarrollo se prioriza la visibilidad y la facilidad de depuración sobre el rendimiento.

```php
use Psr\Log\LogLevel;
use SybaseORM\ORM\OrmFactory;

$config = [
    'connection' => [
        'host' => 'localhost',
        'port' => 5000,
        'dbname' => 'myapp_dev',
        'username' => 'sa',
        'password' => 'dev_password',
        'charset' => 'UTF-8',
        'persistent' => false,        // Sin persistencia: cada request abre y cierra
        'charset_conversion' => false, // Sin conversión en dev
        'read_only' => false,
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
    'proxy_directory' => sys_get_temp_dir() . '/sybase-orm-proxies',
    'metadata_cache_dir' => null, // Sin caché de metadata: detecta cambios al instante
];

// Logger en nivel debug para máxima visibilidad
$logger = new MyPsrLogger(LogLevel::DEBUG);

$em = OrmFactory::create($config, $logger);
```

### Caché en desarrollo

Se recomienda **no configurar segundo nivel** de caché. El `CacheManager` opera solo con el Identity Map (primer nivel), lo que permite ver el estado real de la base de datos sin capas intermedias:

```php
// Sin segundo nivel — CacheManager usa solo Identity Map
use SybaseORM\Cache\CacheManager;
use SybaseORM\ORM\IdentityMap;

$cacheManager = new CacheManager(
    identityMap: new IdentityMap(),
    secondLevel: null,  // Deshabilitado en desarrollo
    logger: $logger,
);
```

### Logging en desarrollo

Se configura nivel `debug` para capturar todas las consultas SQL, tiempos de ejecución y operaciones del Unit of Work:

```php
// El logger recibe TODAS las operaciones
// - Consultas SQL ejecutadas
// - Parámetros de binding
// - Operaciones de reconexión
// - Warnings de caché
```

### Conexiones en desarrollo

- `persistent => false`: Cada request crea una conexión nueva, lo que facilita detectar fugas de conexión.
- Sin necesidad de configurar límites de conexiones.
- El statement cache LRU sigue activo (máximo 256 sentencias), lo que acelera consultas repetidas dentro de un mismo request.

## Configuración de Staging

Staging replica la configuración de producción con ajustes para facilitar la detección de problemas antes del despliegue.

```php
use Psr\Log\LogLevel;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\RedisCacheAdapter;
use SybaseORM\ORM\OrmFactory;
use SybaseORM\ORM\IdentityMap;

$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => false,        // Sin persistencia para detectar problemas de conexión
        'charset_conversion' => true,  // Activado como en producción
        'read_only' => false,
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
    'proxy_directory' => '/var/app/proxies',
    'metadata_cache_dir' => '/var/app/cache/metadata',
];

$logger = new MyPsrLogger(LogLevel::WARNING);

$em = OrmFactory::create($config, $logger);
```

### Caché en staging

Se habilita Redis con TTL reducido para validar la integración sin enmascarar problemas:

```php
$redis = new \Redis();
$redis->connect(getenv('REDIS_HOST'), 6379);

$secondLevel = new RedisCacheAdapter($redis, 'staging_orm:');

$cacheManager = new CacheManager(
    identityMap: new IdentityMap(),
    secondLevel: $secondLevel,
    logger: $logger,
);

// queryCached() con TTL corto para detectar inconsistencias
$results = $em->queryCached(
    'SELECT u FROM App\Entity\User u WHERE u.active = :active',
    ['active' => true],
    ttl: 60  // 1 minuto en staging vs 5-10 minutos en producción
);
```

### Logging en staging

Nivel `warning` para capturar degradaciones sin generar volumen excesivo de logs:

- Captura fallbacks de segundo nivel de caché
- Captura reconexiones automáticas
- No captura cada consulta SQL individual

## Configuración de Producción

En producción se optimiza para rendimiento, resiliencia y mínima sobrecarga de logging.

```php
use Psr\Log\LogLevel;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\RedisCacheAdapter;
use SybaseORM\ORM\OrmFactory;
use SybaseORM\ORM\IdentityMap;

$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => true,          // Reutiliza conexiones entre requests
        'charset_conversion' => true,  // Asegura compatibilidad de charset
        'read_only' => false,          // true solo para réplicas de lectura
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
    'proxy_directory' => '/var/app/proxies',       // Pre-generados en deploy
    'metadata_cache_dir' => '/var/app/cache/metadata', // Persistente entre deploys
];

$logger = new MyPsrLogger(LogLevel::ERROR);

$em = OrmFactory::create($config, $logger);
```

### Caché en producción

Redis habilitado con TTL apropiado y prefijo de entorno:

```php
$redis = new \Redis();
$redis->connect(getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'));
$redis->auth(getenv('REDIS_PASSWORD'));

$secondLevel = new RedisCacheAdapter($redis, 'prod_orm:');

$cacheManager = new CacheManager(
    identityMap: new IdentityMap(),
    secondLevel: $secondLevel,
    logger: $logger,
);
```

El `CacheManager` maneja automáticamente el fallback: si Redis falla, registra un warning y opera solo con Identity Map sin interrumpir la aplicación.

### Logging en producción

Solo nivel `error` para minimizar I/O de disco y capturar únicamente fallos críticos:

- `ConnectionLostException` al fallar conexión
- Errores irrecuperables de transacción
- No se loguean consultas normales ni warnings de caché

### Conexiones en producción

Con `persistent => true`, el `ConnectionManager` configura `PDO::ATTR_PERSISTENT = true`, lo que permite reutilizar la conexión TCP entre requests del mismo proceso PHP-FPM:

```php
// ConnectionManager internamente configura:
$options = [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_PERSISTENT => true,  // Reutilización de conexión
];
```

**Consideraciones:**
- El statement cache LRU (256 sentencias) persiste con la conexión, mejorando aún más el rendimiento.
- Usar `ping()` para verificar que una conexión persistente sigue activa.
- Usar `reconnect()` si `ping()` falla.

## Réplicas de Lectura

Para configurar una réplica como solo lectura:

```php
$readConfig = [
    'connection' => [
        'host' => getenv('SYBASE_READ_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_READ_USER'),
        'password' => getenv('SYBASE_READ_PASS'),
        'charset' => 'UTF-8',
        'persistent' => true,
        'read_only' => true,  // Previene operaciones de escritura
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
];

$readEm = OrmFactory::create($readConfig, $logger);
// $readEm->persist($entity); // Lanza excepción — modo read_only
```

## Variables de Entorno Recomendadas

| Variable | Descripción | Ejemplo |
|----------|------------|---------|
| `SYBASE_HOST` | Host del servidor Sybase ASE | `sybase-prod.internal` |
| `SYBASE_PORT` | Puerto de conexión | `5000` |
| `SYBASE_DB` | Nombre de la base de datos | `myapp_production` |
| `SYBASE_USER` | Usuario de conexión | `app_user` |
| `SYBASE_PASS` | Contraseña de conexión | `(secreto)` |
| `REDIS_HOST` | Host del servidor Redis | `redis.internal` |
| `REDIS_PORT` | Puerto Redis | `6379` |
| `REDIS_PASSWORD` | Contraseña Redis | `(secreto)` |

## Resumen de Opciones Relevantes por Entorno

| Opción `ConnectionManager` | Tipo | Dev | Staging | Producción |
|----------------------------|------|-----|---------|------------|
| `persistent` | `bool` | `false` | `false` | `true` |
| `charset_conversion` | `bool` | `false` | `true` | `true` |
| `read_only` | `bool` | `false` | `false` | Según uso |
| `charset` | `string` | `UTF-8` | `UTF-8` | `UTF-8` |

| Opción `OrmFactory` | Tipo | Dev | Staging | Producción |
|---------------------|------|-----|---------|------------|
| `proxy_directory` | `string` | `/tmp/...` | Dedicado | Pre-generado |
| `metadata_cache_dir` | `string\|null` | `null` | Temporal | Persistente |

| Aspecto `CacheManager` | Dev | Staging | Producción |
|------------------------|-----|---------|------------|
| Segundo nivel | `null` | Redis (TTL corto) | Redis (TTL largo) |
| Fallback automático | N/A | Activo | Activo |
| Prefijo Redis | N/A | `staging_orm:` | `prod_orm:` |

---

← [Anterior](./despliegue-requisitos.md) | [Índice](./README.md) | [Siguiente →](./optimizacion-conexiones.md)
