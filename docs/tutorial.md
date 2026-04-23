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

    # Caché de segundo nivel (opcional)
    cache:
        enabled: false
        adapter: redis
        dsn: '%env(REDIS_URL)%'
        default_ttl: 3600
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

// --- Persistencia ---
$repo->save($producto);                              // Crear o actualizar
$repo->saveMany([$p1, $p2, $p3]);                    // Lote en una transacción
$repo->delete($producto);                            // Eliminar
$repo->deleteMany([$p1, $p2]);                       // Eliminar varios

// --- QueryBuilder ---
$qb = $repo->createQueryBuilder();                   // Pre-configurado con FROM

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

`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `AND`, `OR`

```php
$resultados = $this->em->query(
    'SELECT p FROM Producto p WHERE p.precio >= :min AND p.precio <= :max AND p.activo = :activo',
    ['min' => 100, 'max' => 500, 'activo' => true]
);
```


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

### Migraciones

- Genera migraciones después de cada cambio en las entidades
- Revisa el SQL generado antes de ejecutar en producción
- Mantén los archivos de migración en control de versiones

---

## Referencia rápida de Attributes

| Attribute | Target | Parámetros |
|-----------|--------|-----------|
| `#[Entity]` | Clase | `table?`, `schema?` |
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
