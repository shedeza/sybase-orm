# SybaseORM Bundle

Symfony Bundle que implementa un ORM completo para **Sybase ASE** (Adaptive Server Enterprise), inspirado en los patrones de Doctrine pero diseñado específicamente para el dialecto SQL y las limitaciones de Sybase ASE.

## Requisitos

- PHP 8.1+
- Extensión `pdo_dblib` (FreeTDS)
- Symfony 6.x, 7.x o 8.x

## Instalación

**Paso 1:** Agregar el repositorio y requerir el paquete:

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
use SybaseORM\Type\Types;

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Column(type: Types::STRING, length: 100)]
    private string $nombre = '';

    #[Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $email = null;

    #[Column(type: Types::BOOLEAN)]
    private bool $activo = true;

    #[Column(type: Types::DATETIME, nullable: true)]
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

### Repositorio personalizado vía `repositoryClass`

Puedes asociar un repositorio personalizado directamente en el atributo `#[Entity]`:

```php
#[Entity(table: 'productos', repositoryClass: ProductoRepository::class)]
class Producto
{
    // ...
}
```

Con esto, `$em->getRepository(Producto::class)` retorna una instancia de `ProductoRepository` automáticamente.

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

    public function desvincular(int $id): void
    {
        $usuario = $this->em->getRepository(Usuario::class)->find($id);

        $this->em->isManaged($usuario); // true — rastreado por el UnitOfWork
        $this->em->contains($usuario);  // true — alias Doctrine-compatible
        $this->em->detach($usuario);    // Remueve del IdentityMap y del UnitOfWork
        $this->em->isManaged($usuario); // false — ya no está rastreado
    }

    public function refrescar(int $id): void
    {
        $usuario = $this->em->getRepository(Usuario::class)->find($id);
        $usuario->setNombre('cambio temporal');

        // Descarta cambios en memoria y recarga desde la BD
        $this->em->refresh($usuario);
        // $usuario->getNombre() retorna el valor original de la BD
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

// Consultas con ordenamiento y paginación
$recientes = $repo->findBy(
    ['activo' => true],
    ['creadoEn' => 'DESC'],  // orderBy
    10,                       // limit
    20,                       // offset
);
// La paginación se aplica a nivel SQL (TOP/ROW_NUMBER), no en memoria

// Conteo y existencia
$totalActivos = $repo->count(['activo' => true]);   // int
$existe = $repo->exists(['email' => '[email]']);     // bool

// OQL: executeUpdate y queryScalar
$affected = $repo->executeUpdate(
    'UPDATE Usuario u SET u.activo = :activo WHERE u.departamento = :dep',
    ['activo' => false, 'dep' => 'ventas']
);
$maxSalario = $repo->queryScalar(
    'SELECT MAX(u.salario) FROM Usuario u WHERE u.activo = :activo',
    ['activo' => true]
);

// Persistencia
$repo->save($usuario);
$repo->saveMany([$u1, $u2, $u3]);
$repo->delete($usuario);
$repo->deleteMany([$u1, $u2]);

// Buscar o lanzar excepción
$usuario = $repo->findOrFail(1);                              // PersistenceException si no existe
$admin = $repo->findOneByOrFail(['email' => '[email]']);      // PersistenceException si no existe

// Merge: re-asociar una entidad detached
$managed = $repo->merge($detachedUsuario);

// Transacciones
$repo->transactional(function () use ($repo) {
    $u1 = $repo->find(1);
    $u1->setNombre('Nuevo nombre');
    $repo->save($u1);
    // flush + commit automático, rollback si hay excepción
});

// Streaming de resultados (para conjuntos grandes)
$iterator = $repo->queryIterator(
    'SELECT u FROM Usuario u WHERE u.activo = :activo',
    ['activo' => true]
);
foreach ($iterator as $usuario) {
    // Cada entidad se hidrata bajo demanda
}
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

### QueryBuilder: `setParameter()` / `setParameters()`

Asigna parámetros con nombre de forma independiente a las cláusulas:

```php
$qb = $this->em->createQueryBuilder(Usuario::class);

$sql = $qb
    ->select('e.id', 'e.nombre')
    ->where('e.activo = :activo')
    ->andWhere('e.departamento = :dep')
    ->setParameter('activo', true)
    ->setParameter('dep', 'ventas')
    ->getSQL();

// O asignar varios de una vez
$qb->setParameters(['activo' => true, 'dep' => 'ventas']);
```

### QueryBuilder: HAVING

```php
$qb = $this->em->createQueryBuilder(Usuario::class);

$sql = $qb
    ->select('e.departamento', 'COUNT(*)')
    ->groupBy('e.departamento')
    ->having('COUNT(*) > ?', [5])
    ->orderBy('e.departamento')
    ->getSQL();

$params = $qb->getParameters(); // [5]
```

### QueryBuilder: reset()

Reutiliza una instancia de QueryBuilder limpiando todo su estado:

```php
$qb = $this->em->createQueryBuilder(Usuario::class);

// Primera consulta
$sql1 = $qb->select('e.id')->where('e.activo = ?', [1])->getSQL();

// Limpiar y reutilizar
$sql2 = $qb->reset()
    ->select('e.nombre')
    ->where('e.departamento = ?', ['ventas'])
    ->getSQL();
```

### OQL (Object Query Language)

```php
$usuarios = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.activo = :activo ORDER BY u.nombre ASC',
    ['activo' => true]
);
```

### OQL: `queryOne()` — resultado único

```php
// Retorna un solo resultado o null
$usuario = $this->em->queryOne(
    'SELECT u FROM Usuario u WHERE u.email = :email',
    ['email' => '[email]']
);
// Aplica TOP 1 automáticamente
```

### OQL: IS NULL / IS NOT NULL

```php
$sinEmail = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.email IS NULL'
);

$conEmail = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.email IS NOT NULL'
);
```

### OQL: IN / NOT IN

```php
// Con parámetro (array expandido automáticamente)
$usuarios = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.status IN (:estados)',
    ['estados' => ['activo', 'pendiente']]
);

// Con literales
$excluidos = $this->em->query(
    'SELECT u FROM Usuario u WHERE u.id NOT IN (1, 2, 3)'
);
```

### OQL: Funciones de agregación

```php
$resultado = $this->em->query(
    'SELECT COUNT(u.id) FROM Usuario u WHERE u.activo = :activo',
    ['activo' => true]
);

$stats = $this->em->query(
    'SELECT COUNT(DISTINCT u.departamento), AVG(u.salario), MAX(u.salario) FROM Usuario u'
);

// COUNT(*)
$total = $this->em->query('SELECT COUNT(*) FROM Usuario u');
```

### OQL: HAVING

```php
$departamentos = $this->em->query(
    'SELECT u.departamento, COUNT(u.id) AS total FROM Usuario u GROUP BY u.departamento HAVING COUNT(u.id) > 5'
);
```

### OQL: queryIterator() — streaming de resultados

Para conjuntos de datos grandes que no caben en memoria, `queryIterator()` retorna un `Generator` que produce resultados uno a uno sin cargar todas las filas:

```php
$iterator = $this->em->queryIterator(
    'SELECT u FROM Usuario u WHERE u.activo = :activo',
    ['activo' => true]
);

foreach ($iterator as $usuario) {
    // Cada $usuario se hidrata bajo demanda, sin acumular en memoria
    $this->procesarUsuario($usuario);
}
```

### OQL: JOIN con entidad (WITH)

```php
// JOIN basado en entidad con condición WITH (sin relación mapeada)
$resultados = $this->em->query(
    'SELECT u FROM Usuario u JOIN Address a WITH a.userId = u.id WHERE a.ciudad = :ciudad',
    ['ciudad' => 'Madrid']
);

// LEFT JOIN con entidad
$resultados = $this->em->query(
    'SELECT u FROM Usuario u LEFT JOIN Profile p WITH p.userId = u.id'
);
```

### OQL: SELECT *, DISTINCT y aliases

```php
// Wildcard
$todos = $this->em->query('SELECT * FROM Usuario u');

// DISTINCT
$nombres = $this->em->query('SELECT DISTINCT u.nombre FROM Usuario u');

// Aliases de columna
$datos = $this->em->query(
    'SELECT u.nombre AS nombreUsuario, COUNT(u.id) AS total FROM Usuario u GROUP BY u.nombre'
);
```

### OQL: Funciones personalizadas (`registerOqlFunction`)

Registra funciones SQL personalizadas para usarlas en consultas OQL:

```php
// Registrar una función SQL personalizada
$this->em->registerOqlFunction('RAND2', 'RAND2()');
$this->em->registerOqlFunction('DATEDIFF_DAYS', 'DATEDIFF(day, ?, ?)');

// Usar en OQL
$resultados = $this->em->query(
    'SELECT u FROM Usuario u WHERE DATEDIFF_DAYS(u.creadoEn, GETDATE()) > :dias',
    ['dias' => 30]
);
```

### Modos de hidratación

Por defecto, `EntityManager::query()` retorna instancias de entidad (`HYDRATE_OBJECT`). Para consultas con agregaciones, aliases o selecciones multi-entidad, se puede usar `HYDRATE_ARRAY`:

```php
use SybaseORM\ORM\HydrationMode;

// Modo explícito: retorna arrays asociativos
$filas = $this->em->query(
    'SELECT u.nombre, COUNT(u.id) AS total FROM Usuario u GROUP BY u.nombre',
    [],
    HydrationMode::HYDRATE_ARRAY
);
// $filas = [['nombre' => 'Juan', 'total' => 5], ...]

// Auto-detección: consultas con agregaciones o aliases usan HYDRATE_ARRAY automáticamente
$stats = $this->em->query(
    'SELECT u.departamento, AVG(u.salario) AS promedio FROM Usuario u GROUP BY u.departamento'
);
```

## Claves primarias compuestas

El ORM soporta claves primarias compuestas usando múltiples anotaciones `#[Id]` en una misma entidad:

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

    // Getters y setters...
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

El `IdentityMap` genera una clave determinista a partir del array (ordena las claves y une los valores con `|`), garantizando identidad de objeto también para entidades con clave compuesta.

El `UnitOfWork` genera cláusulas `WHERE` con `AND` para cada campo de la clave compuesta en operaciones UPDATE y DELETE.

## Conversión de charset

El ORM soporta conversión transparente de charset entre UTF-8 (PHP) e ISO-8859-1 (Sybase ASE):

```yaml
sybase_orm:
    connection:
        url: '%env(DATABASE_URL)%'
        charset_conversion: true
```

Cuando `charset_conversion` está habilitado:
- **Parámetros salientes**: UTF-8 → ISO-8859-1 (con `//TRANSLIT` para caracteres sin equivalencia)
- **Resultados entrantes**: ISO-8859-1 → UTF-8

Si la conversión falla para un valor, se preserva el string original sin lanzar excepción. Los valores no-string pasan sin modificación.

Si se inyecta un `Psr\Log\LoggerInterface` en el `ConnectionManager`, se registran advertencias cuando la conversión de charset falla, facilitando la detección de problemas de codificación en producción.

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

### Orphan Removal

Cuando se remueve un hijo de la colección de un padre, `orphanRemoval: true` lo elimina automáticamente de la BD en el siguiente flush:

```php
#[Entity(table: 'usuarios')]
class Usuario
{
    #[OneToMany(targetEntity: Orden::class, mappedBy: 'usuario', orphanRemoval: true)]
    private array $ordenes = [];

    public function removeOrden(Orden $orden): void
    {
        $this->ordenes = array_filter($this->ordenes, fn($o) => $o !== $orden);
    }
}

// Uso:
$usuario->removeOrden($orden);
$repo->save($usuario);
// La orden se elimina automáticamente de la BD (DELETE)
```

### Event Subscribers

Para cross-cutting concerns (auditoría, logging) sin modificar entidades:

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
        // Registrar cambio en tabla de auditoría
    }
}

// Registrar en el HookDispatcher
$hookDispatcher->addSubscriber(new AuditSubscriber());
```

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

Cada hook acepta un parámetro `priority` opcional (mayor prioridad ejecuta primero, default: 0):

```php
#[PrePersist(priority: 10)]
public function validar(): void { /* ejecuta primero */ }

#[PrePersist(priority: -1)]
public function log(): void { /* ejecuta después */ }
```

## Conversión de tipos

Conversiones automáticas entre PHP y Sybase ASE:

| PHP | Sybase ASE | Notas |
|-----|-----------|-------|
| `bool` | `BIT` | `true` → `1`, `false` → `0` |
| `DateTime` | `DATETIME` | Formato `YYYY-MM-DD HH:MM:SS.mmm` |
| `int` | `INT` | Conversión directa |
| `float` | `FLOAT` / `REAL` | Conversión directa |
| `string` | `DECIMAL` / `NUMERIC` | Preserva precisión como string PHP |
| `string` | `VARCHAR` / `TEXT` | Conversión directa |
| `DateTimeImmutable` | `DATE` | Solo fecha (sin hora) |
| `DateTimeImmutable` | `TIME` | Solo hora (sin fecha) |
| `BackedEnum` | Valor escalar | `->value` para DB, `::from()` para PHP |

### Diccionario de tipos (`Types`)

La clase `SybaseORM\Type\Types` provee constantes para todos los tipos soportados, evitando strings mágicos:

```php
use SybaseORM\Type\Types;

#[Column(type: Types::STRING, length: 100)]    // 'string'
#[Column(type: Types::VARCHAR, length: 100)]   // 'varchar' (alias de STRING)
#[Column(type: Types::INTEGER)]                 // 'integer'
#[Column(type: Types::INT)]                     // 'int' (alias de INTEGER)
#[Column(type: Types::BOOLEAN)]                 // 'boolean'
#[Column(type: Types::BOOL)]                    // 'bool' (alias de BOOLEAN)
#[Column(type: Types::DATETIME)]                // 'datetime'
#[Column(type: Types::DATE)]                    // 'date'
#[Column(type: Types::TIME)]                    // 'time'
#[Column(type: Types::DECIMAL, precision: 10, scale: 2)]  // 'decimal' (retorna string PHP)
#[Column(type: Types::NUMERIC, precision: 10, scale: 2)]  // 'numeric' (alias de DECIMAL)
#[Column(type: Types::TEXT)]                    // 'text'
#[Column(type: Types::FLOAT)]                   // 'float'
#[Column(type: Types::DOUBLE)]                  // 'double' (alias de FLOAT)
#[Column(type: Types::REAL)]                    // 'real'
#[Column(type: Types::BIGINT)]                  // 'bigint'
#[Column(type: Types::SMALLINT)]                // 'smallint'
#[Column(type: Types::TINYINT)]                 // 'tinyint'
```

Los literales string (`'string'`, `'integer'`, etc.) siguen funcionando. Las constantes son opcionales pero recomendadas para autocompletado y detección de errores en tiempo de compilación.

### Embeddable Value Objects

Mapea objetos de valor a múltiples columnas en la tabla del padre:

```php
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;
use SybaseORM\Attribute\Column;

#[Embeddable]
class Direccion
{
    #[Column(type: Types::STRING, length: 200)]
    public string $calle = '';

    #[Column(type: Types::STRING, length: 100)]
    public string $ciudad = '';

    #[Column(type: Types::STRING, length: 10)]
    public string $codigoPostal = '';
}

#[Entity(table: 'clientes')]
class Cliente
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Embedded(class: Direccion::class)]
    private ?Direccion $direccion = null;

    // Genera columnas: direccion_calle, direccion_ciudad, direccion_codigo_postal
}

// Prefijo personalizado
#[Embedded(class: Direccion::class, columnPrefix: 'envio_')]
private ?Direccion $direccionEnvio = null;
// Genera: envio_calle, envio_ciudad, envio_codigo_postal
```

El Hydrator crea automáticamente el objeto embeddable durante la hidratación. Si todas las columnas del embeddable son NULL, la propiedad queda como `null`.

> **Nota OQL:** En consultas OQL, las propiedades de embeddables se referencian por su nombre de columna (e.g. `u.direccion_calle`), no por la notación de punto (`u.direccion.calle`).

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

### `transactional()` — atajo para transacciones

Ejecuta un callable y hace flush automático al terminar. La transacción es gestionada internamente por el UnitOfWork durante el flush:

```php
$this->em->transactional(function () {
    $cuenta1 = $this->em->getRepository(Cuenta::class)->find(1);
    $cuenta2 = $this->em->getRepository(Cuenta::class)->find(2);

    $cuenta1->retirar(100);
    $cuenta2->depositar(100);

    $this->em->persist($cuenta1);
    $this->em->persist($cuenta2);
    // flush + commit automático al terminar el callback
});

// También disponible desde el repositorio
$repo->transactional(function () use ($repo) {
    $usuario = $repo->find(1);
    $usuario->setActivo(false);
    $repo->save($usuario);
});
```

### Savepoints

Para rollback parcial dentro de una transacción (Sybase ASE `SAVE TRANSACTION`):

```php
$conn = $this->em->getConnection();
$conn->beginTransaction();

try {
    // Operación 1
    $conn->executeStatement('INSERT INTO logs ...', [...]);

    $sp = $conn->createSavepoint();

    try {
        // Operación 2 (puede fallar)
        $conn->executeStatement('UPDATE cuentas ...', [...]);
    } catch (\Throwable $e) {
        $conn->rollbackToSavepoint($sp);
        // Operación 1 sigue intacta
    }

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    throw $e;
}
```

## Migraciones

```bash
# Generar migración comparando entidades con el esquema actual
php bin/console sybase:migrations:generate

# Ejecutar migraciones pendientes
php bin/console sybase:migrations:migrate
```

Las migraciones generan SQL compatible con Sybase ASE (`CREATE TABLE` con `PRIMARY KEY` y `FOREIGN KEY` constraints, `ALTER TABLE ADD/DROP`, con tipos `INT`, `VARCHAR`, `BIT`, `DECIMAL`, `DATETIME`, `IDENTITY`, etc.). Detecta columnas nuevas y eliminadas automáticamente.

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

### Limpiar caché programáticamente

```php
$this->em->clear();                  // Limpia todo el IdentityMap
$this->em->clear(Producto::class);   // Limpia solo las instancias de Producto
```

## Arquitectura

El `EntityManager` expone `getDialect()` y `getConnection()` para acceso directo al dialecto SQL y al gestor de conexiones cuando se necesita SQL crudo:

```php
// Acceso al dialecto para construir SQL específico de Sybase ASE
$dialect = $this->em->getDialect();

// Acceso a la conexión para ejecutar SQL crudo
$conn = $this->em->getConnection();
$stmt = $conn->executeQuery('SELECT @@version', []);
```

### Conexión: `ping()`, `getServerVersion()` y `reconnect()`

```php
$conn = $this->em->getConnection();

// Verificar si la conexión sigue activa
if ($conn->ping()) {
    echo 'Conexión activa';
}

// Obtener la versión del servidor Sybase ASE
$version = $conn->getServerVersion();

// Forzar reconexión (útil para workers long-running)
$conn->reconnect();
```

### Query logging (PSR-3)

El `ConnectionManager` acepta un `Psr\Log\LoggerInterface` opcional. Cuando se proporciona, registra advertencias de conversión de charset. Para logging de queries SQL, configura un logger PSR-3 en tu servicio:

```yaml
# config/services.yaml
SybaseORM\Connection\ConnectionManager:
    arguments:
        $logger: '@logger'
```

### Caché de sentencias preparadas

El `ConnectionManager` mantiene un caché interno de `PDOStatement` (`stmtCache`). Las sentencias SQL idénticas reutilizan el statement preparado, evitando re-compilación en el servidor. El caché se invalida automáticamente al reconectar.

```
src/
├── Attribute/           # PHP Attributes para mapeo (Entity, Column, Id, relaciones, herencia, hooks, Embeddable)
├── Cache/               # Caché de dos niveles (IdentityMap + Redis)
├── Command/             # Comandos de consola Symfony
├── Connection/          # Gestión de conexiones PDO dblib, savepoints, charset conversion
├── DependencyInjection/ # Integración con el contenedor DI de Symfony
├── Dialect/             # Dialecto SQL para Sybase ASE
├── Exception/           # Excepciones del dominio con factory methods
├── Hook/                # Dispatcher de hooks de ciclo de vida + EventSubscriberInterface
├── Hydrator/            # Conversión de filas DB → instancias de entidad (con embeddables)
├── Metadata/            # Lectura de metadatos, ClassMetadata, EmbeddedMetadata, EntityDiscovery
├── Migration/           # Gestión de migraciones (PK/FK constraints, preview)
├── ORM/                 # EntityManager, UnitOfWork, IdentityMap, Repository, PersistentCollection
├── Proxy/               # Generación de proxies para lazy loading
├── Query/               # QueryBuilder, OQL Parser/Printer/Translator, AST
├── Type/                # Conversión de tipos PHP ↔ Sybase ASE, Types dictionary
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
- `generateCount()` y `generateExists()` para consultas optimizadas de conteo y existencia

## Tests

```bash
vendor/bin/phpunit
```

3031+ tests cubriendo todos los componentes.

## Licencia

MIT
