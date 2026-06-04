# Troubleshooting — Problemas Comunes

Guía de diagnóstico y resolución de los problemas más frecuentes al operar `shedeza/sybase-orm` en entornos productivos.

## 1. Conexiones Perdidas

### Síntomas

- Se lanza `ConnectionLostException` con mensajes como *"Connection to Sybase ASE was lost"*.
- Las consultas fallan intermitentemente con SQLSTATE `08S01`, `08001` o `HY000`.

### Diagnóstico

1. **Verificar conectividad de red** — Confirmar que el servidor Sybase ASE es alcanzable desde la aplicación (`telnet host 5000`).
2. **Revisar logs del servidor ASE** — Buscar desconexiones forzadas por timeout de inactividad o límites de conexión.
3. **Usar `ping()` para confirmar estado:**

```php
$connection = $entityManager->getConnection();

if (!$connection->ping()) {
    // La conexión está caída
    $connection->reconnect();
}
```

4. **Revisar el timeout del servidor** — El parámetro `connection timeout` de ASE puede cerrar conexiones inactivas.

### Soluciones

| Causa | Solución |
|-------|----------|
| Timeout de inactividad del servidor | Configurar keep-alive o usar `ping()` periódico |
| Conexiones persistentes recicladas | Llamar `ConnectionManager::reconnect()` antes de reusar |
| Firewall cortando conexiones idle | Reducir el intervalo de reciclado de conexiones |
| Límite de conexiones del servidor | Aumentar `max connections` en ASE o usar connection pooling |

### Patrón de reconexión

```php
use SybaseORM\Exception\ConnectionLostException;

try {
    $result = $entityManager->query('SELECT u FROM User u');
} catch (ConnectionLostException $e) {
    // Reconectar y reintentar
    $entityManager->getConnection()->reconnect();
    $result = $entityManager->query('SELECT u FROM User u');
}
```

## 2. Deadlocks

### Síntomas

- `PersistenceException` con mensaje que contiene *"deadlock"* o *"error 1205"*.
- El error *"chosen as the deadlock victim"* aparece en los logs.

### Diagnóstico

1. **Detectar programáticamente:**

```php
use SybaseORM\Connection\ConnectionManager;
use SybaseORM\Exception\PersistenceException;

try {
    $entityManager->flush();
} catch (PersistenceException $e) {
    $previous = $e->getPrevious();
    if ($previous instanceof \PDOException && ConnectionManager::isDeadlock($previous)) {
        // Es un deadlock — se puede reintentar
    }
}
```

2. **Revisar en ASE** — Ejecutar `sp_who` y `sp_lock` para identificar bloqueos activos.
3. **Analizar orden de acceso a tablas** — Los deadlocks ocurren cuando dos transacciones bloquean recursos en orden opuesto.

### Soluciones

| Causa | Solución |
|-------|----------|
| Acceso a tablas en orden inconsistente | Ordenar operaciones por nombre de tabla |
| Transacciones largas | Reducir duración del `flush()`, dividir en lotes |
| Nivel de aislamiento alto | Evaluar si `READ COMMITTED` es suficiente |
| Consultas sin índices | Agregar índices para reducir lock escalation |

### Patrón de retry para deadlocks

```php
$maxRetries = 3;
$attempt = 0;

while ($attempt < $maxRetries) {
    try {
        $entityManager->transactional(function ($em) {
            // ... operaciones de persistencia
            $em->flush();
        });
        break; // Éxito
    } catch (PersistenceException $e) {
        $previous = $e->getPrevious();
        if ($previous instanceof \PDOException && ConnectionManager::isDeadlock($previous)) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            usleep(100_000 * $attempt); // Backoff exponencial
        } else {
            throw $e; // No es deadlock, relanzar
        }
    }
}
```

## 3. Timeouts de Consultas

### Síntomas

- Las consultas se cuelgan por tiempo indefinido.
- El mensaje *"connection timed out"* aparece en `ConnectionLostException`.
- Consultas OQL con JOINs complejos tardan más de lo esperado.

### Diagnóstico

1. **Identificar consultas lentas** — Habilitar logging PSR-3 para capturar el SQL generado.
2. **Ejecutar el SQL directamente en ASE** — Comparar tiempo de ejecución fuera del ORM.
3. **Revisar plan de ejecución** — Usar `SET SHOWPLAN ON` en ASE para analizar la consulta.

### Soluciones

| Causa | Solución |
|-------|----------|
| Consulta sin índices | Agregar índices en columnas de WHERE y JOIN |
| Consultas N+1 | Usar eager loading con `QueryBuilder::with()` |
| Resultado muy grande | Usar `limit()` y `offset()` para paginar |
| Lock wait | Verificar deadlocks/bloqueos en otras sesiones |

### Configuración de timeout en PDO

```php
$config = [
    'host' => 'sybase-host',
    'port' => 5000,
    'dbname' => 'production',
    'username' => 'app_user',
    'password' => 'secret',
    // El timeout se controla a nivel de freetds.conf:
    // [sybase-host]
    //   timeout = 30
    //   login timeout = 10
];
```

## 4. Memoria Excesiva por Identity Map

### Síntomas

- Consumo de memoria crece linealmente con el número de entidades consultadas.
- `memory_limit` de PHP se alcanza en procesos batch o workers de larga duración.
- El método `IdentityMap::count()` devuelve valores elevados (miles de entidades).

### Diagnóstico

1. **Verificar tamaño del Identity Map:**

```php
$identityMap = $entityManager->getIdentityMap();
$total = $identityMap->count();
echo "Entidades en memoria: {$total}";
```

2. **Identificar procesos batch** — Workers, importaciones masivas o cron jobs que procesan miles de registros sin limpiar.
3. **Monitorear `memory_get_usage()`** antes y después de operaciones bulk.

### Soluciones

| Causa | Solución |
|-------|----------|
| Proceso batch sin limpieza | Llamar `IdentityMap::clear()` o `EntityManager::clear()` periódicamente |
| Iteración sobre colecciones grandes | Usar `queryIterator()` y limpiar cada N registros |
| Entidades con relaciones cargadas | Limpiar clases específicas con `IdentityMap::clearClass()` |

### Patrón para procesos batch

```php
$batchSize = 100;
$iterator = $entityManager->queryIterator('SELECT u FROM User u');
$count = 0;

foreach ($iterator as $user) {
    // Procesar usuario...
    $count++;

    if ($count % $batchSize === 0) {
        $entityManager->flush();
        $entityManager->clear(); // Libera toda la memoria del Identity Map
    }
}

// Flush final
$entityManager->flush();
$entityManager->clear();
```

## 5. Errores de Charset

### Síntomas

- Caracteres especiales (ñ, á, ü) se almacenan o recuperan como `?` o secuencias incorrectas.
- `TypeConversionException` en campos de texto con contenido multibyte.
- Datos legibles en ASE pero corruptos al hidratar en PHP.

### Diagnóstico

1. **Verificar configuración de charset:**

```php
$config = [
    'charset' => 'UTF-8',
    'charset_conversion' => true, // Habilitar si ASE usa ISO-8859-1
];
```

2. **Comparar encodings** — ASE tradicionalmente usa ISO-8859-1; PHP moderno trabaja en UTF-8.
3. **Verificar `freetds.conf`** — La sección `[global]` debe tener `client charset = UTF-8` si la app usa UTF-8.

### Soluciones

| Causa | Solución |
|-------|----------|
| ASE en ISO-8859-1, PHP en UTF-8 | Activar `charset_conversion => true` |
| `freetds.conf` con charset incorrecto | Configurar `client charset = UTF-8` en freetds.conf |
| Datos ya corruptos en la base | Corregir datos con script de migración manual |
| Inserción sin conversión | Asegurar que `charset_conversion` está activo antes de insertar |

### Verificación de la conversión activa

```php
// La conversión UTF-8 ↔ ISO-8859-1 se aplica automáticamente
// cuando charset_conversion está habilitado en la configuración.
// ConnectionManager convierte:
//   - PHP → DB: utf8_decode() al enviar parámetros
//   - DB → PHP: utf8_encode() al recibir resultados
```

## Checklist General de Troubleshooting

Ante cualquier problema en producción:

1. ☐ Revisar logs de la aplicación (nivel PSR-3 configurado)
2. ☐ Verificar conectividad con `ConnectionManager::ping()`
3. ☐ Consultar estado del servidor ASE (`sp_who`, `sp_lock`)
4. ☐ Revisar consumo de memoria (`IdentityMap::count()`)
5. ☐ Verificar configuración de charset en `freetds.conf` y en la config del ORM
6. ☐ Confirmar que las excepciones se capturan correctamente según la jerarquía:
   - `ConnectionLostException` → reconectar y reintentar
   - `PersistenceException` con `isDeadlock()` → retry con backoff
   - `TransactionException` → verificar estado transaccional
   - `TypeConversionException` → revisar tipos y charset

---

← [Anterior](./logging-monitoreo.md) | [Índice](./README.md) | [Siguiente →](./retry-reconexion.md)
