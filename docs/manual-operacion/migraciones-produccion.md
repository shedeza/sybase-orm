# Migraciones en Producción

Guía operativa para ejecutar migraciones de base de datos en entornos de producción de forma segura, incluyendo estrategias de rollback, verificación y recuperación ante fallos.

## Checklist Pre-Migración

Antes de ejecutar migraciones en producción:

1. **Backup de la base de datos** — Crear backup completo antes de cualquier cambio de esquema
2. **Revisar el SQL generado** — Usar `preview()` para inspeccionar los cambios
3. **Probar en staging** — Ejecutar la migración en un entorno idéntico
4. **Ventana de mantenimiento** — Programar en horarios de bajo tráfico
5. **Plan de rollback** — Verificar que las sentencias `down` son correctas
6. **Notificar al equipo** — Comunicar el cambio y tiempo estimado

## Preview Antes de Ejecutar

Siempre revisar el SQL que se ejecutará antes de aplicar en producción:

```php
use SybaseORM\Migration\MigrationManager;

$preview = $migrationManager->preview($entityClasses);

echo "=== SQL UP (aplicar) ===\n";
foreach ($preview['up'] as $sql) {
    echo $sql . "\n";
}

echo "\n=== SQL DOWN (rollback) ===\n";
foreach ($preview['down'] as $sql) {
    echo $sql . "\n";
}
```

### Verificar estado actual

```php
$status = $migrationManager->getStatus();

echo "Total migraciones: {$status['total']}\n";
echo "Ejecutadas: {$status['executed']}\n";
echo "Pendientes: {$status['pending']}\n";
```

## Ejecución de Migraciones

### Ejecución transaccional

Cada migración se ejecuta dentro de una transacción. Si falla un statement, se hace rollback automático de esa migración individual:

```php
try {
    $executed = $migrationManager->migrate();

    foreach ($executed as $version) {
        echo "✓ Migración aplicada: $version\n";
    }
} catch (\SybaseORM\Exception\MigrationException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    // La migración fallida ya fue revertida automáticamente
    // Las migraciones anteriores exitosas permanecen aplicadas
}
```

### Ejecución paso a paso

Para mayor control, se pueden ejecutar las migraciones una a una verificando entre cada paso:

```php
$status = $migrationManager->getStatus();

if ($status['pending'] > 0) {
    // Ejecutar solo la siguiente pendiente
    $executed = $migrationManager->migrate();
    // Verificar estado de la aplicación antes de continuar
}
```

## Estrategias de Rollback

### Rollback de la última migración

```php
$rolledBack = $migrationManager->rollback();

if ($rolledBack !== null) {
    echo "Rollback exitoso de versión: $rolledBack\n";
} else {
    echo "No hay migraciones para revertir\n";
}
```

### Rollback múltiple

Para revertir varias migraciones, ejecutar rollback repetidamente:

```php
$targetVersions = 3; // Revertir las últimas 3

for ($i = 0; $i < $targetVersions; $i++) {
    $version = $migrationManager->rollback();
    if ($version === null) {
        echo "No hay más migraciones para revertir\n";
        break;
    }
    echo "Revertida: $version\n";
}
```

### Rollback con verificación

```php
try {
    $version = $migrationManager->rollback();
    if ($version !== null) {
        echo "Rollback de $version exitoso\n";

        // Verificar integridad post-rollback
        $connection = $em->getConnection();
        $stmt = $connection->executeQuery('SELECT COUNT(*) as c FROM usuarios');
        $count = $stmt->fetch(\PDO::FETCH_ASSOC)['c'];
        echo "Registros en usuarios: $count\n";
    }
} catch (\SybaseORM\Exception\MigrationException $e) {
    echo "ERROR en rollback: " . $e->getMessage() . "\n";
    // Intervención manual necesaria
}
```

## Verificación Post-Migración

### Checklist de verificación

Después de ejecutar una migración:

```php
// 1. Verificar que la tabla de control está actualizada
$executed = $migrationManager->getExecutedVersions();
echo "Versiones aplicadas: " . implode(', ', $executed) . "\n";

// 2. Verificar que las tablas/columnas nuevas existen
$connection = $em->getConnection();
$stmt = $connection->executeQuery(
    "SELECT name FROM syscolumns c JOIN sysobjects o ON c.id = o.id WHERE o.name = ?",
    ['mi_tabla']
);

// 3. Verificar que la aplicación puede leer/escribir
try {
    $repository = $em->getRepository(MiEntidad::class);
    $count = $repository->count();
    echo "Entidades accesibles: $count\n";
} catch (\Throwable $e) {
    echo "ERROR: La aplicación no puede acceder a los datos\n";
}
```

### Validar rendimiento

```php
// Verificar que los índices están funcionando
$start = microtime(true);
$connection->executeQuery('SELECT TOP 1 * FROM usuarios WHERE email = ?', ['test@test.com']);
$elapsed = microtime(true) - $start;

if ($elapsed > 0.1) {
    echo "ADVERTENCIA: Query lenta ({$elapsed}s), revisar índices\n";
}
```

## Manejo de Migraciones Fallidas

### Escenario: SQL inválido

Si una migración falla por SQL inválido, el rollback automático revierte los cambios. Pasos:

1. Revisar el error en el log
2. Corregir la entidad o metadata
3. Regenerar la migración con `generateMigration()`
4. Verificar con `preview()` que el SQL es correcto
5. Volver a ejecutar `migrate()`

### Escenario: Timeout durante migración

Si la conexión se pierde durante una migración:

1. Verificar el estado real de la base de datos (¿se aplicó parcialmente?)
2. Revisar la tabla `__migrations` para ver qué versiones se registraron
3. Si la migración quedó a medias, limpiar manualmente y re-ejecutar
4. Si se completó pero no se registró, registrar manualmente la versión

### Escenario: Conflictos con datos existentes

```php
try {
    $migrationManager->migrate();
} catch (\SybaseORM\Exception\MigrationException $e) {
    if (str_contains($e->getMessage(), 'duplicate key')) {
        // Datos existentes violan una nueva constraint
        // Limpiar datos antes de re-intentar
        $connection->executeStatement(
            'DELETE FROM mi_tabla WHERE condicion_duplicada = 1'
        );
        $migrationManager->migrate();
    }
}
```

## Tabla de Control `__migrations`

El ORM mantiene una tabla `__migrations` con las versiones ejecutadas:

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `version` | VARCHAR(255) | Timestamp de la migración (ej: `20240115120000`) |
| `executed_at` | DATETIME | Fecha/hora de ejecución |

### Consultar directamente

```sql
SELECT * FROM __migrations ORDER BY executed_at DESC
```

## Recomendaciones

- **Una migración por cambio lógico** — No mezclar cambios no relacionados
- **Migraciones idempotentes** — Verificar estado antes de alterar
- **No modificar migraciones ejecutadas** — Crear una nueva para corregir
- **Conservar archivos de migración** — Son el historial del esquema
- **Monitorear tiempos** — Migraciones lentas indican tablas grandes que necesitan estrategia especial

---

← [Anterior](./retry-reconexion.md) | [Índice](./README.md) | [Siguiente →](./mantenimiento-soft-delete.md)
