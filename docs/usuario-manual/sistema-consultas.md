# Sistema de Consultas OQL

El ORM incluye un lenguaje de consultas orientado a objetos llamado **OQL** (Object Query Language). OQL permite escribir consultas usando nombres de entidades y propiedades PHP en lugar de tablas y columnas SQL. El EntityManager se encarga de traducir OQL a SQL nativo Sybase ASE.

## Sintaxis OQL

### SELECT y FROM

```php
// Seleccionar entidades completas
$users = $em->query('SELECT u FROM User u');

// Seleccionar propiedades específicas (devuelve arrays)
$names = $em->query('SELECT u.name, u.email FROM User u');

// Seleccionar con alias
$data = $em->query('SELECT u.name AS nombre FROM User u');

// Wildcard
$all = $em->query('SELECT * FROM Product p');
```

### WHERE

```php
$activos = $em->query(
    'SELECT u FROM User u WHERE u.active = :active',
    ['active' => true]
);

// Operadores: =, !=, <, >, <=, >=, LIKE
$busqueda = $em->query(
    'SELECT u FROM User u WHERE u.name LIKE :patron',
    ['patron' => '%García%']
);

// IS NULL / IS NOT NULL
$sinEmail = $em->query('SELECT u FROM User u WHERE u.email IS NULL');

// IN con lista de valores
$seleccionados = $em->query(
    'SELECT u FROM User u WHERE u.id IN (:ids)',
    ['ids' => [1, 2, 3]]
);

// AND, OR
$filtrado = $em->query(
    'SELECT u FROM User u WHERE u.active = :act AND u.role = :role',
    ['act' => true, 'role' => 'admin']
);
```

### JOIN

```php
// INNER JOIN
$resultado = $em->query(
    'SELECT o FROM Order o JOIN o.customer c WHERE c.name = :name',
    ['name' => 'Acme']
);

// LEFT JOIN / RIGHT JOIN
$todos = $em->query('SELECT u FROM User u LEFT JOIN u.orders o WHERE o.id IS NULL');
```

### ORDER BY

```php
$ordenados = $em->query(
    'SELECT u FROM User u ORDER BY u.name ASC, u.createdAt DESC'
);
```

### GROUP BY y HAVING

```php
$agrupado = $em->query(
    'SELECT u.role, COUNT(u.id) AS total FROM User u GROUP BY u.role'
);

// HAVING filtra sobre resultados agrupados
$frecuentes = $em->query(
    'SELECT u.role, COUNT(u.id) AS total FROM User u GROUP BY u.role HAVING COUNT(u.id) > :min',
    ['min' => 5]
);
```

### Funciones agregadas

Las funciones soportadas son: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`.

```php
$total = $em->queryScalar('SELECT COUNT(u.id) FROM User u');

$promedio = $em->queryScalar(
    'SELECT AVG(o.total) FROM Order o WHERE o.status = :status',
    ['status' => 'completed']
);

// COUNT con DISTINCT
$unicos = $em->queryScalar('SELECT COUNT(DISTINCT u.email) FROM User u');
```

## Métodos de Ejecución

### query()

Ejecuta una consulta OQL y devuelve todos los resultados como array.

```php
public function query(
    string $oql,
    array $params = [],
    int $hydrationMode = HydrationMode::HYDRATE_OBJECT,
    ?int $limit = null,
    ?int $offset = null
): array
```

```php
// Con paginación
$pagina = $em->query('SELECT u FROM User u', [], HydrationMode::HYDRATE_OBJECT, 10, 20);
```

### queryOne()

Devuelve un único resultado o `null` si no hay coincidencias.

```php
$user = $em->queryOne(
    'SELECT u FROM User u WHERE u.email = :email',
    ['email' => 'admin@ejemplo.com']
);
```

### queryScalar()

Devuelve un valor escalar (primera columna del primer resultado).

```php
$count = $em->queryScalar('SELECT COUNT(u.id) FROM User u WHERE u.active = :a', ['a' => true]);
```

### queryScalarAll()

Devuelve la primera columna de cada fila como un array plano de escalares.

```php
$ids = $em->queryScalarAll('SELECT u.id FROM User u WHERE u.active = :a', ['a' => true]);
// [1, 5, 12, 34, ...]

// Con paginación
$page = $em->queryScalarAll('SELECT u.id FROM User u', [], 10, 0);
```

### queryOneOrFail()

Como `queryOne()`, pero lanza `PersistenceException` si no encuentra resultado.

```php
// Lanza excepción si no existe
$user = $em->queryOneOrFail(
    'SELECT u FROM User u WHERE u.email = :email',
    ['email' => 'admin@ejemplo.com']
);
```

### queryIterator()

Devuelve un `Generator` que itera fila por fila sin cargar todo en memoria. Útil para grandes conjuntos de datos.

```php
$iterator = $em->queryIterator('SELECT u FROM User u');

foreach ($iterator as $user) {
    // Procesa un usuario a la vez
    procesarUsuario($user);
}
```

### queryCached()

Ejecuta la consulta con soporte de caché de segundo nivel. Si el resultado está en caché, lo devuelve sin consultar la base de datos.

```php
public function queryCached(
    string $oql, array $params = [], int $ttl = 3600, int $hydrationMode = HydrationMode::HYDRATE_OBJECT
): array
```

```php
$productos = $em->queryCached('SELECT p FROM Product p WHERE p.active = :a', ['a' => true]);

// Caché por 5 minutos
$recientes = $em->queryCached('SELECT o FROM Order o ORDER BY o.createdAt DESC', [], 300);
```

## Parametrización

### Named Parameters

OQL usa parámetros nombrados con prefijo `:`. Los valores se pasan como array asociativo:

```php
$resultado = $em->query(
    'SELECT u FROM User u WHERE u.name = :nombre AND u.age > :edad',
    ['nombre' => 'Carlos', 'edad' => 18]
);
```

Los parámetros se expanden a placeholders posicionales (`?`) antes de la ejecución, protegiendo contra inyección SQL.

### Expansión automática de arrays para IN

Cuando un parámetro es un array, el ORM lo expande automáticamente en múltiples placeholders para cláusulas `IN`:

```php
$usuarios = $em->query(
    'SELECT u FROM User u WHERE u.id IN (:ids)',
    ['ids' => [10, 20, 30]]
);
// Se traduce internamente a: ... WHERE u.id IN (?, ?, ?)
```

Si el array está vacío, se genera una condición imposible (`1 = 0`) que no retorna resultados:

```php
$vacio = $em->query('SELECT u FROM User u WHERE u.id IN (:ids)', ['ids' => []]);
// Resultado: array vacío (la condición nunca se cumple)
```

### Entidades como parámetros

Si se pasa una entidad como parámetro, el ORM resuelve automáticamente su clave primaria:

```php
$orders = $em->query(
    'SELECT o FROM Order o WHERE o.customer = :customer',
    ['customer' => $customerEntity]
);
```

## Funciones OQL Personalizadas

Se pueden registrar funciones SQL personalizadas accesibles desde OQL mediante `registerOqlFunction()`:

```php
public function registerOqlFunction(string $name, string $sqlTemplate): void
```

```php
// Registrar función personalizada
$em->registerOqlFunction('YEAR', 'DATEPART(yy, %s)');
$em->registerOqlFunction('UPPER', 'UPPER(%s)');

// Usar en consultas OQL
$resultado = $em->query(
    'SELECT u FROM User u WHERE YEAR(u.createdAt) = :year',
    ['year' => 2024]
);
```

El `$sqlTemplate` usa `%s` como placeholder para los argumentos de la función.

## Modos de Hidratación

El ORM soporta dos modos de hidratación definidos en `HydrationMode`:

| Constante | Valor | Descripción |
|-----------|-------|-------------|
| `HYDRATE_OBJECT` | 1 | Devuelve instancias de entidad (por defecto) |
| `HYDRATE_ARRAY` | 2 | Devuelve arrays asociativos sin hidratación |

```php
use SybaseORM\ORM\HydrationMode;

// Hidratación a objetos (por defecto)
$users = $em->query('SELECT u FROM User u', [], HydrationMode::HYDRATE_OBJECT);

// Hidratación a arrays (más rápido para lecturas sin lógica de dominio)
$rows = $em->query('SELECT u FROM User u', [], HydrationMode::HYDRATE_ARRAY);
```

**Auto-detección:** cuando la consulta contiene funciones agregadas, alias o selección de múltiples entidades, el ORM cambia automáticamente a `HYDRATE_ARRAY` aunque se solicite `HYDRATE_OBJECT`.

## Sentencias UPDATE y DELETE OQL

El método `executeUpdate()` ejecuta sentencias OQL de modificación y devuelve el número de filas afectadas:

```php
public function executeUpdate(string $oql, array $params = []): int
```

### UPDATE

```php
$affected = $em->executeUpdate(
    'UPDATE User u SET u.active = :active WHERE u.lastLogin < :date',
    ['active' => false, 'date' => '2023-01-01']
);
echo "Usuarios desactivados: $affected";
```

### DELETE

```php
$deleted = $em->executeUpdate(
    'DELETE FROM User u WHERE u.active = :active AND u.createdAt < :date',
    ['active' => false, 'date' => '2022-01-01']
);
echo "Registros eliminados: $deleted";
```

> **Nota:** `executeUpdate()` no acepta sentencias SELECT. Para consultas de lectura use `query()`, `queryOne()` o `queryScalar()`.

## QueryBuilder

Para consultas dinámicas con construcción programática, el ORM ofrece un QueryBuilder completo.

[QueryBuilder en detalle →](./sistema-consultas-querybuilder.md)

---

← [Anterior](./relaciones.md) | [Índice](./README.md) | [Siguiente →](./sistema-consultas-querybuilder.md)
