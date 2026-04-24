# Tutorial Completo: SybaseORM Bundle

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
12. [Tipos personalizados](#12-tipos-personalizados)
13. [Transacciones](#13-transacciones)
14. [Migraciones](#14-migraciones)
15. [Caché](#15-caché)
16. [Manejo de errores](#16-manejo-de-errores)
17. [Buenas prácticas](#17-buenas-prácticas)
18. [Claves primarias compuestas](#18-claves-primarias-compuestas)

---

## 1. Requisitos previos

Antes de empezar necesitas:

- PHP 8.1 o superior
- Extensión `pdo_dblib` instalada (FreeTDS)
- Un proyecto Symfony 6.x o 7.x
- Acceso a un servidor Sybase ASE

Verifica que la extensión está disponible:

```bash
php -m | grep pdo_dblib
```

Si no aparece, instálala según tu sistema operativo:

```bash
# Debian/Ubuntu
sudo apt-get install php-sybase

# CentOS/RHEL
sudo yum install php-mssql
```

---

## 2. Instalación

### Paso 1: Agregar el paquete

Si el bundle está en un repositorio local:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/ruta/al/sybase-ase-orm-bundle"
        }
    ]
}
```

```bash
composer require sybase-orm/sybase-ase-orm-bundle:*
```

### Paso 2: Configuración automática

Ejecuta el comando de instalación que configura todo:

```bash
php bin/console sybase:install
```

Esto crea automáticamente:
- `config/packages/sybase_orm.yaml` con la configuración por defecto
- La variable `DATABASE_URL` en tu archivo `.env`
- El directorio `sybase_ase/migrations/`
- Registra el bundle en `config/bundles.php`

### Paso 3: Configurar la conexión

Edita `.env` con tus datos reales:

```dotenv
DATABASE_URL="sybase://mi_usuario:mi_password@192.168.1.100:5000/mi_base?charset=UTF-8"
```

---

## 3. Configuración de conexión

### Modo URL (recomendado)

El formato de la URL es:

```
sybase://usuario:password@host:puerto/base_de_datos?charset=UTF-8&persistent=true
```

```yaml
# config/packages/sybase_orm.yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'
```

Si tu password tiene caracteres especiales, usa URL encoding:

| Caracter | Encoding |
|----------|----------|
| `@`      | `%40`    |
| `:`      | `%3A`    |
| `/`      | `%2F`    |
| `#`      | `%23`    |

Ejemplo: password `p@ss:word` se escribe como `p%40ss%3Aword`.

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
```

### Opciones adicionales

```yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'

    # Directorios donde buscar entidades
    entity_directories:
        - '%kernel.project_dir%/src/Entity'

    # Directorio para clases proxy (lazy loading)
    proxy_directory: '%kernel.cache_dir%/sybase_orm/proxies'

    # Directorio para archivos de migración
    migrations_directory: '%kernel.project_dir%/sybase_ase/migrations'

    # Conversión transparente de charset UTF-8 ↔ ISO-8859-1 (por defecto: false)
    charset_conversion: false

    # Caché de segundo nivel (opcional)
    cache:
        enabled: false
        adapter: redis
        dsn: '%env(REDIS_URL)%'
        default_ttl: 3600
```

### Conversión de charset

Si tu servidor Sybase ASE usa ISO-8859-1 y tu aplicación PHP trabaja en UTF-8, habilita la conversión transparente:

```yaml
sybase_orm:
    charset_conversion: true
```

Cuando está habilitado:
- **Parámetros salientes** (PHP → Sybase): UTF-8 → ISO-8859-1 con `//TRANSLIT`
- **Resultados entrantes** (Sybase → PHP): ISO-8859-1 → UTF-8

Si la conversión falla para un valor, se preserva el string original sin lanzar excepción. Los valores no-string pasan sin modificación.

El `ConnectionManager` acepta un parámetro opcional `?LoggerInterface $logger` (PSR-3). Cuando se proporciona, registra advertencias (`warning`) cada vez que una conversión de charset falla, facilitando la detección de problemas de codificación:

```
[WARNING] Charset conversion failed (UTF-8 → ISO-8859-1) for value: <primeros 100 caracteres>
```

---

## 4. Tu primera entidad

### Entidad básica

Crea `src/Entity/Producto.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;

#[Entity(table: 'productos')]
class Producto
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 200)]
    private string $nombre = '';

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    private float $precio = 0.0;

    #[Column(type: 'integer')]
    private int $stock = 0;

    #[Column(type: 'boolean')]
    private bool $activo = true;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $creadoEn = null;

    // --- Getters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getPrecio(): float
    {
        return $this->precio;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function getCreadoEn(): ?\DateTimeImmutable
    {
        return $this->creadoEn;
    }

    // --- Setters ---

    public function setNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function setPrecio(float $precio): void
    {
        $this->precio = $precio;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }

    public function setActivo(bool $activo): void
    {
        $this->activo = $activo;
    }

    public function setCreadoEn(?\DateTimeImmutable $creadoEn): void
    {
        $this->creadoEn = $creadoEn;
    }
}
```

### Convenciones de nombres

Si no especificas nombres, el ORM los deriva automáticamente:

| PHP | Sybase ASE |
|-----|-----------|
| Clase `OrdenCompra` | Tabla `orden_compra` |
| Propiedad `fechaCreacion` | Columna `fecha_creacion` |
| Propiedad `nombre` | Columna `nombre` |

Puedes sobreescribir con parámetros explícitos:

```php
#[Entity(table: 'mis_productos')]           // Nombre de tabla explícito
#[Column(name: 'product_name')]             // Nombre de columna explícito
```

### Entidad con esquema

Para tablas en un esquema específico de Sybase ASE:

```php
#[Entity(table: 'facturas', schema: 'facturacion')]
class Factura
{
    // Genera SQL con: [facturacion].[facturas]
}
```

### Repositorio personalizado vía `repositoryClass`

Puedes asociar un repositorio directamente en el atributo `#[Entity]`:

```php
#[Entity(table: 'productos', repositoryClass: ProductoRepository::class)]
class Producto
{
    // ...
}
```

Con esto, `$em->getRepository(Producto::class)` retorna una instancia de `ProductoRepository`.

### Tipos de columna soportados

| Attribute `type` | PHP | Sybase ASE |
|-----------------|-----|-----------|
| `'integer'` o `'int'` | `int` | `INT` |
| `'string'` | `string` | `VARCHAR(length)` |
| `'text'` | `string` | `TEXT` |
| `'boolean'` o `'bool'` | `bool` | `BIT` (1/0) |
| `'float'` o `'double'` | `float` | `FLOAT` |
| `'decimal'` | `float` | `DECIMAL(precision, scale)` |
| `'datetime'` | `DateTimeImmutable` | `DATETIME` |
| `'smallint'` | `int` | `SMALLINT` |
| `'bigint'` | `int` | `BIGINT` |

### Diccionario de tipos (`Types`)

En lugar de strings literales, puedes usar las constantes de `SybaseORM\Type\Types` para autocompletado y seguridad en tiempo de compilación:

```php
use SybaseORM\Type\Types;

#[Column(type: Types::STRING, length: 200)]
private string $nombre = '';

#[Column(type: Types::INTEGER)]
private int $stock = 0;

#[Column(type: Types::DECIMAL, precision: 10, scale: 2)]
private float $precio = 0.0;

#[Column(type: Types::BOOLEAN)]
private bool $activo = true;

#[Column(type: Types::DATETIME, nullable: true)]
private ?\DateTimeImmutable $creadoEn = null;
```

Constantes disponibles: `Types::STRING`, `Types::VARCHAR`, `Types::TEXT`, `Types::INTEGER`, `Types::INT`, `Types::TINYINT`, `Types::SMALLINT`, `Types::BIGINT`, `Types::FLOAT`, `Types::DOUBLE`, `Types::DECIMAL`, `Types::REAL`, `Types::BOOLEAN`, `Types::BOOL`, `Types::DATETIME`.

Los literales string (`'string'`, `'integer'`, etc.) siguen funcionando — las constantes son opcionales.


---

## 5. Operaciones CRUD

### Inyectar el EntityManager

En cualquier controlador o servicio de Symfony:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Producto;
use SybaseORM\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ProductoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}
}
```

### Obtener el repositorio

Cada entidad se gestiona a través de su propio repositorio. El repositorio encapsula todas las operaciones de persistencia y consulta:

```php
$repo = $this->em->getRepository(Producto::class);
```

### Crear (INSERT)

```php
public function crear(): Response
{
    $repo = $this->em->getRepository(Producto::class);

    $producto = new Producto();
    $producto->setNombre('Laptop HP');
    $producto->setPrecio(1299.99);
    $producto->setStock(50);
    $producto->setCreadoEn(new \DateTimeImmutable());

    $repo->save($producto);

    // El ID se asigna automáticamente via @@identity de Sybase ASE
    $id = $producto->getId(); // e.g. 1

    return new Response("Producto creado con ID: {$id}");
}
```

### Leer (SELECT)

```php
public function ver(int $id): Response
{
    $repo = $this->em->getRepository(Producto::class);
    $producto = $repo->find($id);

    if ($producto === null) {
        throw $this->createNotFoundException();
    }

    return new Response("Producto: {$producto->getNombre()}");
}
```

### Actualizar (UPDATE)

```php
public function actualizar(int $id): Response
{
    $repo = $this->em->getRepository(Producto::class);
    $producto = $repo->find($id);

    $producto->setPrecio(999.99);
    $producto->setStock(30);

    // save() detecta automáticamente que es una actualización (dirty checking)
    $repo->save($producto);

    return new Response('Producto actualizado');
}
```

### Eliminar (DELETE)

```php
public function eliminar(int $id): Response
{
    $repo = $this->em->getRepository(Producto::class);
    $producto = $repo->find($id);

    $repo->delete($producto);

    return new Response('Producto eliminado');
}
```

### Operaciones en lote

```php
public function crearVarios(): void
{
    $repo = $this->em->getRepository(Producto::class);

    $productos = [];
    for ($i = 1; $i <= 100; $i++) {
        $producto = new Producto();
        $producto->setNombre("Producto #{$i}");
        $producto->setPrecio($i * 10.0);
        $producto->setStock($i);
        $productos[] = $producto;
    }

    // Un solo saveMany() ejecuta todos los INSERTs en una transacción
    $repo->saveMany($productos);
}
```

### Desvincular entidades (detach)

`detach()` remueve una entidad del contexto de persistencia (IdentityMap). Después de desvincularla, los cambios en esa instancia no se rastrean:

```php
public function desvincular(int $id): void
{
    $repo = $this->em->getRepository(Producto::class);
    $producto = $repo->find($id);

    // Verificar si la entidad está rastreada
    $this->em->isManaged($producto); // true

    // Desvincular del contexto de persistencia
    $this->em->detach($producto);

    $this->em->isManaged($producto); // false — ya no está rastreada

    // Un find() posterior hará una nueva consulta a la BD
    $productoFresco = $repo->find($id); // Consulta a BD, nueva instancia
}
```

`isManaged()` es útil para verificar si una entidad está siendo rastreada por el UnitOfWork antes de realizar operaciones.
```

---

## 6. Repositorios

### Repositorio por defecto

Cada entidad tiene un repositorio con métodos de consulta y persistencia:

```php
$repo = $this->em->getRepository(Producto::class);

// --- Consultas ---
$producto = $repo->find(1);                          // Por ID
$todos = $repo->findAll();                           // Todos
$activos = $repo->findBy(['activo' => true]);        // Por criterios
$laptop = $repo->findOneBy(['nombre' => 'Laptop']);  // Uno por criterios

// --- Consultas con ordenamiento y paginación ---
$recientes = $repo->findBy(
    ['activo' => true],                // criterios
    ['creadoEn' => 'DESC'],            // orderBy
    10,                                 // limit
    20,                                 // offset
);

// --- Información de la entidad ---
$tabla = $repo->getTableName();          // string — nombre de tabla en BD (e.g. "productos")
$nombre = $repo->getEntityShortName();   // string — nombre corto para OQL (e.g. "Producto")

// --- Persistencia ---
$repo->save($producto);                              // Crear o actualizar
$repo->saveMany([$p1, $p2, $p3]);                    // Lote en una transacción
$repo->delete($producto);                            // Eliminar
$repo->deleteMany([$p1, $p2]);                       // Eliminar varios

// --- QueryBuilder ---
$qb = $repo->createQueryBuilder();                   // Pre-configurado con FROM

// --- OQL: executeUpdate y queryScalar ---
$affected = $repo->executeUpdate(
    'UPDATE Producto p SET p.activo = :activo WHERE p.stock = :stock',
    ['activo' => false, 'stock' => 0]
);  // int — filas afectadas

$maxPrecio = $repo->queryScalar(
    'SELECT MAX(p.precio) FROM Producto p WHERE p.activo = :activo',
    ['activo' => true]
);  // mixed — valor escalar

// --- Conteo y existencia ---
$totalActivos = $repo->count(['activo' => true]);    // int — cuenta entidades que coincidan
$hayBaratos = $repo->exists(['precio' => 9.99]);     // bool — verifica si existe al menos una

// --- OQL personalizado ---
$resultados = $repo->query(
    'SELECT p FROM Producto p WHERE p.precio > :min',
    ['min' => 100],
);

// --- Transacciones ---
$repo->beginTransaction();
$repo->save($entidad1);
$repo->save($entidad2);
$repo->commit();
```

### Repositorio personalizado

Extiende `EntityRepository` para agregar métodos de negocio:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Producto;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityRepository;

class ProductoRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, Producto::class);
    }

    /**
     * Busca productos activos con precio menor al máximo.
     *
     * @return Producto[]
     */
    public function findBaratos(float $precioMaximo): array
    {
        return $this->query(
            'SELECT p FROM Producto p WHERE p.activo = :activo AND p.precio < :max ORDER BY p.precio ASC',
            ['activo' => true, 'max' => $precioMaximo],
        );
    }

    /**
     * Busca productos con stock disponible.
     *
     * @return Producto[]
     */
    public function findConStock(): array
    {
        return $this->findBy(['activo' => true]);
    }

    /**
     * Desactiva un producto (soft delete).
     */
    public function desactivar(int $id): void
    {
        $producto = $this->find($id);
        if ($producto !== null) {
            $producto->setActivo(false);
            $this->save($producto);
        }
    }

    /**
     * Actualiza precios en lote con transacción explícita.
     *
     * @param array<int, float> $precios ID => nuevo precio
     */
    public function actualizarPrecios(array $precios): void
    {
        $this->beginTransaction();

        try {
            foreach ($precios as $id => $nuevoPrecio) {
                $producto = $this->find($id);
                if ($producto !== null) {
                    $producto->setPrecio($nuevoPrecio);
                }
            }

            // flush() se ejecuta dentro de save() o manualmente via getEntityManager()
            $this->getEntityManager()->flush();
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
```

Registrar como servicio en Symfony:

```yaml
# config/services.yaml
services:
    App\Repository\ProductoRepository:
        arguments:
            $em: '@SybaseORM\ORM\EntityManagerInterface'
```

Usar en un controlador:

```php
class ProductoController extends AbstractController
{
    public function __construct(
        private readonly ProductoRepository $productos,
    ) {}

    public function listarBaratos(): Response
    {
        $baratos = $this->productos->findBaratos(500.0);
        // ...
    }

    public function crear(): Response
    {
        $producto = new Producto();
        $producto->setNombre('Teclado');
        $producto->setPrecio(49.99);

        $this->productos->save($producto);
        // ...
    }
}
```

---

## 7. QueryBuilder

El QueryBuilder genera SQL parametrizado con una API fluida:

### Consulta básica

```php
$qb = $this->em->createQueryBuilder(Producto::class);

$sql = $qb
    ->select('e.id', 'e.nombre', 'e.precio')
    ->where('e.activo = ?', [1])
    ->orderBy('e.nombre')
    ->getSQL();

$params = $qb->getParameters(); // [1]
```

### Condiciones múltiples

```php
$qb = $this->em->createQueryBuilder(Producto::class);

$sql = $qb
    ->select('*')
    ->where('e.activo = ?', [1])
    ->andWhere('e.precio > ?', [100])
    ->andWhere('e.stock > ?', [0])
    ->orderBy('e.precio', 'DESC')
    ->getSQL();
```

### OR conditions

```php
$sql = $qb
    ->select('*')
    ->where('e.nombre LIKE ?', ['%laptop%'])
    ->orWhere('e.nombre LIKE ?', ['%desktop%'])
    ->getSQL();
```

### JOINs

```php
$sql = $qb
    ->select('p.id', 'p.nombre', 'c.nombre')
    ->from('productos', 'p')
    ->join('categorias', 'c', 'c.id = p.categoria_id')
    ->where('c.activo = ?', [1])
    ->getSQL();
```

### LEFT JOIN

```php
$sql = $qb
    ->select('p.*', 'r.puntuacion')
    ->from('productos', 'p')
    ->leftJoin('resenas', 'r', 'r.producto_id = p.id')
    ->getSQL();
```

### Paginación

Sybase ASE no soporta `LIMIT/OFFSET`. El ORM genera automáticamente:
- `TOP n` para la primera página
- `ROW_NUMBER() OVER (...)` para páginas subsiguientes

```php
// Primera página: usa TOP
$sql = $qb
    ->select('*')
    ->orderBy('e.nombre')
    ->limit(10)
    ->getSQL();
// SELECT TOP 10 * FROM [productos] e ORDER BY e.nombre ASC

// Página 3: usa ROW_NUMBER()
$sql = $qb
    ->select('*')
    ->orderBy('e.nombre')
    ->limit(10)
    ->offset(20)
    ->getSQL();
// SELECT * FROM (SELECT ROW_NUMBER() OVER (ORDER BY e.nombre ASC) AS [__row_number], ...
// WHERE [__row_number] BETWEEN 21 AND 30
```

### GROUP BY

```php
$sql = $qb
    ->select('e.categoria_id', 'COUNT(*)')
    ->groupBy('e.categoria_id')
    ->getSQL();
```

### HAVING

Filtra resultados agrupados con `having()`:

```php
$qb = $this->em->createQueryBuilder(Producto::class);

$sql = $qb
    ->select('e.categoria_id', 'COUNT(*) AS total')
    ->groupBy('e.categoria_id')
    ->having('COUNT(*) > ?', [10])
    ->orderBy('total', 'DESC')
    ->getSQL();

$params = $qb->getParameters(); // [10]
```

`having()` emite la cláusula HAVING después de GROUP BY y antes de ORDER BY. Los parámetros de HAVING se combinan con los de WHERE en `getParameters()`. Funciona con o sin GROUP BY.

### reset() — reutilizar el QueryBuilder

El método `reset()` limpia todo el estado del QueryBuilder para reutilizar la misma instancia:

```php
$qb = $this->em->createQueryBuilder(Producto::class);

// Primera consulta
$sql1 = $qb
    ->select('e.id', 'e.nombre')
    ->where('e.activo = ?', [1])
    ->orderBy('e.nombre')
    ->getSQL();

// Limpiar todo el estado y construir otra consulta
$sql2 = $qb->reset()
    ->select('e.nombre', 'e.precio')
    ->where('e.precio > ?', [100])
    ->orderBy('e.precio', 'DESC')
    ->limit(5)
    ->getSQL();
```

Esto evita crear nuevas instancias de QueryBuilder en loops o servicios que ejecutan múltiples consultas.

### `setParameter()` / `setParameters()` — parámetros con nombre

Asigna parámetros con nombre de forma independiente a las cláusulas:

```php
$qb = $this->em->createQueryBuilder(Producto::class);

$sql = $qb
    ->select('e.id', 'e.nombre')
    ->where('e.activo = :activo')
    ->andWhere('e.precio > :min')
    ->setParameter('activo', true)
    ->setParameter('min', 100)
    ->getSQL();

// O asignar varios de una vez
$qb->setParameters(['activo' => true, 'min' => 100]);
```

---

## 8. OQL - Object Query Language

OQL permite escribir consultas usando nombres de entidades y propiedades en vez de tablas y columnas:

### Sintaxis básica

```php
// SELECT simple
$productos = $this->em->query(
    'SELECT p FROM Producto p'
);

// Con WHERE y parámetros
$activos = $this->em->query(
    'SELECT p FROM Producto p WHERE p.activo = :activo',
    ['activo' => true]
);

// Con ORDER BY
$ordenados = $this->em->query(
    'SELECT p FROM Producto p WHERE p.precio > :min ORDER BY p.precio DESC',
    ['min' => 100.0]
);
```

### SELECT de propiedades específicas

```php
$datos = $this->em->query(
    'SELECT p.nombre, p.precio FROM Producto p WHERE p.activo = :activo',
    ['activo' => true]
);
```

### JOINs en OQL

```php
$resultados = $this->em->query(
    'SELECT p FROM Producto p JOIN p.categoria c WHERE c.nombre = :cat',
    ['cat' => 'Electrónica']
);
```

### LEFT JOIN

```php
$resultados = $this->em->query(
    'SELECT p FROM Producto p LEFT JOIN p.resenas r WHERE r.puntuacion > :min',
    ['min' => 4]
);
```

### Literales

```php
// Literales numéricos
$caros = $this->em->query(
    'SELECT p FROM Producto p WHERE p.precio > 1000'
);

// Literales de texto
$laptops = $this->em->query(
    "SELECT p FROM Producto p WHERE p.nombre = 'Laptop'"
);
```

### Operadores soportados

`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `AND`, `OR`, `IS NULL`, `IS NOT NULL`, `IN`, `NOT IN`

```php
$resultados = $this->em->query(
    'SELECT p FROM Producto p WHERE p.precio >= :min AND p.precio <= :max AND p.activo = :activo',
    ['min' => 100, 'max' => 500, 'activo' => true]
);
```

### IS NULL / IS NOT NULL

```php
// Productos sin fecha de creación
$sinFecha = $this->em->query(
    'SELECT p FROM Producto p WHERE p.creadoEn IS NULL'
);

// Productos con email definido
$conEmail = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.email IS NOT NULL'
);
```

### IN / NOT IN

```php
// Con parámetro — el array se expande automáticamente en placeholders individuales
$productos = $this->em->query(
    'SELECT p FROM Producto p WHERE p.categoria IN (:categorias)',
    ['categorias' => ['electrónica', 'hogar', 'deportes']]
);

// Con literales
$excluidos = $this->em->query(
    'SELECT p FROM Producto p WHERE p.id NOT IN (1, 2, 3)'
);
```

### Funciones de agregación

Funciones soportadas: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`

```php
// COUNT simple
$total = $this->em->query('SELECT COUNT(p.id) FROM Producto p');

// COUNT(*)
$total = $this->em->query('SELECT COUNT(*) FROM Producto p');

// COUNT con DISTINCT
$categorias = $this->em->query(
    'SELECT COUNT(DISTINCT p.categoria) FROM Producto p'
);

// Múltiples agregaciones
$stats = $this->em->query(
    'SELECT SUM(p.precio) AS suma, AVG(p.precio) AS promedio, MIN(p.precio) AS minimo, MAX(p.precio) AS maximo FROM Producto p WHERE p.activo = :activo',
    ['activo' => true]
);
```

### HAVING

Filtra grupos después de GROUP BY:

```php
$departamentos = $this->em->query(
    'SELECT u.departamento, COUNT(u.id) AS total FROM Usuario u GROUP BY u.departamento HAVING COUNT(u.id) > 5'
);
```

### queryIterator() — streaming de resultados grandes

Para conjuntos de datos grandes que no caben en memoria, `queryIterator()` retorna un `Generator` que produce resultados uno a uno sin cargar todas las filas:

```php
// Procesar miles de registros sin consumir memoria excesiva
$iterator = $this->em->queryIterator(
    'SELECT p FROM Producto p WHERE p.activo = :activo',
    ['activo' => true]
);

foreach ($iterator as $producto) {
    // Cada entidad se hidrata bajo demanda
    $this->procesarProducto($producto);
}

// También soporta HYDRATE_ARRAY para resultados con agregaciones
use SybaseORM\ORM\HydrationMode;

$iterator = $this->em->queryIterator(
    'SELECT p.categoria, p.nombre, p.precio FROM Producto p ORDER BY p.precio DESC',
    [],
    HydrationMode::HYDRATE_ARRAY
);

foreach ($iterator as $fila) {
    // $fila es un array asociativo: ['categoria' => '...', 'nombre' => '...', 'precio' => ...]
}
```

### Funciones OQL personalizadas (`registerOqlFunction`)

Registra funciones SQL personalizadas para usarlas en consultas OQL:

```php
$this->em->registerOqlFunction('RAND2', 'RAND2()');
$this->em->registerOqlFunction('DATEDIFF_DAYS', 'DATEDIFF(day, ?, ?)');

$resultados = $this->em->query(
    'SELECT p FROM Producto p ORDER BY RAND2()'
);
```

### `refresh()` — recargar entidad desde la BD

Descarta los cambios en memoria y recarga la entidad desde la base de datos:

```php
$producto = $repo->find(1);
$producto->setNombre('cambio temporal');

$this->em->refresh($producto);
// $producto->getNombre() retorna el valor original de la BD
```

### JOIN con entidad (WITH)

Para JOINs que no se basan en relaciones mapeadas, usa la sintaxis `JOIN Entidad alias WITH condición`:

```php
// JOIN con entidad y condición WITH
$resultados = $this->em->query(
    'SELECT u FROM Usuario u JOIN Address a WITH a.userId = u.id WHERE a.ciudad = :ciudad',
    ['ciudad' => 'Madrid']
);

// LEFT JOIN con entidad
$resultados = $this->em->query(
    'SELECT u FROM Usuario u LEFT JOIN Profile p WITH p.userId = u.id'
);
```

### SELECT * (wildcard)

```php
$todos = $this->em->query('SELECT * FROM Producto p WHERE p.activo = :activo', ['activo' => true]);
```

### SELECT DISTINCT

```php
$nombres = $this->em->query('SELECT DISTINCT p.nombre FROM Producto p');
```

### Aliases de columna

```php
$datos = $this->em->query(
    'SELECT p.nombre AS nombreProducto, COUNT(p.id) AS cantidad FROM Producto p GROUP BY p.nombre'
);
```

### Modos de hidratación

Por defecto, `query()` retorna instancias de entidad (`HYDRATE_OBJECT`). Para consultas con agregaciones, aliases o selecciones multi-entidad, se puede usar `HYDRATE_ARRAY`:

```php
use SybaseORM\ORM\HydrationMode;

// Modo explícito: retorna arrays asociativos
$filas = $this->em->query(
    'SELECT p.nombre, COUNT(p.id) AS total FROM Producto p GROUP BY p.nombre',
    [],
    HydrationMode::HYDRATE_ARRAY
);
// $filas = [['nombre' => 'Laptop', 'total' => 5], ...]

// Auto-detección: consultas con agregaciones o aliases usan HYDRATE_ARRAY automáticamente
$stats = $this->em->query(
    'SELECT p.categoria, AVG(p.precio) AS promedio FROM Producto p GROUP BY p.categoria'
);
```

El tercer parámetro de `EntityManager::query()` es opcional. Si no se especifica, el ORM detecta automáticamente el modo apropiado según la consulta.


---

## 9. Relaciones entre entidades

### ManyToOne / OneToMany (1:N)

Ejemplo: un Producto pertenece a una Categoría, una Categoría tiene muchos Productos.

```php
// src/Entity/Categoria.php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\OneToMany;

#[Entity(table: 'categorias')]
class Categoria
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 100)]
    private string $nombre = '';

    #[OneToMany(targetEntity: Producto::class, mappedBy: 'categoria', cascade: ['persist'])]
    private array $productos = [];

    public function getId(): ?int { return $this->id; }
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): void { $this->nombre = $nombre; }
    public function getProductos(): array { return $this->productos; }
}
```

```php
// src/Entity/Producto.php (agregar relación)
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\JoinColumn;

#[Entity(table: 'productos')]
class Producto
{
    // ... propiedades anteriores ...

    #[ManyToOne(targetEntity: Categoria::class, inversedBy: 'productos', cascade: ['persist'])]
    #[JoinColumn(name: 'categoria_id', referencedColumnName: 'id')]
    private ?Categoria $categoria = null;

    public function getCategoria(): ?Categoria { return $this->categoria; }
    public function setCategoria(?Categoria $categoria): void { $this->categoria = $categoria; }
}
```

Uso:

```php
// Crear categoría y producto juntos (cascade persist)
$categoria = new Categoria();
$categoria->setNombre('Electrónica');

$producto = new Producto();
$producto->setNombre('Laptop');
$producto->setPrecio(1299.99);
$producto->setCategoria($categoria);

// Solo necesitas guardar el producto — la categoría se persiste automáticamente (cascade)
$repo = $this->em->getRepository(Producto::class);
$repo->save($producto);
// INSERT INTO [categorias] ... → obtiene id=1
// INSERT INTO [productos] ... categoria_id=1 (FK propagado automáticamente)
```

### OneToOne (1:1)

```php
use SybaseORM\Attribute\OneToOne;

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[OneToOne(targetEntity: Perfil::class, inversedBy: 'usuario', cascade: ['persist'])]
    #[JoinColumn(name: 'perfil_id', referencedColumnName: 'id')]
    private ?Perfil $perfil = null;
}

#[Entity(table: 'perfiles')]
class Perfil
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[OneToOne(targetEntity: Usuario::class, mappedBy: 'perfil')]
    private ?Usuario $usuario = null;
}
```

### ManyToMany (N:M)

```php
use SybaseORM\Attribute\ManyToMany;

#[Entity(table: 'productos')]
class Producto
{
    #[ManyToMany(
        targetEntity: Etiqueta::class,
        inversedBy: 'productos',
        joinTable: 'producto_etiqueta',
        cascade: ['persist'],
    )]
    private array $etiquetas = [];
}

#[Entity(table: 'etiquetas')]
class Etiqueta
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 50)]
    private string $nombre = '';

    #[ManyToMany(targetEntity: Producto::class, mappedBy: 'etiquetas')]
    private array $productos = [];
}
```

### Fetch modes

```php
// LAZY (por defecto): carga bajo demanda via proxy
#[ManyToOne(targetEntity: Categoria::class, fetch: 'LAZY')]

// EAGER: carga inmediata con la entidad principal
#[ManyToOne(targetEntity: Categoria::class, fetch: 'EAGER')]
```

### Cascade options

```php
// Persistir entidades relacionadas automáticamente
#[ManyToOne(targetEntity: Categoria::class, cascade: ['persist'])]

// Eliminar entidades relacionadas automáticamente
#[OneToMany(targetEntity: Producto::class, mappedBy: 'categoria', cascade: ['persist', 'remove'])]
```

---

## 10. Herencia de entidades

### Table Per Hierarchy (TPH)

Toda la jerarquía en una sola tabla con columna discriminadora:

```php
use SybaseORM\Attribute\InheritanceType;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;

#[Entity(table: 'notificaciones')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'tipo', type: 'string')]
#[DiscriminatorMap(map: [
    'email' => NotificacionEmail::class,
    'sms' => NotificacionSms::class,
    'push' => NotificacionPush::class,
])]
class Notificacion
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 500)]
    private string $mensaje = '';

    #[Column(type: 'datetime')]
    private \DateTimeImmutable $enviadoEn;
}

#[Entity]
class NotificacionEmail extends Notificacion
{
    #[Column(type: 'string', length: 200)]
    private string $destinatario = '';

    #[Column(type: 'string', length: 200, nullable: true)]
    private ?string $asunto = null;
}

#[Entity]
class NotificacionSms extends Notificacion
{
    #[Column(type: 'string', length: 20)]
    private string $telefono = '';
}

#[Entity]
class NotificacionPush extends Notificacion
{
    #[Column(type: 'string', length: 500)]
    private string $deviceToken = '';
}
```

Tabla resultante:
```
notificaciones (id, tipo, mensaje, enviado_en, destinatario, asunto, telefono, device_token)
```

### Table Per Type (TPT)

Tabla base + tabla por subclase, unidas por PK:

```php
#[Entity(table: 'pagos')]
#[InheritanceType(strategy: 'TPT')]
#[DiscriminatorMap(map: [
    'tarjeta' => PagoTarjeta::class,
    'transferencia' => PagoTransferencia::class,
])]
class Pago
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    private float $monto = 0.0;
}

#[Entity(table: 'pagos_tarjeta')]
class PagoTarjeta extends Pago
{
    #[Column(type: 'string', length: 4)]
    private string $ultimosDigitos = '';
}

#[Entity(table: 'pagos_transferencia')]
class PagoTransferencia extends Pago
{
    #[Column(type: 'string', length: 50)]
    private string $referencia = '';
}
```

### Table Per Concrete Class (TPC)

Tabla independiente por clase concreta:

```php
#[Entity(table: 'figuras')]
#[InheritanceType(strategy: 'TPC')]
class Figura
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 20)]
    private string $color = '';
}

#[Entity(table: 'circulos')]
class Circulo extends Figura
{
    #[Column(type: 'float')]
    private float $radio = 0.0;
}
// Tabla circulos: (id, color, radio) — incluye columnas heredadas
```

---

## 11. Hooks de ciclo de vida

Ejecuta lógica personalizada antes y después de operaciones de persistencia:

```php
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PreUpdate;
use SybaseORM\Attribute\PostUpdate;
use SybaseORM\Attribute\PreRemove;
use SybaseORM\Attribute\PostRemove;

#[Entity(table: 'articulos')]
#[HasLifecycleHooks]
class Articulo
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $titulo = '';

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $creadoEn = null;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $actualizadoEn = null;

    #[PrePersist]
    public function antesDeCrear(): void
    {
        $this->creadoEn = new \DateTimeImmutable();
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    #[PostPersist]
    public function despuesDeCrear(): void
    {
        // Ejemplo: enviar notificación, registrar en log
        error_log("Artículo creado con ID: {$this->id}");
    }

    #[PreUpdate]
    public function antesDeActualizar(): void
    {
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    #[PostUpdate]
    public function despuesDeActualizar(): void
    {
        // Ejemplo: invalidar caché externa
    }

    #[PreRemove]
    public function antesDeEliminar(): void
    {
        // Ejemplo: verificar que se puede eliminar
        // Si lanzas una excepción, la eliminación se cancela
    }

    #[PostRemove]
    public function despuesDeEliminar(): void
    {
        // Ejemplo: limpiar archivos asociados
    }
}
```

Los hooks se ejecutan en estos momentos:
- `PrePersist`: al llamar `$em->persist()` (antes del INSERT)
- `PostPersist`: después del INSERT exitoso en `flush()`
- `PreUpdate`: antes del UPDATE en `flush()` (solo si hay cambios)
- `PostUpdate`: después del UPDATE exitoso en `flush()`
- `PreRemove`: al llamar `$em->remove()` (antes del DELETE)
- `PostRemove`: después del DELETE exitoso en `flush()`

Si un hook lanza una excepción, la operación se cancela y se hace rollback.


---

## 12. Tipos personalizados

### BackedEnum

Los enums de PHP se convierten automáticamente:

```php
enum EstadoPedido: string
{
    case Pendiente = 'pendiente';
    case Procesando = 'procesando';
    case Enviado = 'enviado';
    case Entregado = 'entregado';
    case Cancelado = 'cancelado';
}

#[Entity(table: 'pedidos')]
class Pedido
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    // El type es el FQCN del enum
    #[Column(type: EstadoPedido::class)]
    private EstadoPedido $estado = EstadoPedido::Pendiente;

    public function getEstado(): EstadoPedido { return $this->estado; }
    public function setEstado(EstadoPedido $estado): void { $this->estado = $estado; }
}
```

En la base de datos se almacena el valor escalar (`'pendiente'`, `'procesando'`, etc.).

### Value Objects (tipos personalizados)

Para tipos complejos, implementa `CustomTypeInterface`:

```php
// src/ValueObject/Dinero.php
<?php

declare(strict_types=1);

namespace App\ValueObject;

final class Dinero
{
    private function __construct(
        private readonly int $centavos,
    ) {}

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public static function fromDecimal(float $monto): self
    {
        return new self((int) round($monto * 100));
    }

    public function getCentavos(): int
    {
        return $this->centavos;
    }

    public function toDecimal(): float
    {
        return $this->centavos / 100;
    }
}
```

```php
// src/Type/DineroType.php
<?php

declare(strict_types=1);

namespace App\Type;

use App\ValueObject\Dinero;
use SybaseORM\Type\CustomTypeInterface;

final class DineroType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        if (!$value instanceof Dinero) {
            throw new \InvalidArgumentException('Expected Dinero instance');
        }

        return $value->getCentavos();
    }

    public function toPhpValue(mixed $value): mixed
    {
        return Dinero::fromCentavos((int) $value);
    }
}
```

Registrar el tipo (en un CompilerPass o en el boot del bundle):

```php
$typeCaster->registerType('dinero', DineroType::class);
```

Usar en la entidad:

```php
#[Column(type: 'dinero')]
private Dinero $precio;
```

---

## 13. Transacciones

### Transacción implícita (flush)

Cada `save()` ejecuta automáticamente dentro de una transacción:

```php
$repo = $this->em->getRepository(Producto::class);

$producto1 = new Producto();
$producto1->setNombre('A');

$producto2 = new Producto();
$producto2->setNombre('B');

// saveMany() ejecuta todos los INSERTs en una sola transacción
$repo->saveMany([$producto1, $producto2]);
// Si alguno falla, se hace ROLLBACK de ambos
```

### Transacción explícita

Para operaciones que requieren control manual:

```php
$repo = $this->em->getRepository(Cuenta::class);

$repo->beginTransaction();

try {
    $cuenta1 = $repo->find(1);
    $cuenta2 = $repo->find(2);

    $cuenta1->setSaldo($cuenta1->getSaldo() - 100);
    $cuenta2->setSaldo($cuenta2->getSaldo() + 100);

    $repo->save($cuenta1);
    $repo->save($cuenta2);
    $repo->commit();
} catch (\Throwable $e) {
    $repo->rollback();
    throw $e;
}
```

### Niveles de aislamiento

```php
// Configurar antes de iniciar la transacción
$connectionManager->setTransactionIsolation('READ COMMITTED');
$connectionManager->setTransactionIsolation('REPEATABLE READ');
$connectionManager->setTransactionIsolation('SERIALIZABLE');
$connectionManager->setTransactionIsolation('READ UNCOMMITTED');
```

---

## 14. Migraciones

### Generar una migración

Compara las entidades con el esquema actual de la base de datos:

```bash
php bin/console sybase:migrations:generate
```

Genera un archivo en `sybase_ase/migrations/migration_20260408120000.php`:

```php
<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE [productos] ([id] INT IDENTITY NOT NULL, [nombre] VARCHAR(200) NOT NULL, [precio] DECIMAL(10,2) NOT NULL, [stock] INT NOT NULL, [activo] BIT NOT NULL, [creado_en] DATETIME NULL)',
    ],
    'down' => [
        'DROP TABLE [productos]',
    ],
];
```

### Ejecutar migraciones

```bash
php bin/console sybase:migrations:migrate
```

Cada migración se ejecuta dentro de una transacción. Si falla, se hace ROLLBACK automático.

Las versiones ejecutadas se registran en la tabla `__migrations`.

### Otros comandos

```bash
# Generar proxies para lazy loading
php bin/console sybase:proxy:generate

# Limpiar caché del ORM
php bin/console sybase:cache:clear
```

---

## 15. Caché

### Primer nivel (Identity Map)

Siempre activo. Garantiza que la misma fila retorne la misma instancia de objeto:

```php
$producto1 = $this->em->find(Producto::class, 1); // Consulta a BD
$producto2 = $this->em->find(Producto::class, 1); // Retorna desde caché

assert($producto1 === $producto2); // true — misma instancia
```

### Segundo nivel (Redis)

Opcional. Comparte resultados entre sesiones:

```yaml
sybase_orm:
    cache:
        enabled: true
        adapter: redis
        dsn: 'redis://localhost:6379'
        default_ttl: 3600  # 1 hora
```

Si Redis no está disponible, el ORM continúa operando solo con el primer nivel y registra una advertencia en el log.

### Limpiar caché

```bash
php bin/console sybase:cache:clear
```

O programáticamente:

```php
$this->em->clear(); // Limpia Identity Map y desasocia entidades
```

---

## 16. Manejo de errores

Todas las excepciones del ORM heredan de `SybaseORMException`:

```php
use SybaseORM\Exception\SybaseORMException;
use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\TransactionException;
use SybaseORM\Exception\TypeConversionException;
use SybaseORM\Exception\MigrationException;
use SybaseORM\Exception\OqlParseException;

// Capturar cualquier error del ORM
try {
    $this->em->flush();
} catch (SybaseORMException $e) {
    // Maneja cualquier error del ORM
}

// Capturar errores específicos
try {
    $this->em->flush();
} catch (ConnectionLostException $e) {
    // La conexión a Sybase ASE se perdió
} catch (PersistenceException $e) {
    // Error SQL (constraint violation, syntax error, etc.)
} catch (TransactionException $e) {
    // Commit/rollback sin transacción activa
}

// Error de conversión de tipos
try {
    $typeCaster->toDatabaseValue('no-es-bool', 'bool');
} catch (TypeConversionException $e) {
    echo $e->getSourceType();       // "string"
    echo $e->getTargetType();       // "bool"
    echo $e->getProblematicValue(); // "no-es-bool"
}
```

---

## 17. Buenas prácticas

### Entidades

- Usa propiedades `private` con getters/setters
- Inicializa propiedades con valores por defecto
- Usa `?int $id = null` para IDs auto-generados
- Usa `DateTimeImmutable` en vez de `DateTime`

### Rendimiento

- El ORM cachea ReflectionProperty, ReflectionClass, y metadatos automáticamente
- Usa `saveMany()` para operaciones en lote en vez de `save()` en un loop
- Usa `clear()` para liberar memoria en procesos batch largos
- Configura caché de segundo nivel (Redis) para consultas frecuentes

### Transacciones

- Cada `save()` ya es una transacción implícita
- Usa transacciones explícitas (`beginTransaction/commit/rollback`) cuando necesitas agrupar múltiples operaciones
- Siempre haz rollback en el catch

### Patrón de repositorio

- Cada entidad debe trabajarse a través de su propio repositorio
- Crea repositorios personalizados para lógica de negocio específica
- Usa `getEntityManager()` en repositorios personalizados solo cuando necesites acceso directo
- Inyecta repositorios personalizados en controladores y servicios, no el EntityManager

### Consultas

- Usa OQL para consultas orientadas a objetos
- Usa QueryBuilder para consultas dinámicas
- Siempre parametriza valores del usuario (el ORM lo hace automáticamente)

### Acceso directo al dialecto y la conexión

Para casos avanzados donde necesitas SQL crudo o funcionalidades específicas de Sybase ASE, el EntityManager expone acceso directo:

```php
// Acceso al dialecto SQL para construir consultas específicas de Sybase ASE
$dialect = $this->em->getDialect();

// Acceso al gestor de conexiones para ejecutar SQL crudo
$conn = $this->em->getConnection();
$stmt = $conn->executeQuery('SELECT @@version', []);
$version = $stmt->fetch(\PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Verificar si la conexión sigue activa
$conn->ping(); // bool

// Obtener la versión del servidor
$conn->getServerVersion(); // string
```

También puedes acceder a los metadatos de las entidades:

```php
// Leer metadatos de una entidad
$reader = $this->em->getMetadataReader();
$metadata = $reader->getClassMetadata(Producto::class);

// ClassMetadata tiene un __toString() útil para depuración
echo $metadata; // ClassMetadata(App\Entity\Producto → productos, 6 columns, 1 relationships)
```

### Migraciones

- Genera migraciones después de cada cambio en las entidades
- Revisa el SQL generado antes de ejecutar en producción
- Mantén los archivos de migración en control de versiones

### Query logging (PSR-3)

El `ConnectionManager` acepta un `Psr\Log\LoggerInterface` opcional para registrar advertencias de conversión de charset:

```yaml
# config/services.yaml
SybaseORM\Connection\ConnectionManager:
    arguments:
        $logger: '@logger'
```

---

## 18. Claves primarias compuestas

El ORM soporta claves primarias compuestas usando múltiples anotaciones `#[Id]` en una misma entidad.

### Definir una entidad con clave compuesta

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'inscripciones')]
class Inscripcion
{
    #[Id]
    #[Column(type: 'integer')]
    private int $estudianteId;

    #[Id]
    #[Column(type: 'integer')]
    private int $cursoId;

    #[Column(type: 'datetime')]
    private \DateTimeImmutable $fechaInscripcion;

    #[Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?float $calificacion = null;

    public function getEstudianteId(): int { return $this->estudianteId; }
    public function setEstudianteId(int $id): void { $this->estudianteId = $id; }

    public function getCursoId(): int { return $this->cursoId; }
    public function setCursoId(int $id): void { $this->cursoId = $id; }

    public function getFechaInscripcion(): \DateTimeImmutable { return $this->fechaInscripcion; }
    public function setFechaInscripcion(\DateTimeImmutable $fecha): void { $this->fechaInscripcion = $fecha; }

    public function getCalificacion(): ?float { return $this->calificacion; }
    public function setCalificacion(?float $cal): void { $this->calificacion = $cal; }
}
```

### Buscar por clave compuesta

`EntityManager::find()` acepta un array asociativo para claves compuestas:

```php
$inscripcion = $this->em->find(Inscripcion::class, [
    'estudianteId' => 1,
    'cursoId' => 42,
]);
```

### Cómo funciona internamente

- **ClassMetadata**: el array `$idFields` contiene los nombres de todas las propiedades marcadas con `#[Id]`. El método `getIdColumns()` retorna los `ColumnMetadata` correspondientes.
- **IdentityMap**: genera una clave determinista a partir del array (ordena las claves alfabéticamente y une los valores con `|`), garantizando identidad de objeto.
- **UnitOfWork**: genera cláusulas `WHERE` con `AND` para cada campo de la clave compuesta en operaciones UPDATE y DELETE.
- **Hydrator**: resuelve y almacena claves compuestas en el IdentityMap al hidratar resultados.
- Si se detecta una inconsistencia en el número de campos de la clave, se lanza `PersistenceException`.

### Persistir y actualizar

```php
$repo = $this->em->getRepository(Inscripcion::class);

$inscripcion = new Inscripcion();
$inscripcion->setEstudianteId(1);
$inscripcion->setCursoId(42);
$inscripcion->setFechaInscripcion(new \DateTimeImmutable());

$repo->save($inscripcion);
// INSERT INTO [inscripciones] ([estudiante_id], [curso_id], [fecha_inscripcion]) VALUES (?, ?, ?)

$inscripcion->setCalificacion(9.5);
$repo->save($inscripcion);
// UPDATE [inscripciones] SET [calificacion] = ? WHERE [estudiante_id] = ? AND [curso_id] = ?
```

---

## Referencia rápida de Attributes

| Attribute | Target | Parámetros |
|-----------|--------|-----------|
| `#[Entity]` | Clase | `table?`, `schema?`, `repositoryClass?` |
| `#[Column]` | Propiedad | `name?`, `type`, `nullable`, `length?`, `precision?`, `scale?` |
| `#[Id]` | Propiedad | `strategy?` (default: `'identity'`) |
| `#[GeneratedValue]` | Propiedad | `strategy?` (default: `'IDENTITY'`) |
| `#[OneToOne]` | Propiedad | `targetEntity`, `mappedBy?`, `inversedBy?`, `cascade?`, `fetch?` |
| `#[OneToMany]` | Propiedad | `targetEntity`, `mappedBy`, `cascade?`, `fetch?` |
| `#[ManyToOne]` | Propiedad | `targetEntity`, `inversedBy?`, `cascade?`, `fetch?` |
| `#[ManyToMany]` | Propiedad | `targetEntity`, `mappedBy?`, `inversedBy?`, `joinTable?`, `cascade?`, `fetch?` |
| `#[JoinColumn]` | Propiedad | `name`, `referencedColumnName?` (default: `'id'`) |
| `#[InheritanceType]` | Clase | `strategy` (`'TPH'`, `'TPT'`, `'TPC'`) |
| `#[DiscriminatorColumn]` | Clase | `name`, `type?` (default: `'string'`) |
| `#[DiscriminatorMap]` | Clase | `map` (array valor → clase) |
| `#[HasLifecycleHooks]` | Clase | — |
| `#[PrePersist]` | Método | — |
| `#[PostPersist]` | Método | — |
| `#[PreUpdate]` | Método | — |
| `#[PostUpdate]` | Método | — |
| `#[PreRemove]` | Método | — |
| `#[PostRemove]` | Método | — |

---

## Referencia rápida de comandos

```bash
# Instalar y configurar el bundle
php bin/console sybase:install

# Generar migración
php bin/console sybase:migrations:generate

# Ejecutar migraciones pendientes
php bin/console sybase:migrations:migrate

# Generar proxies para lazy loading
php bin/console sybase:proxy:generate

# Limpiar caché del ORM
php bin/console sybase:cache:clear
```
