# Mantenimiento de Soft Delete

Guía operativa para gestionar registros eliminados lógicamente (soft delete), incluyendo limpieza programada, impacto en rendimiento y consultas de auditoría.

## Contexto

Las entidades con `#[SoftDelete]` no se eliminan físicamente de la base de datos. En su lugar, se marca una columna (por defecto `deleted_at`) con la fecha de eliminación. Con el tiempo, estos registros acumulados pueden impactar el rendimiento.

## Impacto en Rendimiento

### Crecimiento de tablas

Los registros con soft delete permanecen en la tabla indefinidamente. Esto afecta:

- **Escaneos de tabla** — Más filas para recorrer aunque estén filtradas
- **Índices** — Incluyen registros eliminados, aumentando su tamaño
- **Backups** — Mayor volumen de datos a respaldar
- **Estadísticas** — Pueden sesgar el optimizador de consultas

### Indicadores de que necesitas limpieza

- Tablas con más del 30% de registros eliminados
- Consultas que se degradan progresivamente
- Backups que exceden la ventana asignada
- Identity Map cargando registros innecesarios en procesos batch

## Limpieza Programada

### Script de purga

Eliminar físicamente registros eliminados hace más de N días:

```php
use SybaseORM\Connection\ConnectionManagerInterface;

function purgarRegistrosEliminados(
    ConnectionManagerInterface $connection,
    string $tabla,
    string $columnaDelete = 'deleted_at',
    int $diasRetencion = 90
): int {
    $fecha = (new \DateTime("-{$diasRetencion} days"))->format('Y-m-d H:i:s');

    return $connection->executeStatement(
        "DELETE FROM {$tabla} WHERE {$columnaDelete} IS NOT NULL AND {$columnaDelete} < ?",
        [$fecha]
    );
}

// Uso
$connection = $em->getConnection();
$eliminados = purgarRegistrosEliminados($connection, 'usuarios', 'deleted_at', 90);
echo "Purgados: $eliminados registros\n";
```

### Purga por lotes

Para tablas grandes, eliminar en lotes para evitar bloqueos prolongados:

```php
function purgarEnLotes(
    ConnectionManagerInterface $connection,
    string $tabla,
    string $columnaDelete,
    int $diasRetencion,
    int $batchSize = 1000
): int {
    $fecha = (new \DateTime("-{$diasRetencion} days"))->format('Y-m-d H:i:s');
    $totalEliminados = 0;

    do {
        $connection->beginTransaction();

        try {
            $eliminados = $connection->executeStatement(
                "DELETE TOP {$batchSize} FROM {$tabla} "
                . "WHERE {$columnaDelete} IS NOT NULL AND {$columnaDelete} < ?",
                [$fecha]
            );
            $connection->commit();
            $totalEliminados += $eliminados;

            // Pausa entre lotes para reducir presión en el servidor
            if ($eliminados > 0) {
                usleep(100_000); // 100ms
            }
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    } while ($eliminados >= $batchSize);

    return $totalEliminados;
}
```

### Programación con cron

Ejemplo de script para ejecutar como tarea programada:

```php
#!/usr/bin/env php
<?php
// scripts/purgar-soft-deletes.php

require __DIR__ . '/../vendor/autoload.php';

$em = crearEntityManager(); // Tu factory
$connection = $em->getConnection();

$tablas = [
    'usuarios' => 180,       // 6 meses de retención
    'ordenes' => 365,        // 1 año
    'notificaciones' => 30,  // 1 mes
    'logs_actividad' => 60,  // 2 meses
];

foreach ($tablas as $tabla => $dias) {
    $eliminados = purgarEnLotes($connection, $tabla, 'deleted_at', $dias);
    echo "[" . date('Y-m-d H:i:s') . "] {$tabla}: {$eliminados} registros purgados\n";
}
```

Entrada en crontab:
```
# Purgar soft deletes todos los domingos a las 3 AM
0 3 * * 0 /usr/bin/php /var/www/app/scripts/purgar-soft-deletes.php >> /var/log/purga.log 2>&1
```

## Consultas de Auditoría

### Registros eliminados recientemente

```php
// Usando el repositorio con _withTrashed
$eliminadosRecientes = $repository->findBy([
    '_withTrashed' => true,
    'deleted_at' => null, // Esto NO funciona para buscar eliminados
]);

// Usar raw SQL para consultas de auditoría
$stmt = $connection->executeQuery(
    'SELECT * FROM usuarios WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
    []
);
```

### Conteo de registros eliminados por tabla

```php
function contarEliminados(ConnectionManagerInterface $connection, string $tabla): array
{
    $stmt = $connection->executeQuery(
        "SELECT COUNT(*) as total, "
        . "SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as eliminados "
        . "FROM {$tabla}"
    );
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    return [
        'total' => (int) $row['total'],
        'eliminados' => (int) $row['eliminados'],
        'porcentaje' => $row['total'] > 0
            ? round(($row['eliminados'] / $row['total']) * 100, 1)
            : 0,
    ];
}

$stats = contarEliminados($connection, 'usuarios');
echo "Eliminados: {$stats['eliminados']}/{$stats['total']} ({$stats['porcentaje']}%)\n";
```

### Historial de eliminaciones por fecha

```php
$stmt = $connection->executeQuery(
    "SELECT CONVERT(VARCHAR(10), deleted_at, 120) as fecha, COUNT(*) as cantidad "
    . "FROM usuarios WHERE deleted_at IS NOT NULL "
    . "GROUP BY CONVERT(VARCHAR(10), deleted_at, 120) "
    . "ORDER BY fecha DESC"
);

while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    echo "{$row['fecha']}: {$row['cantidad']} eliminados\n";
}
$stmt->closeCursor();
```

## Estrategia de Índices

Para tablas con soft delete, considerar índices filtrados que excluyan registros eliminados:

```sql
-- Índice que solo incluye registros activos
CREATE INDEX idx_usuarios_activos ON usuarios (email)
WHERE deleted_at IS NULL

-- Índice para consultas de auditoría
CREATE INDEX idx_usuarios_eliminados ON usuarios (deleted_at)
WHERE deleted_at IS NOT NULL
```

## Recomendaciones

| Aspecto | Recomendación |
|---------|---------------|
| Retención mínima | 30 días para permitir restauración |
| Retención recomendada | 90-180 días según regulaciones |
| Frecuencia de purga | Semanal en horario de bajo tráfico |
| Tamaño de lote | 1000-5000 registros por iteración |
| Monitoreo | Alertar si eliminados superan 30% de la tabla |
| Archivado | Considerar mover a tabla de archivo antes de purgar |

---

← [Anterior](./migraciones-produccion.md) | [Índice](./README.md) | [Siguiente →](./backup-restauracion.md)
