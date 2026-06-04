# Transacciones

El ORM proporciona soporte completo de transacciones para Sybase ASE, incluyendo control manual, ejecución atómica con callback, savepoints y configuración de niveles de aislamiento.

## Control manual de transacciones

Los métodos `beginTransaction()`, `commit()` y `rollback()` permiten gestionar transacciones de forma explícita:

```php
$em = $entityManager;

$em->beginTransaction();

try {
    $cuenta->setSaldo($cuenta->getSaldo() - 500);
    $em->persist($cuenta);

    $destino->setSaldo($destino->getSaldo() + 500);
    $em->persist($destino);

    $em->flush();
    $em->commit();
} catch (\Throwable $e) {
    $em->rollback();
    throw $e;
}
```

### Métodos disponibles

| Método | Descripción |
|--------|-------------|
| `beginTransaction()` | Inicia una transacción nativa de Sybase ASE |
| `commit()` | Confirma la transacción activa |
| `rollback()` | Revierte la transacción activa |
| `isInTransaction()` | Retorna `true` si hay una transacción activa |

> **Nota:** Llamar a `commit()` o `rollback()` sin una transacción activa lanza `TransactionException`.

## Ejecución atómica con transactional()

El método `transactional()` ejecuta un callable y realiza `flush()` automáticamente al finalizar. Si ocurre una excepción, el Unit of Work revierte la transacción interna:

```php
$resultado = $em->transactional(function () use ($em, $pedido, $items) {
    $pedido->setEstado('confirmado');
    $em->persist($pedido);

    foreach ($items as $item) {
        $item->setReservado(true);
        $em->persist($item);
    }

    return $pedido->getId();
});

// $resultado contiene el ID del pedido
```

### Comportamiento

1. Se ejecuta el callback
2. Se llama a `flush()` automáticamente (persiste cambios dentro de una transacción)
3. Si el callback o flush lanzan una excepción, el rollback ocurre automáticamente
4. El valor de retorno del callback se propaga como resultado de `transactional()`

### Manejo de errores con transactional()

```php
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\TransactionException;

try {
    $em->transactional(function () use ($em, $entidad) {
        $entidad->setValor('nuevo');
        $em->persist($entidad);
    });
} catch (PersistenceException $e) {
    // Error SQL (constraint violation, etc.)
    $logger->error('Error de persistencia: ' . $e->getMessage());
} catch (TransactionException $e) {
    // Error de transacción
    $logger->error('Error de transacción: ' . $e->getMessage());
}
```

## Savepoints

Los savepoints permiten hacer rollback parcial dentro de una transacción activa sin revertir toda la operación. Sybase ASE los implementa con `SAVE TRANSACTION`.

### Métodos de savepoint

| Método | Descripción |
|--------|-------------|
| `createSavepoint()` | Crea un savepoint y retorna su nombre auto-generado |
| `rollbackToSavepoint(string $name)` | Revierte al savepoint indicado |
| `releaseSavepoint(string $name)` | Libera el savepoint (limpia estado interno) |

> Los savepoints requieren una transacción activa. Llamar a `createSavepoint()` sin transacción lanza `TransactionException`.

### Ejemplo con savepoints

```php
/** @var \SybaseORM\Connection\ConnectionManager $conn */
$conn = $em->getConnection();

$conn->beginTransaction();

try {
    // Operación principal
    $conn->executeStatement(
        'UPDATE cuentas SET saldo = saldo - ? WHERE id = ?',
        [1000, $cuentaId]
    );

    // Crear savepoint antes de operación secundaria
    $sp = $conn->createSavepoint();

    try {
        // Operación secundaria que puede fallar
        $conn->executeStatement(
            'INSERT INTO notificaciones (cuenta_id, mensaje) VALUES (?, ?)',
            [$cuentaId, 'Transferencia realizada']
        );
    } catch (\Throwable $e) {
        // Revertir solo la operación secundaria
        $conn->rollbackToSavepoint($sp);
    }

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    throw $e;
}
```

### Savepoints anidados

Los savepoints se apilan internamente. Al hacer rollback a un savepoint, todos los savepoints creados posteriormente se eliminan:

```php
$conn->beginTransaction();

$sp1 = $conn->createSavepoint(); // sp_1
$sp2 = $conn->createSavepoint(); // sp_2
$sp3 = $conn->createSavepoint(); // sp_3

// Revertir a sp1 elimina sp2 y sp3
$conn->rollbackToSavepoint($sp1);

$conn->commit();
```

## Niveles de aislamiento

El método `setTransactionIsolation()` configura el nivel de aislamiento de la transacción en Sybase ASE.

### Niveles soportados

| Nivel | Descripción |
|-------|-------------|
| `READ UNCOMMITTED` | Permite lecturas sucias. Mayor concurrencia, menor consistencia |
| `READ COMMITTED` | Lee solo datos confirmados. Nivel por defecto en Sybase ASE |
| `REPEATABLE READ` | Garantiza lecturas repetibles dentro de la transacción |
| `SERIALIZABLE` | Máximo aislamiento. Las transacciones se ejecutan como si fueran secuenciales |

### Uso de setTransactionIsolation()

```php
/** @var \SybaseORM\Connection\ConnectionManager $conn */
$conn = $em->getConnection();

// Configurar antes de iniciar la transacción
$conn->setTransactionIsolation('SERIALIZABLE');

$conn->beginTransaction();
try {
    // Operaciones con aislamiento serializable
    $stmt = $conn->executeQuery(
        'SELECT saldo FROM cuentas WHERE id = ?',
        [$cuentaId]
    );
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $conn->executeStatement(
        'UPDATE cuentas SET saldo = ? WHERE id = ?',
        [$row['saldo'] + 100, $cuentaId]
    );

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    throw $e;
}
```

> Pasar un nivel inválido a `setTransactionIsolation()` lanza `\InvalidArgumentException`.

## Ejemplo completo: transferencia bancaria

```php
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Connection\ConnectionManager;

function transferir(
    EntityManagerInterface $em,
    int $origenId,
    int $destinoId,
    float $monto
): void {
    $conn = $em->getConnection();
    $conn->setTransactionIsolation('REPEATABLE READ');

    $conn->beginTransaction();

    try {
        $conn->executeStatement(
            'UPDATE cuentas SET saldo = saldo - ? WHERE id = ?',
            [$monto, $origenId]
        );

        $sp = $conn->createSavepoint();

        try {
            $conn->executeStatement(
                'INSERT INTO movimientos (origen, destino, monto, fecha) VALUES (?, ?, ?, ?)',
                [$origenId, $destinoId, $monto, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            $conn->rollbackToSavepoint($sp);
            // Continuar sin el registro de movimiento
        }

        $conn->executeStatement(
            'UPDATE cuentas SET saldo = saldo + ? WHERE id = ?',
            [$monto, $destinoId]
        );

        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
```

## Conexiones read-only

Las conexiones configuradas con `read_only => true` no permiten iniciar transacciones ni ejecutar sentencias de escritura. Intentar llamar a `beginTransaction()` en una conexión de solo lectura lanza `PersistenceException`.

## Excepciones relacionadas

| Excepción | Cuándo se lanza |
|-----------|-----------------|
| `TransactionException` | `commit()`/`rollback()` sin transacción activa, `createSavepoint()` sin transacción |
| `PersistenceException` | Error SQL, escritura en conexión read-only |
| `ConnectionLostException` | La conexión se pierde durante la transacción |

---

← [Anterior](./sistema-migraciones.md) | [Índice](./README.md) | [Siguiente →](./repositorio-entidades.md)
