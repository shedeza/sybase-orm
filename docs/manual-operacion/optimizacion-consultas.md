# Optimización de Consultas

Las consultas son el principal punto de impacto en el rendimiento de cualquier aplicación con ORM. Este documento cubre las técnicas de optimización disponibles en `shedeza/sybase-orm`: caché de resultados, prevención de N+1, eager loading y caché LRU de sentencias preparadas.

## Uso de queryCached()

El método `EntityManager::queryCached()` almacena resultados de consultas OQL en el segundo nivel de caché (Redis) para evitar ejecutar la misma consulta repetidamente contra la base de datos.

### Firma del método

```php
public function queryCached(
    string $oql,
    array $params = [],
    int $ttl = 3600,
    int $hydrationMode = HydrationMode::HYDRATE_OBJECT
): array
```

### Parámetros

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `$oql` | `string` | Consulta OQL a ejecutar |
| `$params` | `array` | Parámetros nombrados de la consulta |
| `$ttl` | `int` | Tiempo de vida en caché (segundos). Por defecto: 3600 |
| `$hydrationMode` | `int` | Modo de hidratación (`HYDRATE_OBJECT` o `HYDRATE_ARRAY`) |

### Funcionamiento interno

1. Se genera una clave de caché determinista a partir del OQL, parámetros y modo de hidratación
2. Se consulta el segundo nivel de caché (`CacheManager::getQueryResult()`)
3. Si hay hit, se retorna el resultado sin tocar la base de datos
4. Si hay miss, se ejecuta `query()` normalmente y se almacena el resultado con el TTL indicado

### Ejemplo práctico

```php
// Consulta frecuente: usuarios activos por departamento
$usuarios = $em->queryCached(
    'SELECT u FROM App\Entity\User u WHERE u.departmentId = :dept AND u.active = :active',
    ['dept' => 5, 'active' => true],
    ttl: 300  // 5 minutos
);

// Consultas de catálogos que cambian raramente
$categorias = $em->queryCached(
    'SELECT c FROM App\Entity\Category c ORDER BY c.name',
    [],
    ttl: 3600  // 1 hora
);
```

### Cuándo usar queryCached()

| Caso de uso | TTL recomendado |
|-------------|-----------------|
| Catálogos y datos de referencia | 1800–3600 s |
| Listados con filtros frecuentes | 60–300 s |
| Dashboards y reportes | 300–600 s |
| Datos que cambian en cada request | No usar caché |

### Cuándo NO usar queryCached()

- Consultas con parámetros altamente variables (cada combinación genera una entrada distinta en caché)
- Datos que requieren consistencia estricta en tiempo real
- Consultas de escritura (`executeUpdate()`)

## Identificación del Problema N+1

El problema N+1 ocurre cuando se ejecuta una consulta para obtener N entidades y luego una consulta adicional por cada entidad para cargar una relación. Esto resulta en N+1 consultas totales.

### Ejemplo del problema

```php
// 1 consulta: obtener todos los pedidos
$pedidos = $em->query('SELECT o FROM App\Entity\Order o WHERE o.status = :status', ['status' => 'pending']);

// N consultas: acceder al cliente de cada pedido dispara lazy loading
foreach ($pedidos as $pedido) {
    // Cada acceso genera: SELECT * FROM customers WHERE id = ?
    echo $pedido->getCustomer()->getName();
}
```

Si hay 100 pedidos, se ejecutan **101 consultas** (1 + 100).

### Cómo detectar N+1

1. **Logging en desarrollo**: Con el logger en nivel `debug`, cada consulta SQL se registra. Buscar patrones repetitivos en los logs:
   ```
   [debug] OQL→SQL {"sql": "SELECT * FROM customers WHERE id = ?", "param_count": 1}
   [debug] OQL→SQL {"sql": "SELECT * FROM customers WHERE id = ?", "param_count": 1}
   ...
   ```

2. **Conteo de consultas**: Si el número de queries por request es desproporcionado respecto a las entidades mostradas, probablemente hay un N+1.

## Eager Loading con QueryBuilder::with()

El método `with()` del `QueryBuilder` resuelve el problema N+1 precargando relaciones en la misma consulta mediante JOINs.

### Firma del método

```php
public function with(string ...$relations): static
```

### Uso básico

```php
// Sin eager loading: N+1
$qb = $em->createQueryBuilder(Order::class);
$qb->select('e.*')
   ->where('e.status = :status', ['status' => 'pending']);

// Con eager loading: 1 sola consulta con JOIN
$qb = $em->createQueryBuilder(Order::class);
$qb->select('e.*')
   ->where('e.status = :status', ['status' => 'pending'])
   ->with('customer');  // Precarga la relación customer
```

### Múltiples relaciones

```php
$qb = $em->createQueryBuilder(Order::class);
$qb->select('e.*')
   ->where('e.createdAt > :date', ['date' => '2024-01-01'])
   ->with('customer', 'items', 'shippingAddress');
```

### Cuándo usar eager loading

| Escenario | Recomendación |
|-----------|---------------|
| Iterar entidades accediendo a una relación | Usar `with()` |
| Mostrar datos de entidad sin relaciones | No necesario |
| Relación accedida condicionalmente | Evaluar caso por caso |
| Relaciones profundas (relación de relación) | JOIN explícito |

### Comparación de rendimiento

```php
// ❌ Sin optimización: 1 + N consultas
$pedidos = $em->query('SELECT o FROM App\Entity\Order o');
foreach ($pedidos as $pedido) {
    $nombre = $pedido->getCustomer()->getName(); // Lazy load
}

// ✅ Con eager loading: 1 consulta con LEFT JOIN
$qb = $em->createQueryBuilder(Order::class);
$qb->select('e.*')->with('customer');
// Genera: SELECT e.* FROM orders e LEFT JOIN customer customer ON e.id = customer.e_id
```

## Caché LRU de Sentencias Preparadas

El `ConnectionManager` implementa un caché LRU (Least Recently Used) para sentencias preparadas (`PDOStatement`). Esto evita preparar la misma consulta SQL múltiples veces dentro de una conexión.

### Configuración

El caché es automático e interno al `ConnectionManager`. No requiere configuración adicional.

| Propiedad | Valor |
|-----------|-------|
| Tamaño máximo | 256 sentencias (`STMT_CACHE_MAX_SIZE`) |
| Política de evicción | LRU (se elimina la menos recientemente usada) |
| Alcance | Por instancia de `ConnectionManager` |
| Persistencia | Mientras viva la conexión |

### Funcionamiento

```
┌─────────────────────────────────────────┐
│ ConnectionManager::executeQuery($sql)   │
├─────────────────────────────────────────┤
│ 1. ¿Existe $sql en stmtCache?          │
│    → SÍ: Reordenar (mover al final)    │
│           Retornar PDOStatement cacheado│
│    → NO: Preparar nueva sentencia       │
│           ¿Cache lleno (≥256)?          │
│              → Eliminar la más antigua  │
│           Almacenar en stmtCache        │
│           Retornar PDOStatement nueva   │
└─────────────────────────────────────────┘
```

### Impacto en rendimiento

- **Con conexiones persistentes** (`persistent => true`): El caché sobrevive entre requests del mismo worker PHP-FPM, acumulando sentencias frecuentes.
- **Sin conexiones persistentes**: El caché se reconstruye en cada request, pero sigue beneficiando consultas repetidas dentro del mismo request.

### Ejemplo del beneficio

```php
// En un loop, la misma SQL se prepara UNA sola vez
foreach ($departmentIds as $deptId) {
    // La sentencia "SELECT ... WHERE department_id = ?" se prepara solo la primera vez
    $stmt = $connectionManager->executeQuery(
        'SELECT * FROM users WHERE department_id = ?',
        [$deptId]
    );
    $results[] = $stmt->fetchAll();
}
```

## Patrones de Optimización Combinados

### Patrón 1: Listados con paginación y relaciones

```php
$qb = $em->createQueryBuilder(Order::class);
$qb->select('e.*')
   ->where('e.status = :status', ['status' => 'active'])
   ->with('customer')           // Evitar N+1 al mostrar nombre del cliente
   ->orderBy('e.createdAt', 'DESC')
   ->limit(20)
   ->offset(0);
```

### Patrón 2: Consultas frecuentes con caché

```php
// Menú de navegación: categorías que rara vez cambian
$categorias = $em->queryCached(
    'SELECT c FROM App\Entity\Category c WHERE c.visible = :visible ORDER BY c.sortOrder',
    ['visible' => true],
    ttl: 1800
);
```

### Patrón 3: Reportes con hidratación ligera

```php
// Para reportes, usar HYDRATE_ARRAY para evitar overhead de objetos
$reporte = $em->queryCached(
    'SELECT u.name, COUNT(o.id) as total FROM App\Entity\User u JOIN orders o GROUP BY u.name',
    [],
    ttl: 600,
    hydrationMode: HydrationMode::HYDRATE_ARRAY
);
```

### Patrón 4: Iteración eficiente con grandes volúmenes

```php
// queryIterator() no carga todo en memoria — procesa fila por fila
$iterator = $em->queryIterator(
    'SELECT u FROM App\Entity\User u WHERE u.lastLogin < :date',
    ['date' => '2023-01-01']
);

foreach ($iterator as $usuario) {
    // Procesar usuario sin acumular miles de objetos en memoria
    $this->processInactiveUser($usuario);
}
```

## Checklist de Optimización

| # | Verificación | Acción |
|---|-------------|--------|
| 1 | ¿Se accede a relaciones en un loop? | Usar `with()` para eager loading |
| 2 | ¿La misma consulta se ejecuta frecuentemente con los mismos parámetros? | Usar `queryCached()` con TTL apropiado |
| 3 | ¿Se necesitan solo datos tabulares sin lógica de entidad? | Usar `HYDRATE_ARRAY` |
| 4 | ¿Se procesan miles de registros? | Usar `queryIterator()` |
| 5 | ¿Las conexiones son persistentes en producción? | El caché LRU de sentencias se acumula entre requests |
| 6 | ¿Los logs muestran consultas SQL repetitivas? | Revisar N+1 o falta de caché |

---

← [Anterior](./cache-produccion.md) | [Índice](./README.md) | [Siguiente →](./logging-monitoreo.md)
