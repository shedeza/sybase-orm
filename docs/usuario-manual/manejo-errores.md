# Manejo de Errores

SybaseORM define una jerarquía de excepciones que permite capturar errores de forma granular o global según la necesidad.

## Jerarquía de Excepciones

```
SybaseORMException (base)
├── PersistenceException
├── ConnectionLostException
├── TransactionException
├── TypeConversionException
├── OqlParseException
└── MigrationException
```

Todas las excepciones del ORM extienden `SybaseORMException`, lo que permite capturar cualquier error con un solo `catch`:

```php
try {
    $em->flush();
} catch (SybaseORMException $e) {
    // Maneja cualquier error del ORM
}
```

## SybaseORMException

Clase base para todas las excepciones del ORM. Extiende `\RuntimeException`.

```php
use SybaseORM\Exception\SybaseORMException;
```

**Método estático `wrap()`**: Envuelve cualquier `\Throwable` en una `SybaseORMException`, preservando la excepción original como `previous`. Si ya es una `SybaseORMException`, la retorna sin cambios.

```php
$wrapped = SybaseORMException::wrap($exception, 'Mensaje opcional');
```

## PersistenceException

Lanzada cuando una operación de persistencia falla: entidad no encontrada, error al guardar o eliminar.

```php
use SybaseORM\Exception\PersistenceException;

try {
    $usuario = $repository->findOrFail(999);
} catch (PersistenceException $e) {
    // Entidad no encontrada
}
```

**Cuándo se lanza:**
- `findOrFail()` cuando no encuentra la entidad por ID
- `findOneByOrFail()` cuando no hay resultados para los criterios
- `refresh()` cuando la entidad no tiene ID o no existe en la base de datos

**Método estático `forEntity()`**:

```php
PersistenceException::forEntity(
    entityClass: Usuario::class,
    operation: 'findOrFail (id: 999)'
);
```

## ConnectionLostException

Lanzada cuando se pierde la conexión a Sybase ASE durante una operación.

```php
use SybaseORM\Exception\ConnectionLostException;

try {
    $results = $em->query('SELECT e FROM Usuario e');
} catch (ConnectionLostException $e) {
    echo $e->getSqlState(); // Código SQLSTATE original
    // Intentar reconexión
    $em->getConnection()->reconnect();
}
```

**Cuándo se lanza:**
- Timeout de conexión durante ejecución de consultas
- Servidor Sybase ASE no disponible
- Red interrumpida durante operación

**Métodos:**
- `getSqlState()`: Retorna el código SQLSTATE del `PDOException` original, o `null`
- `fromPdoException()`: Crea la excepción a partir de un `PDOException`

## TransactionException

Lanzada cuando hay un error en el manejo de transacciones.

```php
use SybaseORM\Exception\TransactionException;

try {
    $em->commit();
} catch (TransactionException $e) {
    // No hay transacción activa
}
```

**Cuándo se lanza:**
- `commit()` o `rollback()` sin transacción activa
- `beginTransaction()` cuando ya hay una transacción activa

**Métodos estáticos:**
- `noActiveTransaction(string $operation)`: Crea excepción indicando la operación sin transacción
- `alreadyActive()`: Crea excepción cuando se intenta iniciar una transacción ya activa

## TypeConversionException

Lanzada cuando falla la conversión entre un tipo PHP y un tipo de base de datos.

```php
use SybaseORM\Exception\TypeConversionException;

try {
    $repository->save($entidad);
} catch (TypeConversionException $e) {
    echo $e->getSourceType();       // Tipo origen (ej: 'string')
    echo $e->getTargetType();       // Tipo destino (ej: 'int')
    var_dump($e->getProblematicValue()); // Valor que causó el error
}
```

**Cuándo se lanza:**
- Valor incompatible con el tipo de columna declarado
- BackedEnum con valor no válido
- Tipo personalizado que falla en la conversión

**Métodos:**
- `getSourceType()`: Tipo de dato del valor original
- `getTargetType()`: Tipo al que se intentó convertir
- `getProblematicValue()`: El valor que causó la excepción

## OqlParseException

Lanzada cuando hay un error de sintaxis en una consulta OQL.

```php
use SybaseORM\Exception\OqlParseException;

try {
    $em->query('SELEC e FROM Usuario e'); // typo en SELECT
} catch (OqlParseException $e) {
    echo $e->getMessage();
}
```

**Cuándo se lanza:**
- Sintaxis OQL inválida
- Token inesperado en la consulta
- Entidad o propiedad no reconocida

**Método estático `unexpectedToken()`**:

```php
OqlParseException::unexpectedToken(
    expected: 'SELECT',
    actual: 'SELEC',
    oql: 'SELEC e FROM Usuario e'
);
```

## MigrationException

Lanzada cuando una migración falla durante ejecución o rollback.

```php
use SybaseORM\Exception\MigrationException;

try {
    $migrationManager->migrate();
} catch (MigrationException $e) {
    echo $e->getMessage(); // "Migration '20240115...' failed: ..."
}
```

**Cuándo se lanza:**
- Error SQL durante ejecución de una migración
- Archivo de migración no encontrado
- Formato de archivo de migración inválido
- Rollback fallido

**Método estático `forVersion()`**:

```php
MigrationException::forVersion(
    version: '20240115120000',
    reason: 'Table already exists'
);
```

## Detección de Deadlocks

El `ConnectionManager` provee un método estático para detectar deadlocks:

```php
use SybaseORM\Connection\ConnectionManager;

try {
    $em->flush();
} catch (\PDOException $e) {
    if (ConnectionManager::isDeadlock($e)) {
        // Es un deadlock, reintentar
    }
}
```

## Patrones de Retry

### Retry para conexiones perdidas

```php
function ejecutarConReintento(EntityManagerInterface $em, callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;

    while (true) {
        try {
            return $operation($em);
        } catch (ConnectionLostException $e) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            $em->getConnection()->reconnect();
            usleep(100_000 * $attempt); // Backoff exponencial
        }
    }
}

// Uso
$usuarios = ejecutarConReintento($em, function ($em) {
    return $em->query('SELECT e FROM Usuario e WHERE e.status = :s', ['s' => 'activo']);
});
```

### Retry para deadlocks

```php
function ejecutarConRetryDeadlock(EntityManagerInterface $em, callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;

    while (true) {
        try {
            return $em->transactional($operation);
        } catch (\PDOException $e) {
            if (!ConnectionManager::isDeadlock($e)) {
                throw $e;
            }
            $attempt++;
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            usleep(50_000 * $attempt);
        }
    }
}

// Uso
ejecutarConRetryDeadlock($em, function () use ($em, $repository) {
    $usuario = $repository->findOrFail(1);
    $usuario->saldo += 100;
    $em->flush();
});
```

---

← [Anterior](./repositorio-entidades.md) | [Índice](./README.md) | [Siguiente →](./caracteristicas-avanzadas.md)
