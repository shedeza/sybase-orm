# SybaseORM Bundle

Symfony Bundle que implementa un ORM completo para **Sybase ASE** (Adaptive Server Enterprise), inspirado en los patrones de Doctrine pero diseñado específicamente para el dialecto SQL y las limitaciones de Sybase ASE.

## Requisitos

- PHP 8.1+
- Extensión `pdo_dblib` (FreeTDS)
- Symfony 6.x o 7.x

## Instalación

**Paso 1:** Agregar el repositorio y requerir el paquete:

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

**Paso 2:** Ejecutar el comando de instalación:

```bash
php bin/console sybase:install
```

Este comando configura todo automáticamente:
- Crea `config/packages/sybase_orm.yaml` con la configuración por defecto
- Agrega `DATABASE_URL` al archivo `.env`
- Crea el directorio `sybase_ase/migrations/`
- Registra el bundle en `config/bundles.php`

**Paso 3:** Editar la variable `DATABASE_URL` en `.env` con tus datos de conexión:

```dotenv
DATABASE_URL="sybase://sa:mi_password@192.168.1.100:5000/mi_base?charset=UTF-8"
```

Si prefieres configurar manualmente sin el comando, consulta la sección de Configuración más abajo.

## Configuración

### Variables de entorno

La forma recomendada es usar una URL de conexión única en `.env`, similar a `DATABASE_URL` de Doctrine:

```dotenv
# .env
DATABASE_URL="sybase://sa:secret@192.168.1.100:5000/mi_base_de_datos?charset=UTF-8"
```

Formato de la URL:

```
sybase://usuario:password@host:puerto/base_de_datos?charset=UTF-8&persistent=true
```

Para entornos específicos, sobreescribir en `.env.local`:

```dotenv
# .env.local
DATABASE_URL="sybase://dev_user:dev_pass@localhost:5000/mi_base_dev?charset=UTF-8"
```

### Configuración del bundle

**Opción 1: URL de conexión (recomendada)**

```yaml
# config/packages/sybase_orm.yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'
```

**Opción 2: Parámetros individuales**

```yaml
# config/packages/sybase_orm.yaml
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

Con variables individuales en `.env`:

```dotenv
SYBASE_HOST=192.168.1.100
SYBASE_PORT=5000
SYBASE_DATABASE=mi_base_de_datos
SYBASE_USERNAME=sa
SYBASE_PASSWORD=secret
```

**Opciones adicionales (ambos modos)**

```yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'

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

Cuando se proporciona `url`, los parámetros individuales (`host`, `port`, `database`, etc.) se ignoran. Los caracteres especiales en el password deben ir URL-encoded (e.g. `p@ss` → `p%40ss`).

## Uso básico

### Definir una entidad

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 100)]
    private string $nombre = '';

    #[Column(type: 'string', length: 200, nullable: true)]
    private ?string $email = null;

    #[Column(type: 'boolean')]
    private bool $activo = true;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $creadoEn = null;

    // Getters y setters...
}
```

Si no se especifica `table`, el nombre se deriva automáticamente del nombre de la clase en snake_case (`Usuario` → `usuario`, `OrdenCompra` → `orden_compra`).

### Entidad con esquema

```php
#[Entity(table: 'facturas', schema: 'facturacion')]
class Factura
{
    // Genera SQL con: [facturacion].[facturas]
}
```

### Persistir y consultar

Cada entidad se gestiona a través de su propio repositorio:

```php
<?php

use SybaseORM\ORM\EntityManagerInterface;

class UsuarioController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function crear(): void
    {
        $repo = $this->em->getRepository(Usuario::class);

        $usuario = new Usuario();
        $usuario->setNombre('Juan');
        $usuario->setEmail('[email]');

        $repo->save($usuario);

        // El ID se asigna automáticamente via @@identity
        echo $usuario->getId(); // 1
    }

    public function buscar(int $id): ?Usuario
    {
        return $this->em->getRepository(Usuario::class)->find($id);
    }

    public function modificar(int $id): void
    {
        $repo = $this->em->getRepository(Usuario::class);
        $usuario = $repo->find($id);
        $usuario->setNombre('Pedro');

        // save() detecta el cambio automáticamente (dirty checking)
        $repo->save($usuario);
        // Genera: UPDATE [usuarios] SET [nombre] = ? WHERE [id] = ?
    }

    public function eliminar(int $id): void
    {
        $repo = $this->em->getRepository(Usuario::class);
        $usuario = $repo->find($id);
        $repo->delete($usuario);
    }
}
```

### Repositorios

```php
$repo = $this->em->getRepository(Usuario::class);

// Consultas
$usuario = $repo->find(1);
$todos = $repo->findAll();
$activos = $repo->findBy(['activo' => true]);
$admin = $repo->findOneBy(['email' => '[email]']);

// Persistencia
$repo->save($usuario);
$repo->saveMany([$u1, $u2, $u3]);
$repo->delete($usuario);
$repo->deleteMany([$u1, $u2]);
```

### QueryBuilder

```php
$qb = $this->em->createQueryBuilder(Usuario::class);

$sql = $qb
    ->select('e.id', 'e.nombre')
    ->where('e.activo = ?', [1])
    ->andWhere('e.nombre LIKE ?', ['%Juan%'])
    ->orderBy('e.nombre')
    ->limit(10)
    ->offset(20)
    ->getSQL();

// Genera paginación con TOP o ROW_NUMBER() según el offset
```

### OQL (Object Query Language)

```php
$usuarios = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.activo = :activo ORDER BY u.nombre ASC',
    ['activo' => true]
);
```

## Relaciones

```php
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Attribute\JoinColumn;

#[Entity(table: 'ordenes')]
class Orden
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[ManyToOne(targetEntity: Usuario::class, inversedBy: 'ordenes', cascade: ['persist'])]
    #[JoinColumn(name: 'usuario_id', referencedColumnName: 'id')]
    private ?Usuario $usuario = null;

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    private float $total = 0.0;
}

#[Entity(table: 'usuarios')]
class Usuario
{
    // ...

    #[OneToMany(targetEntity: Orden::class, mappedBy: 'usuario', cascade: ['persist'])]
    private array $ordenes = [];
}
```

Tipos de relación soportados: `#[OneToOne]`, `#[OneToMany]`, `#[ManyToOne]`, `#[ManyToMany]`.

La persistencia en cascada respeta automáticamente el orden de claves foráneas y propaga los IDs generados a las entidades dependientes.

## Herencia

### Table Per Hierarchy (TPH)

```php
use SybaseORM\Attribute\InheritanceType;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;

#[Entity(table: 'vehiculos')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'tipo', type: 'string')]
#[DiscriminatorMap(map: ['auto' => Auto::class, 'camion' => Camion::class])]
class Vehiculo
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $marca = '';
}

#[Entity]
class Auto extends Vehiculo
{
    #[Column(type: 'integer')]
    private int $puertas = 4;
}
```

Estrategias disponibles: `TPH` (Table Per Hierarchy), `TPT` (Table Per Type), `TPC` (Table Per Concrete Class).

## Hooks de ciclo de vida

```php
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PreUpdate;

#[Entity(table: 'articulos')]
#[HasLifecycleHooks]
class Articulo
{
    // ...

    #[PrePersist]
    public function antesDeCrear(): void
    {
        $this->creadoEn = new \DateTimeImmutable();
    }

    #[PreUpdate]
    public function antesDeActualizar(): void
    {
        $this->actualizadoEn = new \DateTimeImmutable();
    }
}
```

Hooks disponibles: `#[PrePersist]`, `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]`, `#[PostRemove]`.

## Conversión de tipos

Conversiones automáticas entre PHP y Sybase ASE:

| PHP | Sybase ASE | Notas |
|-----|-----------|-------|
| `bool` | `BIT` | `true` → `1`, `false` → `0` |
| `DateTime` | `DATETIME` | Formato `YYYY-MM-DD HH:MM:SS.mmm` |
| `int` | `INT` | Conversión directa |
| `float` | `FLOAT` / `DECIMAL` | Conversión directa |
| `string` | `VARCHAR` / `TEXT` | Conversión directa |
| `BackedEnum` | Valor escalar | `->value` para DB, `::from()` para PHP |

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

// Registrar el tipo
$typeCaster->registerType('money', MoneyType::class);
```

## Transacciones

```php
$repo = $this->em->getRepository(Cuenta::class);

$repo->beginTransaction();

try {
    $cuenta1 = $repo->find(1);
    $cuenta2 = $repo->find(2);

    $cuenta1->retirar(100);
    $cuenta2->depositar(100);

    $repo->save($cuenta1);
    $repo->save($cuenta2);
    $repo->commit();
} catch (\Throwable $e) {
    $repo->rollback();
    throw $e;
}
```

Niveles de aislamiento soportados: `READ UNCOMMITTED`, `READ COMMITTED`, `REPEATABLE READ`, `SERIALIZABLE`.

## Migraciones

```bash
# Generar migración comparando entidades con el esquema actual
php bin/console sybase:migrations:generate

# Ejecutar migraciones pendientes
php bin/console sybase:migrations:migrate
```

Las migraciones generan SQL compatible con Sybase ASE (`CREATE TABLE`, `ALTER TABLE ADD/DROP`, con tipos `INT`, `VARCHAR`, `BIT`, `DECIMAL`, `DATETIME`, `IDENTITY`, etc.).

## Otros comandos

```bash
# Configurar el bundle en el proyecto (crea config, .env, bundles.php)
php bin/console sybase:install

# Generar clases proxy para lazy loading
php bin/console sybase:proxy:generate

# Limpiar caché del ORM
php bin/console sybase:cache:clear
```

## Caché

El ORM implementa dos niveles de caché:

- **Primer nivel (Identity Map)**: siempre activo, garantiza una instancia por entidad por sesión
- **Segundo nivel (Redis)**: opcional, comparte resultados entre sesiones con TTL configurable

Si Redis no está disponible, el sistema continúa operando solo con el primer nivel y registra una advertencia en el log.

## Arquitectura

```
src/
├── Attribute/           # PHP Attributes para mapeo de entidades
├── Cache/               # Caché de dos niveles (IdentityMap + Redis)
├── Command/             # Comandos de consola Symfony
├── Connection/          # Gestión de conexiones PDO dblib
├── DependencyInjection/ # Integración con el contenedor DI de Symfony
├── Dialect/             # Dialecto SQL para Sybase ASE
├── Exception/           # Excepciones del dominio
├── Hook/                # Dispatcher de hooks de ciclo de vida
├── Hydrator/            # Conversión de filas DB → instancias de entidad
├── Metadata/            # Lectura de metadatos desde PHP Attributes
├── Migration/           # Gestión de migraciones de esquema
├── ORM/                 # EntityManager, UnitOfWork, IdentityMap, Repository
├── Proxy/               # Generación de proxies para lazy loading
├── Query/               # QueryBuilder, OQL Parser/Printer/Translator, AST
├── Type/                # Conversión de tipos PHP ↔ Sybase ASE
└── SybaseORMBundle.php  # Punto de entrada del bundle
```

## Dialecto Sybase ASE

El `SybaseDialect` maneja las particularidades de Sybase ASE:

- Paginación con `TOP` (primera página) y `ROW_NUMBER()` (páginas subsiguientes) en lugar de `LIMIT/OFFSET`
- Omisión de columnas `IDENTITY` en `INSERT`
- Recuperación de IDs generados con `SELECT @@identity`
- Identificadores con corchetes: `[tabla]`, `[esquema].[tabla]`
- `SET ANSINULL ON` y `SET QUOTED_IDENTIFIER ON` al establecer conexión
- Liberación temprana de `PDOStatement` para respetar el límite de cursores

## Tests

```bash
vendor/bin/phpunit
```

524 tests, 1130 assertions cubriendo todos los componentes.

## Licencia

MIT
