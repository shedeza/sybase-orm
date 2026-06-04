# Sistema de Migraciones

El sistema de migraciones permite evolucionar el esquema de base de datos de forma controlada y versionada. `MigrationManager` compara los metadatos de las entidades con el esquema actual, genera archivos de migración con SQL específico para Sybase ASE, ejecuta migraciones dentro de transacciones y soporta rollback.

## Configuración

`MigrationManager` requiere una conexión, un lector de metadatos, el dialecto SQL y un directorio donde almacenar los archivos de migración:

```php
use SybaseORM\Migration\MigrationManager;

$migrationManager = new MigrationManager(
    connection: $connectionManager,
    metadataReader: $metadataReader,
    dialect: $dialect,
    migrationsDirectory: __DIR__ . '/migrations',
);
```

## Métodos Principales

### generateMigration()

Genera un archivo de migración comparando los metadatos de las entidades con el esquema existente en la base de datos. Detecta tablas nuevas (genera `CREATE TABLE`) y columnas añadidas o eliminadas (genera `ALTER TABLE`).

```php
$filePath = $migrationManager->generateMigration([
    App\Entity\User::class,
    App\Entity\Order::class,
]);

if ($filePath === null) {
    echo "No se detectaron cambios en el esquema.\n";
} else {
    echo "Migración generada: {$filePath}\n";
}
```

Retorna la ruta del archivo generado o `null` si no hay cambios.

### migrate()

Ejecuta todas las migraciones pendientes en orden cronológico. Cada migración se ejecuta dentro de una transacción individual: si falla, se hace rollback automático y se lanza `MigrationException`.

```php
use SybaseORM\Exception\MigrationException;

try {
    $executed = $migrationManager->migrate();
    echo count($executed) . " migraciones ejecutadas.\n";
    foreach ($executed as $version) {
        echo "  - {$version}\n";
    }
} catch (MigrationException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

Retorna un array con las versiones ejecutadas.

### rollback()

Revierte la última migración ejecutada aplicando las sentencias `down`. La operación se ejecuta dentro de una transacción.

```php
$rolledBack = $migrationManager->rollback();

if ($rolledBack === null) {
    echo "No hay migraciones para revertir.\n";
} else {
    echo "Migración revertida: {$rolledBack}\n";
}
```

Retorna la versión revertida o `null` si no hay migraciones ejecutadas.

### getStatus()

Retorna el estado actual del sistema de migraciones: total de archivos, ejecutadas y pendientes.

```php
$status = $migrationManager->getStatus();

echo "Total: {$status['total']}\n";
echo "Ejecutadas: {$status['executed']}\n";
echo "Pendientes: {$status['pending']}\n";
```

Retorna un array con claves `total`, `executed` y `pending`.

### preview()

Genera el SQL que produciría una migración sin escribir archivo ni ejecutar nada (dry-run). Útil para revisar los cambios antes de generar la migración.

```php
$sql = $migrationManager->preview([
    App\Entity\Product::class,
]);

echo "Sentencias UP:\n";
foreach ($sql['up'] as $statement) {
    echo "  {$statement}\n";
}

echo "Sentencias DOWN:\n";
foreach ($sql['down'] as $statement) {
    echo "  {$statement}\n";
}
```

Retorna un array con claves `up` y `down`, cada una conteniendo un array de sentencias SQL.

## Formato de Archivo de Migración

Los archivos de migración son archivos PHP que retornan un array con dos claves: `up` (sentencias para aplicar) y `down` (sentencias para revertir).

**Nomenclatura:** `migration_YYYYMMDDHHmmss.php` (timestamp de generación).

```php
<?php

declare(strict_types=1);

/**
 * Migration generated at 20240315143022.
 */
return [
    'up' => [
        'CREATE TABLE [users] ([id] INT IDENTITY NOT NULL, [name] VARCHAR(255) NOT NULL, [email] VARCHAR(255) NOT NULL)',
    ],
    'down' => [
        'DROP TABLE [users]',
    ],
];
```

Cada archivo debe retornar exactamente esta estructura. Si el formato es inválido, `MigrationManager` lanza `MigrationException`.

## Tabla de Control `__migrations`

El sistema mantiene una tabla `__migrations` en la base de datos para rastrear qué versiones han sido ejecutadas:

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `version` | VARCHAR(255) | Timestamp de la migración (ej: `20240315143022`) |
| `executed_at` | DATETIME | Fecha y hora de ejecución |

La tabla se crea automáticamente la primera vez que se invoca `migrate()`, `rollback()` o `getStatus()`. Las migraciones pendientes son aquellas cuyos archivos existen en el directorio pero no están registradas en esta tabla.

## Flujo Completo de Ejemplo

### 1. Previsualizar cambios

```php
$sql = $migrationManager->preview([App\Entity\User::class]);

if (empty($sql['up'])) {
    echo "El esquema está actualizado.\n";
    return;
}

foreach ($sql['up'] as $stmt) {
    echo "  {$stmt}\n";
}
```

### 2. Generar la migración

```php
$file = $migrationManager->generateMigration([App\Entity\User::class]);
echo "Archivo generado: {$file}\n";
// Resultado: migrations/migration_20240315143022.php
```

### 3. Ejecutar migraciones pendientes

```php
$executed = $migrationManager->migrate();
echo count($executed) . " migración(es) aplicada(s).\n";
```

### 4. Verificar estado

```php
$status = $migrationManager->getStatus();
// ['total' => 1, 'executed' => 1, 'pending' => 0]
```

### 5. Rollback si es necesario

```php
$version = $migrationManager->rollback();
echo "Revertida: {$version}\n";
```

## Manejo de Errores

Todas las operaciones de migración lanzan `MigrationException` en caso de fallo:

- **Migración fallida**: rollback automático de la transacción en curso
- **Archivo no encontrado**: al intentar rollback de una versión cuyo archivo fue eliminado
- **Formato inválido**: archivo que no retorna un array con claves `up` y `down`

```php
use SybaseORM\Exception\MigrationException;

try {
    $migrationManager->migrate();
} catch (MigrationException $e) {
    // La transacción de la migración fallida ya fue revertida
    error_log("Migración fallida: " . $e->getMessage());
}
```

## Detección de Cambios

`generateMigration()` y `preview()` detectan automáticamente:

- **Tablas nuevas**: entidades sin tabla correspondiente → `CREATE TABLE`
- **Columnas nuevas**: propiedades mapeadas sin columna en la tabla → `ALTER TABLE ADD`
- **Columnas eliminadas**: columnas en la tabla sin propiedad mapeada → `ALTER TABLE DROP`
- **Claves foráneas**: relaciones `ManyToOne` y `OneToOne` con `JoinColumn` → `CONSTRAINT FOREIGN KEY`

---

← [Anterior](./sistema-cache.md) | [Índice](./README.md) | [Siguiente →](./transacciones.md)
