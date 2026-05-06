# Tutorial Completo: SybaseORM Bundle v2.1

## Tabla de Contenidos

1. [Requisitos previos](#1-requisitos-previos)
2. [Instalación](#2-instalación)
3. [Configuración de conexión](#3-configuración-de-conexión)
4. [Tu primera entidad](#4-tu-primera-entidad)
5. [Operaciones CRUD](#5-operaciones-crud)
6. [Repositorios](#6-repositorios)
7. [QueryBuilder](#7-querybuilder)
8. [OQL - Object Query Language](#8-oql---object-query-language)
9. [Relaciones entre entidades](#9-relaciones-entre-entidades)
10. [Herencia de entidades](#10-herencia-de-entidades)
11. [Hooks de ciclo de vida](#11-hooks-de-ciclo-de-vida)
12. [Event Subscribers](#12-event-subscribers)
13. [Tipos y conversión](#13-tipos-y-conversión)
14. [Embeddable Value Objects](#14-embeddable-value-objects)
15. [Transacciones y Savepoints](#15-transacciones-y-savepoints)
16. [Migraciones](#16-migraciones)
17. [Caché](#17-caché)
18. [Manejo de errores](#18-manejo-de-errores)
19. [Claves primarias compuestas](#19-claves-primarias-compuestas)
20. [Colecciones](#20-colecciones)
21. [Buenas prácticas](#21-buenas-prácticas)

---

## 1. Requisitos previos

- PHP 8.1 o superior
- Extensión `pdo_dblib` instalada (FreeTDS)
- Un proyecto Symfony 6.x, 7.x o 8.x
- Acceso a un servidor Sybase ASE

```bash
php -m | grep pdo_dblib
```

---

## 2. Instalación

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/shedeza/sybase-orm.git"
        }
    ]
}
```

```bash
composer require shedeza/sybase-orm
php bin/console sybase:install
```

Edita `.env`:

```dotenv
DATABASE_URL="sybase://sa:password@192.168.1.100:5000/mi_base?charset=UTF-8"
```

---

## 3. Configuración de conexión

### Modo URL (recomendado)

```yaml
# config/packages/sybase_orm.yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'
        charset_conversion: false
```

### Modo parámetros individuales

```yaml
sybase_orm:
    connection:
        host: '%env(SYBASE_HOST)%'
        port: '%env(int:SYBASE_PORT)%'
        database: '%env(SYBASE_DATABASE)%'
        username: '%env(SYBASE_USERNAME)%'
        password: '%env(SYBASE_PASSWORD)%'
        charset: UTF-8
        persistent: false
        charset_conversion: false
```

### Opciones completas

```yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'
        charset_conversion: false

    entity_directories:
        - '%kernel.project_dir%/src/Entity'

    proxy_directory: '%kernel.cache_dir%/sybase_orm/proxies'
    migrations_directory: '%kernel.project_dir%/sybase_ase/migrations'

    cache:
        enabled: false
        adapter: redis
        dsn: '%env(REDIS_URL)%'
        default_ttl: 3600
```

---

## 4. Tu primera entidad

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Type\Types;

#[Entity(table: 'productos')]
class Producto
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Column(type: Types::STRING, length: 100)]
    private string $nombre = '';

    #[Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $precio = '0.00';

    #[Column(type: Types::BOOLEAN)]
    private bool $activo = true;

    #[Column(type: Types::DATETIME, nullable: true)]
    private ?\DateTimeImmutable $creadoEn = null;

    // Getters y setters...
    public function getId(): ?int { return $this->id; }
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): void { $this->nombre = $nombre; }
    public function getPrecio(): string { return $this->precio; }
    public function setPrecio(string $precio): void { $this->precio = $precio; }
    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): void { $this->activo = $activo; }
}
```

Notas:
- Sin `table`, el nombre se deriva en snake_case: `Producto` → `producto`
- `Types::DECIMAL` retorna `string` en PHP para preservar precisión
- `#[Entity(schema: 'ventas')]` genera SQL con `[ventas].[productos]`

---

## 5. Operaciones CRUD

```php
use SybaseORM\ORM\EntityManagerInterface;

class ProductoController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function crear(): void
    {
        $repo = $this->em->getRepository(Producto::class);

        $producto = new Producto();
        $producto->setNombre('Widget');
        $producto->setPrecio('29.99');

        $repo->save($producto);
        // ID asignado automáticamente via @@identity
    }

    public function buscar(int $id): ?Producto
    {
        return $this->em->getRepository(Producto::class)->find($id);
    }

    public function buscarOFallar(int $id): Producto
    {
        // Lanza PersistenceException si no existe
        return $this->em->getRepository(Producto::class)->findOrFail($id);
    }

    public function actualizar(int $id): void
    {
        $repo = $this->em->getRepository(Producto::class);
        $producto = $repo->find($id);
        $producto->setPrecio('34.99');
        $repo->save($producto); // Dirty checking detecta el cambio
    }

    public function eliminar(int $id): void
    {
        $repo = $this->em->getRepository(Producto::class);
        $producto = $repo->find($id);
        $repo->delete($producto);
    }
}
```

---

## 6. Repositorios

```php
$repo = $this->em->getRepository(Producto::class);

// Consultas básicas
$producto = $repo->find(1);
$todos = $repo->findAll();
$activos = $repo->findBy(['activo' => true]);
$primero = $repo->findOneBy(['nombre' => 'Widget']);

// Con ordenamiento y paginación (SQL-level TOP/ROW_NUMBER)
$pagina = $repo->findBy(['activo' => true], ['precio' => 'DESC'], 10, 20);

// Conteo y existencia
$total = $repo->count(['activo' => true]);
$existe = $repo->exists(['nombre' => 'Widget']);

// Buscar o lanzar excepción
$producto = $repo->findOrFail(1);
$admin = $repo->findOneByOrFail(['nombre' => 'Admin']);

// Persistencia en lote
$repo->saveMany([$p1, $p2, $p3]);
$repo->deleteMany([$p1, $p2]);

// Merge (re-asociar entidad detached)
$managed = $repo->merge($detachedProducto);

// Refresh (recargar desde BD)
$repo->refresh($producto);

// Streaming para conjuntos grandes
$iterator = $repo->queryIterator('SELECT p FROM Producto p WHERE p.activo = :a', ['a' => true]);
foreach ($iterator as $producto) {
    // Hidratado bajo demanda, sin acumular en memoria
}
```

### Repositorio personalizado

```php
#[Entity(table: 'productos', repositoryClass: ProductoRepository::class)]
class Producto { /* ... */ }

class ProductoRepository extends EntityRepository
{
    public function findBaratos(string $maxPrecio): array
    {
        return $this->getEntityManager()->query(
            'SELECT p FROM Producto p WHERE p.precio < :max',
            ['max' => $maxPrecio]
        );
    }
}
```

---

## 7. QueryBuilder

```php
$qb = $this->em->createQueryBuilder(Producto::class);

$sql = $qb
    ->select('e.id', 'e.nombre', 'e.precio')
    ->where('e.activo = :activo')
    ->andWhere('e.precio > :min')
    ->setParameter('activo', true)
    ->setParameter('min', '10.00')
    ->orderBy('e.precio', 'DESC')
    ->limit(10)
    ->offset(20)
    ->getSQL();

// DISTINCT
$qb->distinct()->select('e.nombre')->from('productos', 'e')->getSQL();

// GROUP BY + HAVING
$qb->select('e.categoria', 'COUNT(*)')
    ->groupBy('e.categoria')
    ->having('COUNT(*) > ?', [5])
    ->getSQL();

// JOINs
$qb->join('ordenes', 'o', 'o.producto_id = e.id')
    ->leftJoin('categorias', 'c', 'c.id = e.categoria_id')
    ->rightJoin('proveedores', 'p', 'p.id = e.proveedor_id')
    ->getSQL();

// Reset para reutilizar
$qb->reset()->select('e.id')->from('usuarios', 'e')->getSQL();
```

---

## 8. OQL - Object Query Language

```php
// Consulta básica
$productos = $this->em->query(
    'SELECT p FROM Producto p WHERE p.activo = :activo ORDER BY p.nombre ASC',
    ['activo' => true]
);

// Resultado único (aplica TOP 1)
$producto = $this->em->queryOne(
    'SELECT p FROM Producto p WHERE p.nombre = :nombre',
    ['nombre' => 'Widget']
);

// Valor escalar
$maxPrecio = $this->em->queryScalar(
    'SELECT MAX(p.precio) FROM Producto p WHERE p.activo = :a',
    ['a' => true]
);

// UPDATE/DELETE
$affected = $this->em->executeUpdate(
    'UPDATE Producto p SET p.activo = :activo WHERE p.precio < :min',
    ['activo' => false, 'min' => '5.00']
);

// IS NULL / IS NOT NULL
$sinPrecio = $this->em->query('SELECT p FROM Producto p WHERE p.precio IS NULL');

// IN / NOT IN (array expandido automáticamente)
$seleccion = $this->em->query(
    'SELECT p FROM Producto p WHERE p.id IN (:ids)',
    ['ids' => [1, 2, 3]]
);

// Funciones de agregación
$stats = $this->em->query(
    'SELECT COUNT(DISTINCT p.categoria), AVG(p.precio), MAX(p.precio) FROM Producto p'
);

// HAVING
$categorias = $this->em->query(
    'SELECT p.categoria, COUNT(p.id) AS total FROM Producto p GROUP BY p.categoria HAVING COUNT(p.id) > 5'
);

// JOIN con entidad (WITH)
$resultados = $this->em->query(
    'SELECT p FROM Producto p JOIN Inventario i WITH i.productoId = p.id WHERE i.cantidad > :min',
    ['min' => 0]
);

// SELECT DISTINCT, *, aliases
$this->em->query('SELECT DISTINCT p.categoria FROM Producto p');
$this->em->query('SELECT * FROM Producto p');
$this->em->query('SELECT p.nombre AS nombreProducto FROM Producto p');

// Funciones personalizadas con argumentos
$this->em->registerOqlFunction('DATEDIFF_DAYS', 'DATEDIFF(day, ?, ?)');
$viejos = $this->em->query(
    'SELECT p FROM Producto p WHERE DATEDIFF_DAYS(p.creadoEn, GETDATE()) > :dias',
    ['dias' => 365]
);

// Streaming (Generator)
$iterator = $this->em->queryIterator(
    'SELECT p FROM Producto p WHERE p.activo = :a',
    ['a' => true]
);
```

### Modos de hidratación

```php
use SybaseORM\ORM\HydrationMode;

// HYDRATE_ARRAY: retorna arrays asociativos
$filas = $this->em->query('SELECT p.nombre, COUNT(*) AS total FROM Producto p GROUP BY p.nombre', [], HydrationMode::HYDRATE_ARRAY);

// Auto-detección: consultas con agregaciones/aliases usan HYDRATE_ARRAY automáticamente
```

---

## 9. Relaciones entre entidades

```php
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Attribute\OneToOne;
use SybaseORM\Attribute\ManyToMany;
use SybaseORM\Attribute\JoinColumn;

#[Entity(table: 'ordenes')]
class Orden
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ManyToOne(targetEntity: Cliente::class, inversedBy: 'ordenes', cascade: ['persist'])]
    #[JoinColumn(name: 'cliente_id', referencedColumnName: 'id')]
    private ?Cliente $cliente = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $total = '0.00';
}

#[Entity(table: 'clientes')]
class Cliente
{
    #[OneToMany(targetEntity: Orden::class, mappedBy: 'cliente', cascade: ['persist'], orphanRemoval: true)]
    private array $ordenes = [];

    public function removeOrden(Orden $orden): void
    {
        $this->ordenes = array_filter($this->ordenes, fn($o) => $o !== $orden);
    }
}
```

### Orphan Removal

Con `orphanRemoval: true`, al remover un hijo de la colección del padre, se elimina automáticamente de la BD:

```php
$cliente->removeOrden($orden);
$repo->save($cliente);
// La orden se elimina automáticamente (DELETE)
```

### Cascade

- `cascade: ['persist']` — al persistir el padre, los hijos se persisten automáticamente
- `cascade: ['remove']` — al eliminar el padre, los hijos se eliminan automáticamente

---

## 10. Herencia de entidades

### TPH (Table Per Hierarchy)

```php
#[Entity(table: 'vehiculos')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'tipo', type: 'string')]
#[DiscriminatorMap(map: ['auto' => Auto::class, 'camion' => Camion::class])]
class Vehiculo
{
    #[Id]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Column(type: Types::STRING)]
    private string $marca = '';
}

#[Entity]
class Auto extends Vehiculo
{
    #[Column(type: Types::INTEGER)]
    private int $puertas = 4;
}
```

Estrategias: `TPH`, `TPT` (Table Per Type), `TPC` (Table Per Concrete Class).

---

## 11. Hooks de ciclo de vida

```php
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PreUpdate;

#[Entity(table: 'articulos')]
#[HasLifecycleHooks]
class Articulo
{
    #[PrePersist(priority: 10)]
    public function validar(): void
    {
        if ($this->nombre === '') {
            throw new \InvalidArgumentException('Nombre requerido');
        }
    }

    #[PrePersist(priority: 0)]
    public function setFechaCreacion(): void
    {
        $this->creadoEn = new \DateTimeImmutable();
    }

    #[PreUpdate]
    public function setFechaActualizacion(): void
    {
        $this->actualizadoEn = new \DateTimeImmutable();
    }
}
```

Hooks disponibles: `PrePersist`, `PostPersist`, `PreUpdate`, `PostUpdate`, `PreRemove`, `PostRemove`.

El parámetro `priority` controla el orden de ejecución (mayor prioridad ejecuta primero, default: 0).

---

## 12. Event Subscribers

Para cross-cutting concerns sin modificar entidades:

```php
use SybaseORM\Hook\EventSubscriberInterface;

class AuditSubscriber implements EventSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return ['PostPersist', 'PostUpdate', 'PostRemove'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        // Registrar en tabla de auditoría, enviar notificación, etc.
    }
}

// Registrar
$hookDispatcher->addSubscriber(new AuditSubscriber());
```

---

## 13. Tipos y conversión

| PHP | Sybase ASE | Constante |
|-----|-----------|-----------|
| `bool` | BIT | `Types::BOOLEAN` |
| `DateTimeImmutable` | DATETIME | `Types::DATETIME` |
| `DateTimeImmutable` | DATE | `Types::DATE` |
| `DateTimeImmutable` | TIME | `Types::TIME` |
| `int` | INT | `Types::INTEGER` |
| `int` | TINYINT/SMALLINT/BIGINT | `Types::TINYINT` / `Types::SMALLINT` / `Types::BIGINT` |
| `float` | FLOAT/REAL | `Types::FLOAT` / `Types::REAL` |
| `string` | DECIMAL/NUMERIC | `Types::DECIMAL` / `Types::NUMERIC` |
| `string` | VARCHAR/TEXT | `Types::STRING` / `Types::TEXT` |
| `BackedEnum` | Valor escalar | FQCN del enum |

### Tipos personalizados (Value Objects)

```php
use SybaseORM\Type\CustomTypeInterface;

class MoneyType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        return $value->getAmountInCents();
    }

    public function toPhpValue(mixed $value): mixed
    {
        return Money::fromCents((int) $value);
    }
}

$typeCaster->registerType('money', MoneyType::class);
```

---

## 14. Embeddable Value Objects

```php
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;

#[Embeddable]
class Direccion
{
    #[Column(type: Types::STRING, length: 200)]
    public string $calle = '';

    #[Column(type: Types::STRING, length: 100)]
    public string $ciudad = '';
}

#[Entity(table: 'clientes')]
class Cliente
{
    #[Embedded(class: Direccion::class)]
    private ?Direccion $direccion = null;

    // Genera columnas: direccion_calle, direccion_ciudad

    #[Embedded(class: Direccion::class, columnPrefix: 'envio_')]
    private ?Direccion $direccionEnvio = null;

    // Genera columnas: envio_calle, envio_ciudad
}
```

Si todas las columnas del embeddable son NULL, la propiedad queda como `null`.

> En OQL, referencia por nombre de columna: `u.direccion_calle`, no `u.direccion.calle`.

---

## 15. Transacciones y Savepoints

### Transacción básica

```php
$repo->beginTransaction();
try {
    $repo->save($entidad1);
    $repo->save($entidad2);
    $repo->commit();
} catch (\Throwable $e) {
    $repo->rollback();
    throw $e;
}
```

### transactional() — atajo

```php
$this->em->transactional(function () {
    $this->em->persist($entidad1);
    $this->em->persist($entidad2);
    // flush automático al terminar
});
```

### Savepoints (rollback parcial)

```php
$conn = $this->em->getConnection();
$conn->beginTransaction();

$conn->executeStatement('INSERT INTO logs ...', [...]);

$sp = $conn->createSavepoint();
try {
    $conn->executeStatement('UPDATE cuentas ...', [...]);
} catch (\Throwable $e) {
    $conn->rollbackToSavepoint($sp);
    // El INSERT sigue intacto
}

$conn->commit();
```

### Niveles de aislamiento

```php
$conn->setTransactionIsolation('SERIALIZABLE');
```

---

## 16. Migraciones

```bash
php bin/console sybase:migrations:generate   # Genera migración
php bin/console sybase:migrations:migrate    # Ejecuta pendientes
```

Las migraciones generan:
- `CREATE TABLE` con `PRIMARY KEY` y `FOREIGN KEY` constraints
- `ALTER TABLE ADD/DROP` para columnas nuevas/eliminadas
- Tipos: INT, VARCHAR, BIT, DECIMAL, DATETIME, IDENTITY

### Rollback

```php
$migrationManager->rollback(); // Revierte la última migración
```

### Preview (dry-run)

```php
$sql = $migrationManager->preview($entityClasses);
// Retorna ['up' => [...], 'down' => [...]] sin escribir archivos
```

---

## 17. Caché

Dos niveles:
- **Primer nivel (Identity Map)**: siempre activo, una instancia por entidad por sesión
- **Segundo nivel (Redis)**: opcional, compartido entre sesiones

```php
$this->em->clear();                  // Limpia todo
$this->em->clear(Producto::class);   // Limpia solo Producto

// Query result caching (usa segundo nivel)
$productos = $this->em->queryCached(
    'SELECT p FROM Producto p WHERE p.activo = :a',
    ['a' => true],
    3600  // TTL en segundos
);

// También desde el repositorio
$repo->queryCached('SELECT p FROM Producto p', [], 1800);
```

---

## 18. Manejo de errores

```php
use SybaseORM\Exception\SybaseORMException;
use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\TransactionException;

try {
    $this->em->flush();
} catch (ConnectionLostException $e) {
    // Conexión perdida — reconectar
    $this->em->getConnection()->reconnect();
} catch (PersistenceException $e) {
    // Error SQL (constraint violation, etc.)
} catch (SybaseORMException $e) {
    // Cualquier error del ORM
}

// Detección de deadlock
if (ConnectionManager::isDeadlock($pdoException)) {
    // Reintentar operación
}
```

---

## 19. Claves primarias compuestas

```php
#[Entity(table: 'inscripciones')]
class Inscripcion
{
    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $estudianteId;

    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $cursoId;
}

// Buscar
$inscripcion = $this->em->find(Inscripcion::class, [
    'estudianteId' => 1,
    'cursoId' => 42,
]);
```

---

## 20. Colecciones

```php
use SybaseORM\Collection\Collection;
use SybaseORM\Collection\ArrayCollection;
use SybaseORM\ORM\PersistentCollection;

// ArrayCollection: para colecciones en código de aplicación (sin BD)
$items = new ArrayCollection([$item1, $item2]);
$items->add($item3);
$items->remove($item1);
$items->filter(fn($i) => $i->isActivo());

// PersistentCollection: asignada automáticamente por el Hydrator a relaciones to-many
// Se carga lazy al primer acceso (ejecuta UNA query, previene N+1)
// Ejemplo: $cliente->getOrdenes()->count() → carga todas las órdenes en 1 query

// Ambas implementan Collection — código polimórfico:
function procesar(Collection $items): void {
    foreach ($items as $item) { /* ... */ }
}
```

---

## 21. Buenas prácticas

1. **Usa `saveMany()`** en vez de `save()` en loops — un solo flush para todo el lote
2. **Usa `queryIterator()`** para conjuntos grandes — evita cargar todo en memoria
3. **Usa `Types::DECIMAL`** para dinero — preserva precisión como string
4. **Usa `orphanRemoval: true`** en OneToMany para limpieza automática
5. **Usa `transactional()`** para operaciones atómicas simples
6. **Usa `clear()`** en workers long-running para liberar memoria
7. **Usa `reconnect()`** después de `ConnectionLostException` en daemons
8. **Usa `#[Embeddable]`** para value objects (Direccion, Money, etc.)
9. **Usa event subscribers** para cross-cutting concerns (auditoría, logging)
10. **Usa hook `priority`** cuando el orden de ejecución importa

---

## Comandos disponibles

```bash
php bin/console sybase:install              # Configuración inicial
php bin/console sybase:migrations:generate  # Generar migración
php bin/console sybase:migrations:migrate   # Ejecutar migraciones
php bin/console sybase:proxy:generate       # Generar proxies lazy loading
php bin/console sybase:cache:clear          # Limpiar caché
```
