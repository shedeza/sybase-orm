# Logging y Monitoreo

El ORM `shedeza/sybase-orm` integra logging mediante la interfaz estándar PSR-3 (`Psr\Log\LoggerInterface`). Tanto `ConnectionManager` como `CacheManager` aceptan un logger opcional en su constructor, permitiendo capturar eventos operacionales sin acoplar el ORM a una implementación de logging específica.

## Integración PSR-3

### Inyección del Logger

Los componentes principales reciben el logger como dependencia opcional:

```php
use Psr\Log\LoggerInterface;
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Cache\CacheManager;

// ConnectionManager acepta logger como segundo parámetro
$connection = new ConnectionManager($config, $logger);

// CacheManager acepta logger como tercer parámetro
$cache = new CacheManager($identityMap, $secondLevelCache, $logger);
```

Si no se proporciona un logger, los componentes operan silenciosamente sin registrar eventos.

### Implementaciones Compatibles

Cualquier librería que implemente `Psr\Log\LoggerInterface` es compatible:

| Librería | Paquete Composer |
|----------|-----------------|
| Monolog | `monolog/monolog` |
| Symfony Logger | `symfony/monolog-bundle` |
| Laravel Log | Integrado en framework |
| PHP-FIG NullLogger | `psr/log` (incluido) |

### Ejemplo con Monolog

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use SybaseORM\Connection\ConnectionManager;

$logger = new Logger('sybase-orm');
$logger->pushHandler(new RotatingFileHandler('/var/log/app/orm.log', 14, Logger::WARNING));
$logger->pushHandler(new StreamHandler('php://stderr', Logger::ERROR));

$connection = new ConnectionManager([
    'host' => 'sybase-prod.internal',
    'port' => 5000,
    'dbname' => 'produccion',
    'username' => 'app_user',
    'password' => '***',
    'charset_conversion' => true,
], $logger);
```

## Eventos Registrados por el ORM

### ConnectionManager

| Nivel | Evento | Contexto |
|-------|--------|----------|
| `warning` | Fallo en conversión de charset (UTF-8 → ISO-8859-1) | Valor truncado a 100 caracteres |
| `warning` | Fallo en conversión de charset (ISO-8859-1 → UTF-8) | Valor truncado a 100 caracteres |

El `ConnectionManager` registra warnings cuando la conversión de charset falla, permitiendo identificar datos con codificación inesperada sin interrumpir la operación.

### CacheManager

| Nivel | Evento | Contexto |
|-------|--------|----------|
| `warning` | Segundo nivel de caché no disponible | Excepción completa en contexto `['exception' => $e]` |

Cuando el segundo nivel de caché (Redis) falla, el `CacheManager` registra un warning y degrada automáticamente a solo primer nivel (Identity Map). Esto permite detectar problemas de conectividad con Redis sin afectar la disponibilidad de la aplicación.

```php
// Ejemplo de log generado por CacheManager
// [WARNING] Second-level cache unavailable, falling back to first-level only: Connection refused
// Context: ['exception' => Redis\ConnectionException(...)]
```

## Configuración de Logging por Entorno

### Desarrollo

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('sybase-orm');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// En desarrollo: registrar todo para facilitar debugging
$connection = new ConnectionManager($config, $logger);
$cache = new CacheManager($identityMap, $secondLevel, $logger);
```

### Staging

```php
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

$logger = new Logger('sybase-orm');
$logger->pushHandler(new RotatingFileHandler(
    '/var/log/app/orm.log',
    7,            // retener 7 días
    Logger::INFO  // INFO y superior
));

$connection = new ConnectionManager($config, $logger);
$cache = new CacheManager($identityMap, $secondLevel, $logger);
```

### Producción

```php
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SyslogHandler;

$logger = new Logger('sybase-orm');

// Archivo rotado para warnings/errors
$logger->pushHandler(new RotatingFileHandler(
    '/var/log/app/orm-errors.log',
    30,              // retener 30 días
    Logger::WARNING  // solo WARNING y superior
));

// Syslog para alertas críticas (integración con sistemas de monitoreo)
$logger->pushHandler(new SyslogHandler('sybase-orm', LOG_USER, Logger::ERROR));

$connection = new ConnectionManager($config, $logger);
$cache = new CacheManager($identityMap, $secondLevel, $logger);
```

### Tabla Comparativa

| Aspecto | Desarrollo | Staging | Producción |
|---------|-----------|---------|------------|
| Nivel mínimo | DEBUG | INFO | WARNING |
| Destino | stdout | Archivo rotado (7d) | Archivo rotado (30d) + syslog |
| Rotación | No | Diaria | Diaria |
| Formato | Texto plano | Texto + timestamp | JSON (para parseo automatizado) |

## Métricas de Rendimiento del Pool de Conexiones

El `ConnectionManager` expone información útil para monitoreo mediante sus métodos públicos:

```php
// Verificar si la conexión está activa
$isAlive = $connection->ping();

// Obtener información del servidor
$version = $connection->getServerVersion();
$dbName = $connection->getDatabaseName();
$host = $connection->getHost();
$port = $connection->getPort();

// Verificar si es read-only
$readOnly = $connection->isReadOnly();

// Verificar estado transaccional
$inTransaction = $connection->isInTransaction();
```

### Métricas Recomendadas para Monitoreo

| Métrica | Cómo obtenerla | Umbral de alerta |
|---------|---------------|-----------------|
| Conexión activa | `$connection->ping()` | `false` → alerta inmediata |
| Caché segundo nivel disponible | `$cache->isSecondLevelAvailable()` | `false` → warning |
| Estado transaccional | `$connection->isInTransaction()` | `true` por >30s → investigar |
| Modo read-only | `$connection->isReadOnly()` | Validar contra esperado |

### Implementación de Health Check

```php
class OrmHealthCheck
{
    public function __construct(
        private ConnectionManager $connection,
        private CacheManager $cache,
    ) {}

    public function check(): array
    {
        return [
            'database_reachable' => $this->connection->ping(),
            'database_host' => $this->connection->getHost(),
            'database_port' => $this->connection->getPort(),
            'database_name' => $this->connection->getDatabaseName(),
            'server_version' => $this->safeGetVersion(),
            'read_only' => $this->connection->isReadOnly(),
            'in_transaction' => $this->connection->isInTransaction(),
            'cache_second_level' => $this->cache->isSecondLevelAvailable(),
        ];
    }

    private function safeGetVersion(): string
    {
        try {
            return $this->connection->getServerVersion();
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
```

## Alertas Recomendadas

### Alertas Críticas (acción inmediata)

| Condición | Detección | Acción |
|-----------|-----------|--------|
| `ping()` retorna `false` | Health check cada 30s | Verificar conectividad de red y estado del servidor Sybase |
| `ConnectionLostException` recurrente | Contador en logs | Revisar firewall, timeouts de red, estado del servidor |
| Deadlocks frecuentes (>5/min) | Filtrar `isDeadlock()` en logs | Revisar concurrencia y orden de locks |

### Alertas de Warning (investigar)

| Condición | Detección | Acción |
|-----------|-----------|--------|
| `isSecondLevelAvailable()` es `false` | Health check periódico | Verificar conectividad con Redis |
| Warnings de charset repetidos | Contador en logs | Revisar datos de entrada y encoding |
| Transacciones largas (>30s) | Monitoreo de `isInTransaction()` | Revisar lógica de negocio, posible leak |

### Integración con Sistemas de Alertas

```php
// Ejemplo: exportar métricas para Prometheus/Grafana
class OrmMetricsExporter
{
    public function __construct(
        private ConnectionManager $connection,
        private CacheManager $cache,
    ) {}

    public function collect(): array
    {
        return [
            'orm_db_up' => $this->connection->ping() ? 1 : 0,
            'orm_cache_l2_up' => $this->cache->isSecondLevelAvailable() ? 1 : 0,
            'orm_db_readonly' => $this->connection->isReadOnly() ? 1 : 0,
            'orm_db_in_transaction' => $this->connection->isInTransaction() ? 1 : 0,
        ];
    }
}
```

### Dashboard Recomendado

Métricas clave para un dashboard de monitoreo del ORM:

1. **Disponibilidad de BD** — `orm_db_up` (gauge, 0/1)
2. **Disponibilidad de caché L2** — `orm_cache_l2_up` (gauge, 0/1)
3. **Tasa de warnings de charset** — Contador de logs `warning` por minuto
4. **Tasa de reconexiones** — Contador de `ConnectionLostException` por minuto
5. **Transacciones activas** — `orm_db_in_transaction` (gauge)

## Formato de Logs para Producción

Para facilitar el parseo automatizado en producción, se recomienda formato JSON:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

$logger = new Logger('sybase-orm');

$handler = new StreamHandler('/var/log/app/orm.log', Logger::WARNING);
$handler->setFormatter(new JsonFormatter());
$logger->pushHandler($handler);
```

Ejemplo de salida JSON:

```json
{
  "message": "Second-level cache unavailable, falling back to first-level only: Connection refused",
  "context": {"exception": "..."},
  "level": 300,
  "level_name": "WARNING",
  "channel": "sybase-orm",
  "datetime": "2024-01-15T10:30:45+00:00"
}
```

---

← [Anterior](./optimizacion-consultas.md) | [Índice](./README.md) | [Siguiente →](./troubleshooting.md)
