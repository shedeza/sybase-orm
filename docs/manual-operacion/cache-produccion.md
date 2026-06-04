# Caché de Segundo Nivel en Producción

Guía de configuración, dimensionamiento y monitoreo del caché de segundo nivel basado en Redis para `shedeza/sybase-orm`. El ORM utiliza `CacheManager` para coordinar dos niveles de caché: el Identity Map (primer nivel, por sesión) y `RedisCacheAdapter` (segundo nivel, compartido entre procesos).

## Arquitectura del Caché

```
┌─────────────────────────────────────────────────┐
│  CacheManager (CacheManagerInterface)           │
│  ┌───────────────┐   ┌───────────────────────┐  │
│  │ Identity Map  │   │ SecondLevelCacheInterface│ │
│  │ (1er nivel)   │   │  → RedisCacheAdapter   │  │
│  └───────────────┘   └───────────────────────┘  │
└─────────────────────────────────────────────────┘
```

- **Primer nivel (IdentityMap):** Automático, por request/sesión, sin configuración.
- **Segundo nivel (RedisCacheAdapter):** Compartido entre procesos, requiere Redis y configuración de TTL.

## Configuración de Redis

### Conexión básica

```php
use SybaseORM\Cache\RedisCacheAdapter;
use SybaseORM\Cache\CacheManager;
use SybaseORM\ORM\IdentityMap;

$redis = new \Redis();
$redis->connect('redis-host', 6379);
$redis->auth('password-seguro');
$redis->select(2); // DB dedicada para el ORM

$adapter = new RedisCacheAdapter($redis, 'sybase_orm:');
$cacheManager = new CacheManager(
    new IdentityMap(),
    $adapter,
    $logger // PSR-3 LoggerInterface
);
```

### Parámetros de conexión recomendados

| Parámetro | Producción | Descripción |
|-----------|-----------|-------------|
| `timeout` | 2.0 s | Timeout de conexión inicial |
| `read_timeout` | 1.0 s | Timeout de lectura por operación |
| `retry_interval` | 100 ms | Intervalo entre reintentos |
| `persistent` | `true` | Reutilizar conexión entre requests |
| `database` | DB dedicada | Evitar colisión con otros servicios |

```php
$redis->connect('redis-host', 6379, 2.0, null, 100, 1.0);
$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
```

## Dimensionamiento de TTL

El TTL controla cuánto tiempo permanece un valor en caché antes de expirar. Un TTL muy bajo genera muchos cache-miss; uno muy alto puede servir datos obsoletos.

### Estrategia por tipo de dato

| Tipo de dato | TTL recomendado | Justificación |
|-------------|----------------|---------------|
| Entidades de referencia (catálogos) | 3600–7200 s | Cambian raramente |
| Entidades transaccionales | 300–900 s | Balance frescura/rendimiento |
| Resultados de consultas frecuentes | 60–300 s | Datos que cambian moderadamente |
| Consultas con agregaciones | 120–600 s | Costosas de recalcular |

### Uso de TTL con `putQueryResult()`

```php
// Caché de consulta con TTL de 5 minutos
$cacheManager->putQueryResult(
    'usuarios_activos_count',
    $resultado,
    300 // TTL en segundos
);
```

### Uso con `queryCached()` del EntityManager

```php
// El segundo parámetro es el TTL en segundos
$usuarios = $em->queryCached(
    'SELECT u FROM App\Entity\Usuario u WHERE u.activo = :activo',
    ['activo' => true],
    600 // 10 minutos
);
```

## Políticas de Evicción en Redis

La política de evicción determina qué claves elimina Redis cuando alcanza el límite de memoria.

### Políticas recomendadas

| Política | Uso recomendado |
|----------|----------------|
| `allkeys-lru` | **Recomendada.** Elimina las claves menos usadas recientemente |
| `volatile-lru` | Solo elimina claves con TTL definido (LRU) |
| `volatile-ttl` | Elimina primero claves con TTL más cercano a expirar |
| `noeviction` | No recomendada — retorna error cuando se llena la memoria |

### Configuración en `redis.conf`

```ini
maxmemory 512mb
maxmemory-policy allkeys-lru
maxmemory-samples 10
```

### Dimensionamiento de memoria

Regla general para estimar la memoria necesaria:

```
Memoria ≈ (nº entidades en caché × tamaño promedio serializado) + 20% overhead
```

| Escenario | Entidades estimadas | Memoria sugerida |
|-----------|-------------------|------------------|
| Baja carga (<100 req/s) | ~5,000 | 128–256 MB |
| Media carga (100–500 req/s) | ~25,000 | 256–512 MB |
| Alta carga (>500 req/s) | ~100,000 | 512 MB – 2 GB |

## Invalidación de Caché

`CacheManager` invalida automáticamente entradas cuando se actualiza o elimina una entidad:

```php
// Al hacer persist/flush, CacheManager::invalidate() se invoca internamente
$cacheManager->invalidate(Usuario::class, $userId);
```

### Estrategias de invalidación

1. **Invalidación por entidad:** Automática vía `CacheManager::invalidate()`.
2. **Invalidación de consultas:** Manual — limpiar claves de query cuando los datos subyacentes cambian.
3. **Limpieza total:** `$cacheManager->clear()` para ambos niveles.

## Monitoreo del Hit-Rate

El hit-rate es la métrica principal para evaluar la efectividad del caché.

### Obtener métricas de Redis

```bash
redis-cli INFO stats | grep -E "keyspace_(hits|misses)"
```

### Cálculo del hit-rate

```
hit_rate = keyspace_hits / (keyspace_hits + keyspace_misses) × 100
```

### Métricas clave y umbrales

| Métrica | Umbral saludable | Acción si fuera de rango |
|---------|-----------------|--------------------------|
| Hit-rate | ≥ 85% | Revisar TTL (muy cortos) o precalentamiento |
| Hit-rate crítico | < 60% | Revisar si las claves se invalidan excesivamente |
| Memoria usada | < 80% de maxmemory | Aumentar maxmemory o reducir TTL |
| Evicted keys/s | < 100/s | Aumentar memoria o revisar política de evicción |
| Connected clients | < 80% de maxclients | Verificar connection pooling |
| Latencia p99 | < 5 ms | Revisar tamaño de objetos serializados |

### Monitoreo continuo con Redis INFO

```bash
# Resumen completo de métricas
redis-cli INFO all

# Solo métricas de memoria
redis-cli INFO memory

# Claves del ORM activas
redis-cli DBSIZE
redis-cli --scan --pattern 'sybase_orm:*' | wc -l
```

### Script de verificación de salud

```bash
#!/bin/bash
HITS=$(redis-cli INFO stats | grep keyspace_hits | cut -d: -f2 | tr -d '\r')
MISSES=$(redis-cli INFO stats | grep keyspace_misses | cut -d: -f2 | tr -d '\r')
TOTAL=$((HITS + MISSES))

if [ "$TOTAL" -gt 0 ]; then
    RATE=$(echo "scale=2; $HITS * 100 / $TOTAL" | bc)
    echo "Cache hit-rate: ${RATE}%"
    if (( $(echo "$RATE < 60" | bc -l) )); then
        echo "ALERTA: Hit-rate por debajo del umbral crítico"
    fi
fi
```

## Fallback Automático

`CacheManager` maneja automáticamente la indisponibilidad de Redis. Si `RedisCacheAdapter` lanza una excepción, el manager:

1. Marca el segundo nivel como no disponible (`isSecondLevelAvailable() → false`).
2. Registra un warning via PSR-3 Logger.
3. Continúa operando solo con el Identity Map (primer nivel).

```php
// Verificar disponibilidad programáticamente
if (!$cacheManager->isSecondLevelAvailable()) {
    $logger->warning('Caché de segundo nivel no disponible, operando solo con Identity Map');
}
```

> **Nota:** Una vez deshabilitado, el segundo nivel no se reactiva automáticamente en la misma sesión. Se requiere reiniciar la instancia de `CacheManager`.

## Checklist de Producción

- [ ] Redis con autenticación (`requirepass`) y red privada
- [ ] Base de datos dedicada para el ORM (`SELECT N`)
- [ ] Política de evicción `allkeys-lru` configurada
- [ ] `maxmemory` dimensionado según carga esperada
- [ ] TTL definido en todas las consultas cacheadas
- [ ] Monitoreo de hit-rate con alertas (umbral < 60%)
- [ ] Logs PSR-3 configurados para capturar warnings de fallback
- [ ] Conexiones persistentes habilitadas si hay alto throughput
- [ ] Backup/snapshot de Redis no impacta latencia (usar replica para backups)

## Alertas Recomendadas

| Alerta | Condición | Severidad |
|--------|-----------|-----------|
| Hit-rate bajo | < 60% por 5 minutos | Warning |
| Redis no disponible | Conexión fallida | Critical |
| Memoria alta | > 90% de maxmemory | Warning |
| Evictions excesivas | > 500 keys/s por 1 minuto | Warning |
| Latencia alta | p99 > 10 ms por 2 minutos | Warning |

---

← [Anterior](./optimizacion-conexiones.md) | [Índice](./README.md) | [Siguiente →](./optimizacion-consultas.md)
