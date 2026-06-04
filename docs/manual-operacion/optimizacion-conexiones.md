# Optimización de Conexiones

La gestión eficiente de conexiones a Sybase ASE es crítica para el rendimiento en producción. El `ConnectionManager` del ORM ofrece conexiones persistentes, caché LRU de sentencias preparadas y reconexión automática. Este documento cubre las estrategias de optimización según el volumen de tráfico.

## Conexiones Persistentes

El `ConnectionManager` soporta conexiones persistentes mediante la opción `persistent`. Cuando se activa, PDO reutiliza la conexión TCP entre requests del mismo proceso PHP-FPM en lugar de abrir y cerrar una conexión por cada petición.

```php
use SybaseORM\ORM\OrmFactory;

$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => true,  // Reutiliza conexiones entre requests
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
];

$em = OrmFactory::create($config);
```

### Beneficios de conexiones persistentes

| Aspecto | Sin persistencia | Con persistencia |
|---------|-----------------|------------------|
| **Overhead por request** | Handshake TCP + autenticación | Ninguno (reutiliza) |
| **Latencia de conexión** | 5-50ms por request | Solo en primer request |
| **Statement cache** | Se pierde entre requests | Persiste con la conexión |
| **Carga en Sybase ASE** | Alta rotación de conexiones | Conexiones estables |

### Cuándo NO usar persistentes

- **Desarrollo:** Dificulta detectar fugas de conexión.
- **Scripts CLI de larga duración:** La conexión puede expirar por inactividad.
- **Aplicaciones con muchos workers y pocas queries:** Se mantienen conexiones ociosas.

## Connection Pooling con PHP-FPM

PHP no implementa un pool de conexiones a nivel de aplicación. El pooling se logra combinando **conexiones persistentes de PDO** con la gestión de procesos de **PHP-FPM**.

### Modelo de pool implícito

```
┌─────────────────────────────────────────────┐
│                PHP-FPM Pool                  │
├─────────────────────────────────────────────┤
│  Worker 1 ──── Conexión persistente ──┐     │
│  Worker 2 ──── Conexión persistente ──┼──► Sybase ASE
│  Worker 3 ──── Conexión persistente ──┤     │
│  ...                                  │     │
│  Worker N ──── Conexión persistente ──┘     │
└─────────────────────────────────────────────┘
```

Cada worker de PHP-FPM mantiene como máximo **una conexión persistente por DSN único**. El tamaño efectivo del pool es igual al número de workers activos.

### Relación workers ↔ conexiones

Conexiones máximas a Sybase = `pm.max_children`. Con `EntityManagerRegistry` y múltiples DSN:

```
Conexiones máximas = pm.max_children × número_de_DSN_distintos
```

## Límites de Conexión

### Configuración de PHP-FPM

El número de workers de PHP-FPM define el techo de conexiones simultáneas:

```ini
; /etc/php/8.1/fpm/pool.d/www.conf

; Pool dinámico: ajusta workers según demanda
pm = dynamic
pm.max_children = 50        ; Máximo de conexiones simultáneas a Sybase
pm.start_servers = 10       ; Workers iniciales
pm.min_spare_servers = 5    ; Mínimo de workers inactivos
pm.max_spare_servers = 20   ; Máximo de workers inactivos
pm.max_requests = 1000      ; Recicla worker cada N requests
```

### Configuración de Sybase ASE

Verificar que los límites permitan las conexiones de todos los workers:

```sql
-- Verificar conexiones máximas configuradas
sp_configure 'number of user connections'

-- Verificar conexiones actuales
SELECT COUNT(*) FROM master..sysprocesses WHERE hostprocess IS NOT NULL
```

**Regla práctica:** Configurar en Sybase ASE al menos un 20% más de conexiones que `pm.max_children`.

### Monitoreo de conexiones activas

Desde el ORM se puede verificar el estado de la conexión:

```php
if (!$connectionManager->ping()) {
    $connectionManager->reconnect();
}

$host = $connectionManager->getHost();       // Host configurado
$port = $connectionManager->getPort();       // Puerto configurado
$db = $connectionManager->getDatabaseName(); // Base de datos
```

## Reciclado de Conexiones

Las conexiones persistentes pueden degradarse con el tiempo. El reciclado garantiza conexiones saludables.

### Reciclado por PHP-FPM

La directiva `pm.max_requests` recicla el worker (y su conexión) tras N requests:

```ini
pm.max_requests = 1000  ; Worker se reinicia cada 1000 requests
```

### Reciclado manual vía ConnectionManager

El método `reconnect()` cierra la conexión actual y fuerza una nueva en el siguiente uso:

```php
// Forzar reconexión tras detectar una conexión muerta
$connectionManager->reconnect();

// La siguiente operación abre una conexión nueva automáticamente
$result = $connectionManager->executeQuery('SELECT 1');
```

Internamente, `reconnect()` limpia el estado completo: cierra la conexión PDO, limpia el caché de sentencias preparadas, resetea el estado de transacción y limpia los savepoints.

### Detección proactiva con ping()

Antes de operaciones críticas, verificar la salud de la conexión:

```php
if (!$connectionManager->ping()) {
    $logger->warning('Conexión perdida, reconectando...');
    $connectionManager->reconnect();
}

$connectionManager->beginTransaction();
// ... operaciones críticas ...
$connectionManager->commit();
```

## Caché de Sentencias Preparadas (LRU)

El `ConnectionManager` mantiene un caché interno de sentencias preparadas con política LRU y un máximo de **256 entradas** (`STMT_CACHE_MAX_SIZE`). Cada SQL ejecutado se prepara una vez y se reutiliza en llamadas posteriores. Cuando el caché se llena, se desaloja la sentencia menos usada. El caché se invalida automáticamente al llamar `reconnect()`.

| Escenario | Impacto |
|-----------|---------|
| **Sin persistencia** | Caché vive solo durante el request actual |
| **Con persistencia** | Caché persiste entre requests del mismo worker |
| **Tras reconnect()** | Caché se limpia (sentencias previas inválidas) |

## Configuración Recomendada por Volumen de Tráfico

### Bajo tráfico (< 50 req/s)

Aplicaciones internas o microservicios con poco volumen.

```php
$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => false, // Overhead de conexión es aceptable
    ],
];
```

```ini
; PHP-FPM
pm = dynamic
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
```

**Sybase ASE:** Configurar al menos 15 conexiones de usuario.

### Tráfico medio (50-500 req/s)

Aplicaciones web con tráfico moderado y constante.

```php
$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => true,          // Elimina overhead de conexión
        'charset_conversion' => true,
    ],
];
```

```ini
; PHP-FPM
pm = dynamic
pm.max_children = 50
pm.start_servers = 15
pm.min_spare_servers = 10
pm.max_spare_servers = 30
pm.max_requests = 1000
```

**Sybase ASE:** Configurar al menos 65 conexiones de usuario.

### Alto tráfico (> 500 req/s)

Aplicaciones de alta concurrencia con picos de carga.

```php
$config = [
    'connection' => [
        'host' => getenv('SYBASE_HOST'),
        'port' => (int) getenv('SYBASE_PORT'),
        'dbname' => getenv('SYBASE_DB'),
        'username' => getenv('SYBASE_USER'),
        'password' => getenv('SYBASE_PASS'),
        'charset' => 'UTF-8',
        'persistent' => true,
        'charset_conversion' => true,
        'read_only' => false,
    ],
];
```

```ini
; PHP-FPM - Pool estático para predecir uso de recursos
pm = static
pm.max_children = 100         ; Fijo: evita overhead de escalar dinámicamente
pm.max_requests = 2000        ; Reciclado menos agresivo para estabilidad
```

**Sybase ASE:** Configurar al menos 130 conexiones de usuario.

**Estrategia adicional:** Usar réplicas de lectura con `read_only => true` para distribuir carga:

```php
use SybaseORM\ORM\EntityManagerRegistry;

$registry = new EntityManagerRegistry();
$registry->addEntityManager('default', OrmFactory::create($writeConfig));

// Réplica de lectura
$readConfig['connection']['host'] = getenv('SYBASE_READ_HOST');
$readConfig['connection']['read_only'] = true;
$registry->addEntityManager('read', OrmFactory::create($readConfig));

// Consultas pesadas en réplica
$readEm = $registry->getEntityManager('read');
$results = $readEm->query('SELECT u FROM App\Entity\User u WHERE u.active = :a', ['a' => true]);
```

## Resumen de Opciones del ConnectionManager

| Opción | Tipo | Default | Impacto en conexiones |
|--------|------|---------|----------------------|
| `persistent` | `bool` | `false` | Reutiliza conexión TCP entre requests |
| `host` | `string` | `localhost` | Host del servidor Sybase ASE |
| `port` | `int` | `5000` | Puerto de conexión (1-65535) |
| `read_only` | `bool` | `false` | Previene escrituras (para réplicas) |
| `charset` | `string` | `UTF-8` | Charset usado en el DSN |
| `charset_conversion` | `bool` | `false` | Convierte ISO-8859-1 ↔ UTF-8 |

## Checklist de Optimización

- [ ] Activar `persistent => true` en producción
- [ ] Configurar `pm.max_children` según carga esperada
- [ ] Verificar límite de conexiones en Sybase ASE (20% sobre max_children)
- [ ] Configurar `pm.max_requests` para reciclado periódico
- [ ] Implementar verificación con `ping()` antes de operaciones críticas
- [ ] Considerar réplicas `read_only` para consultas pesadas

---

← [Anterior](./configuracion-entornos.md) | [Índice](./README.md) | [Siguiente →](./cache-produccion.md)
