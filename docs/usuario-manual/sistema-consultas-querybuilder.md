# QueryBuilder — API de Consultas Fluida

El `QueryBuilder` permite construir consultas SQL de forma programática y segura mediante una interfaz fluida (method chaining). Genera SQL parametrizado delegando la paginación al dialecto de Sybase.

## Obtener un QueryBuilder

```php
// Desde el EntityManager
$qb = $entityManager->createQueryBuilder(User::class);

// Desde un repositorio personalizado
$qb = $this->createQueryBuilder();
```

El método `createQueryBuilder()` configura automáticamente la cláusula `FROM` con la tabla de la entidad y el alias `e`.

## Referencia de Métodos

### select()

Define las columnas o expresiones a seleccionar.

```php
$qb->select('e.id', 'e.name', 'e.email');
```

Si no se llama a `select()`, se selecciona `*` por defecto.

### distinct()

Activa `DISTINCT` en la cláusula SELECT.

```php
$qb->select('e.department')->distinct();
```

### from()

Define la tabla o entidad origen. Generalmente ya está configurado por `createQueryBuilder()`.

```php
$qb->from('users', 'u');
```

### where(), andWhere(), orWhere()

Condiciones WHERE con parametrización automática.

```php
// where() reemplaza condiciones previas
$qb->where('e.status = :status', ['status' => 'active']);

// andWhere() agrega con AND
$qb->andWhere('e.age > :min_age', ['min_age' => 18]);

// orWhere() agrega con OR
$qb->orWhere('e.role = :role', ['role' => 'admin']);
```

### join(), leftJoin(), rightJoin()

Agregan JOINs a la consulta. Reciben tabla, alias y condición.

```php
$qb->join('orders', 'o', 'o.user_id = e.id');
$qb->leftJoin('profiles', 'p', 'p.user_id = e.id');
$qb->rightJoin('departments', 'd', 'd.id = e.department_id');
```

### orderBy() y addOrderBy()

Definen el ordenamiento de resultados.

```php
// orderBy() agrega una cláusula de orden
$qb->orderBy('e.created_at', 'DESC');

// addOrderBy() agrega orden adicional sin reemplazar
$qb->addOrderBy('e.name', 'ASC');
```

### groupBy() y addGroupBy()

Definen la agrupación de resultados.

```php
$qb->groupBy('e.department', 'e.status');

// Agregar columnas adicionales sin reemplazar
$qb->addGroupBy('e.role');
```

### having()

Agrega una condición HAVING para filtrar grupos.

```php
$qb->groupBy('e.department')
   ->having('COUNT(e.id) > :min_count', ['min_count' => 5]);
```

### limit() y offset()

Paginación de resultados. La generación SQL se delega al dialecto Sybase (TOP / ROW_NUMBER).

```php
$qb->limit(20)->offset(40); // Página 3, 20 por página
```

### with()

Especifica relaciones para Eager Loading mediante JOINs automáticos.

```php
$qb->with('orders', 'profile');
```

### setParameter() y setParameters()

Configuran parámetros nombrados de forma individual o masiva.

```php
$qb->where('e.id = :id')
   ->setParameter('id', 42);

$qb->where('e.status = :status AND e.role = :role')
   ->setParameters(['status' => 'active', 'role' => 'editor']);
```

### reset()

Reinicia todo el estado del QueryBuilder para reutilizarlo.

```php
$qb->reset();
$qb->select('e.name')->from('products', 'e');
```

### getSQL() y getParameters()

Obtienen el SQL generado y los parámetros para ejecución manual.

```php
$sql    = $qb->getSQL();
$params = $qb->getParameters();
```

## Métodos de Ejecución

El QueryBuilder incluye métodos que ejecutan la consulta directamente cuando se crea a través de `EntityManager::createQueryBuilder()` o `EntityRepository::createQueryBuilder()`.

### getResult()

Ejecuta la consulta y devuelve todos los resultados hidratados como entidades.

```php
$usuarios = $qb->where('e.active = :a')
    ->setParameter('a', true)
    ->getResult();
```

### getSingleResult()

Ejecuta la consulta con limit 1 y devuelve el primer resultado o `null`.

```php
$usuario = $qb->where('e.email = :email')
    ->setParameter('email', 'admin@ejemplo.com')
    ->getSingleResult();
```

### getOneOrNullResult()

Como `getSingleResult()`, pero lanza `OverflowException` si hay más de un resultado.

```php
$usuario = $qb->where('e.token = :token')
    ->setParameter('token', $token)
    ->getOneOrNullResult();
```

### getArrayResult()

Ejecuta la consulta y devuelve los resultados como arrays asociativos (sin hidratar).

```php
$rows = $qb->select('e.id', 'e.name', 'e.email')
    ->getArrayResult();
// [['id' => 1, 'name' => 'Juan', 'email' => '...'], ...]
```

### getScalarResult()

Ejecuta la consulta y devuelve la primera columna de cada fila como array plano.

```php
$ids = $qb->select('e.id')
    ->where('e.active = :a')
    ->setParameter('a', true)
    ->getScalarResult();
// [1, 5, 12, 34, ...]
```

### getSingleScalarResult()

Ejecuta la consulta y devuelve un único valor escalar (primera columna, primera fila).

```php
$total = $qb->select('COUNT(*)')
    ->getSingleScalarResult();
```

### getCount()

Devuelve el total de filas que coinciden con las condiciones actuales, sin modificar el estado del QueryBuilder (select, limit, order se ignoran).

```php
$qb->where('e.active = :a')->setParameter('a', true);
$count = $qb->getCount(); // SELECT COUNT(*) FROM ... WHERE ...
$results = $qb->limit(10)->getResult(); // Las condiciones siguen intactas
```

### execute()

Para consultas UPDATE o DELETE. Devuelve el número de filas afectadas.

```php
$affected = $qb->... // construir UPDATE/DELETE SQL manualmente
    ->execute();
```

### getQuery()

Devuelve `$this` para compatibilidad con el patrón Doctrine `->getQuery()->getResult()`.

```php
$usuarios = $qb->where('e.active = :a')
    ->setParameter('a', true)
    ->getQuery()
    ->getResult();
```

### setMaxResults() y setFirstResult()

Aliases de `limit()` y `offset()` para compatibilidad Doctrine.

```php
$qb->setMaxResults(10)->setFirstResult(20); // equivale a ->limit(10)->offset(20)
```

## Ejemplos de Consultas Complejas

### Búsqueda con filtros y paginación

```php
$qb = $entityManager->createQueryBuilder(User::class);

$qb->select('e.id', 'e.name', 'e.email')
   ->where('e.active = :active', ['active' => 1])
   ->andWhere('e.created_at > :since', ['since' => '2024-01-01'])
   ->orderBy('e.created_at', 'DESC')
   ->limit(25)
   ->offset(0);

$sql    = $qb->getSQL();
$params = $qb->getParameters();
```

### Consulta con JOINs y agrupación

```php
$qb = $entityManager->createQueryBuilder(Order::class);

$qb->select('e.status', 'COUNT(e.id) as total', 'SUM(e.amount) as revenue')
   ->join('customers', 'c', 'c.id = e.customer_id')
   ->where('e.created_at >= :start', ['start' => '2024-01-01'])
   ->groupBy('e.status')
   ->having('COUNT(e.id) > :min', ['min' => 10])
   ->orderBy('revenue', 'DESC');
```

### Consulta con LEFT JOIN y eager loading

```php
$qb = $entityManager->createQueryBuilder(User::class);

$qb->select('e.id', 'e.name', 'p.bio')
   ->leftJoin('profiles', 'p', 'p.user_id = e.id')
   ->with('orders')
   ->where('e.role = :role', ['role' => 'premium'])
   ->orderBy('e.name', 'ASC')
   ->limit(50);
```

### Consulta con valores DISTINCT y múltiples condiciones

```php
$qb = $entityManager->createQueryBuilder(Product::class);

$qb->select('e.category', 'e.brand')
   ->distinct()
   ->where('e.price > :min_price', ['min_price' => 100])
   ->andWhere('e.stock > :min_stock', ['min_stock' => 0])
   ->orWhere('e.featured = :featured', ['featured' => 1])
   ->orderBy('e.category', 'ASC')
   ->addOrderBy('e.brand', 'ASC');
```

## Subqueries

El QueryBuilder soporta subqueries para condiciones `WHERE IN`, `WHERE NOT IN` y `WHERE EXISTS`. Se construyen como instancias independientes del QueryBuilder y se pasan como argumento.

### WHERE IN subquery

```php
// Obtener usuarios que tienen órdenes con total > 1000
$subQb = $em->createQueryBuilder(Order::class)
    ->select('e.user_id')
    ->where('e.total > :min')
    ->setParameter('min', 1000);

$users = $em->createQueryBuilder(User::class)
    ->whereIn('e.id', $subQb)
    ->getResult();
```

### WHERE NOT IN subquery

```php
// Usuarios que NO tienen órdenes con total > 1000
$users = $em->createQueryBuilder(User::class)
    ->whereNotIn('e.id', $subQb)
    ->getResult();
```

### WHERE EXISTS / NOT EXISTS

```php
// WHERE EXISTS
$qb->whereExists($subQb);

// WHERE NOT EXISTS
$qb->whereNotExists($subQb);
```

Las subqueries se renderizan como SQL parametrizado y sus parámetros se fusionan automáticamente con los de la consulta principal.

## Condiciones Especializadas

A partir de v3.7.0, el QueryBuilder incluye métodos dedicados para condiciones SQL comunes, eliminando la necesidad de escribir expresiones raw en la mayoría de los casos.

```php
// IS NULL / IS NOT NULL
$qb->whereNull('e.deletedAt');
$qb->whereNotNull('e.email');

// BETWEEN
$qb->whereBetween('e.age', 'min', 'max')
   ->setParameter('min', 18)
   ->setParameter('max', 65);
$qb->whereNotBetween('e.salary', 'lo', 'hi');

// LIKE / NOT LIKE
$qb->whereLike('e.name', 'pattern')
   ->setParameter('pattern', '%García%');
$qb->whereNotLike('e.email', 'exclude')
   ->setParameter('exclude', '%test%');

// Raw WHERE (escape hatch)
$qb->whereRaw('DATEDIFF(day, e.created_at, GETDATE()) > 30');

// UNION
$qb1 = $em->createQueryBuilder(User::class)->where('e.active = :a', ['a' => 1]);
$qb2 = $em->createQueryBuilder(User::class)->where('e.role = :r', ['r' => 'admin']);
$qb1->union($qb2);              // UNION (removes duplicates)
$qb1->union($qb2, all: true);   // UNION ALL (keeps duplicates)
```

---

### Reutilización del QueryBuilder

```php
$qb = $entityManager->createQueryBuilder(Article::class);

// Primera consulta
$qb->select('e.id', 'e.title')
   ->where('e.published = :pub', ['pub' => 1])
   ->limit(10);

$sql1 = $qb->getSQL();

// Reutilizar para otra consulta
$qb->reset();
$qb->from('articles', 'e')
   ->select('COUNT(e.id) as total')
   ->where('e.author_id = :author', ['author' => 5]);

$sql2 = $qb->getSQL();
```

---

← [Anterior](./sistema-consultas.md) | [Índice](./README.md) | [Siguiente →](./ciclo-vida-hooks.md)
