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

Obtienen el SQL generado y los parámetros para ejecución.

```php
$sql    = $qb->getSQL();
$params = $qb->getParameters();
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
