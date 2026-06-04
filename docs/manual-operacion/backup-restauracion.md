# Backup y Restauración

Consideraciones operativas para realizar backups y restauraciones en sistemas que utilizan SybaseORM, teniendo en cuenta el caché, el Identity Map y las transacciones en curso.

## Consideraciones del Identity Map

El Identity Map mantiene entidades en memoria durante la vida del EntityManager. Al restaurar un backup:

### Problema: Datos desactualizados en memoria

Si se restaura un backup mientras la aplicación está activa, las entidades en el Identity Map no reflejan el estado restaurado.

**Solución:**

```php
// Después de restaurar un backup, limpiar el Identity Map
$em->clear();

// O reiniciar el EntityManager completamente
// (en aplicaciones web, cada request crea un EM nuevo, no es problema)
```

### Problema: IDs generados conflictivos

Si la aplicación generó registros después del punto de backup y luego se restaura, los IDs IDENTITY pueden entrar en conflicto.

**Solución:**

```php
// Después de restaurar, resetear el Identity en Sybase ASE
$connection->executeStatement(
    "DBCC SETIDENTITY('usuarios', SELECT MAX(id) FROM usuarios)"
);
```

## Consideraciones de Caché

### Caché de segundo nivel (Redis)

Al restaurar un backup, los datos en Redis pueden ser inconsistentes con el estado restaurado.

**Procedimiento recomendado:**

```php
// 1. Antes de restaurar: detener la aplicación
// 2. Restaurar el backup de la base de datos
// 3. Invalidar todo el caché de segundo nivel

// Opción A: Flush completo de Redis (si es dedicado al ORM)
// redis-cli FLUSHDB

// Opción B: Invalidar por prefijo desde la aplicación
$connection = $em->getConnection();
// El caché se invalida automáticamente al crear un nuevo EntityManager
```

### Caché de sentencias preparadas (LRU)

El caché LRU del `ConnectionManager` se invalida automáticamente al reconectar:

```php
// La reconexión limpia el caché de statements
$em->getConnection()->reconnect();
```

Si se cambia el esquema durante la restauración (tablas diferentes), las sentencias preparadas cacheadas serán inválidas. La reconexión resuelve esto.

## Transacciones Durante Backups

### Problema: Transacciones abiertas

Si se realiza un backup mientras hay transacciones activas, el backup puede capturar un estado inconsistente.

**Recomendaciones:**

1. **Backup en caliente con aislamiento** — Sybase ASE soporta `dump database` que captura un snapshot consistente sin bloquear transacciones.

2. **Verificar transacciones activas antes del backup:**

```php
// Verificar si hay transacción activa en esta conexión
if ($em->getConnection()->isInTransaction()) {
    echo "ADVERTENCIA: Transacción activa, finalizar antes del backup\n";
}
```

3. **No iniciar transacciones largas durante ventanas de backup:**

```php
// Patrón: transacciones cortas para no interferir con backups
$em->transactional(function () use ($em, $repository) {
    // Operaciones breves
    $repository->save($entidad);
    // No incluir procesamiento pesado aquí
});
```

### Secuencia recomendada de backup

```
1. Notificar a la aplicación (modo mantenimiento o reducir carga)
2. Esperar a que transacciones activas finalicen (timeout: 30s)
3. Ejecutar dump database
4. Verificar integridad del backup
5. Reanudar operación normal
```

## Procedimiento de Restauración

### Paso 1: Preparar el entorno

```bash
# Detener la aplicación (o poner en mantenimiento)
# Detener workers/crons que usen el ORM
```

### Paso 2: Restaurar la base de datos

```sql
-- En Sybase ASE
LOAD DATABASE mi_db FROM '/backups/mi_db_20240115.dmp'
ONLINE DATABASE mi_db
```

### Paso 3: Limpiar cachés del ORM

```php
// Script post-restauración
$em = crearEntityManager();

// Limpiar Identity Map
$em->clear();

// Forzar reconexión (limpia caché LRU)
$em->getConnection()->reconnect();

// Verificar conectividad
if ($em->getConnection()->ping()) {
    echo "Conexión restaurada correctamente\n";
}
```

### Paso 4: Verificar consistencia

```php
// Verificar que las migraciones están sincronizadas
$status = $migrationManager->getStatus();

if ($status['pending'] > 0) {
    echo "ADVERTENCIA: {$status['pending']} migraciones pendientes después de restaurar\n";
    echo "El backup puede ser de una versión anterior del esquema\n";
}

// Verificar acceso a entidades
try {
    $count = $em->getRepository(Usuario::class)->count();
    echo "Usuarios accesibles: $count\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
```

### Paso 5: Reanudar operación

```bash
# Reiniciar la aplicación
# Reactivar workers/crons
# Verificar logs por errores
```

## Backups Incrementales y el ORM

Si se usan backups incrementales (transaction logs), considerar:

- Los logs de transacción capturan operaciones del ORM tal como se ejecutan
- El `flush()` del UnitOfWork genera las sentencias SQL reales que se registran en el log
- Los savepoints internos se registran pero se resuelven dentro de la transacción

## Tabla de Verificación Post-Restauración

| Verificación | Comando | Esperado |
|--------------|---------|----------|
| Conexión activa | `$connection->ping()` | `true` |
| Migraciones sincronizadas | `$migrationManager->getStatus()['pending']` | `0` |
| Entidades accesibles | `$repository->count()` | `> 0` |
| Identity Map limpio | `$em->clear()` | Sin error |
| Caché LRU limpio | `$connection->reconnect()` | Sin error |

## Recomendaciones

- **Frecuencia de backup:** Diario completo + logs de transacción cada hora
- **Retención:** Mínimo 7 días de backups completos
- **Pruebas de restauración:** Mensual en entorno de staging
- **Documentar el proceso:** Incluir pasos específicos del ORM en el runbook
- **Automatizar verificación:** Script post-restauración con las verificaciones listadas

---

← [Anterior](./mantenimiento-soft-delete.md) | [Índice](./README.md)
