# Sybase ORM

[![CI](https://github.com/shedeza/sybase-orm/actions/workflows/ci.yml/badge.svg)](https://github.com/shedeza/sybase-orm/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![Licencia MIT](https://img.shields.io/badge/licencia-MIT-green.svg)](LICENSE)

ORM puro en PHP para **Sybase ASE**, independiente de framework. Soporta mapeo de entidades con atributos PHP 8.1, consultas OQL, QueryBuilder, relaciones, herencia, caché de dos niveles, migraciones y más.

## Requisitos del sistema

- PHP 8.1 o superior
- Extensión `ext-pdo_dblib`
- Servidor Sybase ASE

## Instalación

```bash
composer require shedeza/sybase-orm
```

## Configuración rápida

### Configuración por array

```php
use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::create([
    'connection' => [
        'host'     => '192.168.1.100',
        'port'     => 5000,
        'dbname'   => 'mi_base',
        'username' => 'sa',
        'password' => 'secret',
        'charset'  => 'UTF-8',
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
]);
```

### Configuración por URL DSN

```php
use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::createFromUrl(
    'sybase://sa:secret@192.168.1.100:5000/mi_base?charset=UTF-8',
    entityDirectories: [__DIR__ . '/src/Entity'],
);
```

Los caracteres especiales en la contraseña deben codificarse con URL encoding (por ejemplo, `p@ss` → `p%40ss`).

## Uso básico

### Persistir una entidad

```php
$usuario = new Usuario();
$usuario->nombre = 'Juan';
$usuario->email = 'juan@example.com';

$em->persist($usuario);
$em->flush();
```

### Buscar por ID

```php
$usuario = $em->find(Usuario::class, 1);
```

### Consultar con OQL

```php
$usuarios = $em->query(
    'SELECT u FROM Usuario u WHERE u.activo = :activo',
    ['activo' => true]
);
```

### Eliminar una entidad

```php
$em->remove($usuario);
$em->flush();
```

## Mapeo de entidades

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\GeneratedValue;

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string', length: 100)]
    public string $nombre = '';

    #[Column(type: 'string', length: 255, nullable: true)]
    public ?string $email = null;

    #[Column(type: 'datetime')]
    public ?\DateTimeInterface $creadoEn = null;
}
```

## Atributos PHP disponibles

| Atributo | Destino | Descripción |
|----------|---------|-------------|
| `#[Entity]` | Clase | Marca una clase como entidad mapeada. Parámetros: `table`, `schema`, `repositoryClass`, `connection` |
| `#[Id]` | Propiedad | Marca la propiedad como clave primaria. Parámetro: `strategy` |
| `#[Column]` | Propiedad | Mapea una propiedad a una columna. Parámetros: `name`, `type`, `nullable`, `length`, `precision`, `scale` |
| `#[GeneratedValue]` | Propiedad | Indica que el valor es generado por la base de datos. Parámetro: `strategy` (por defecto `IDENTITY`) |
| `#[ManyToOne]` | Propiedad | Relación muchos-a-uno. Parámetros: `targetEntity`, `inversedBy`, `cascade`, `fetch` |
| `#[OneToMany]` | Propiedad | Relación uno-a-muchos. Parámetros: `targetEntity`, `mappedBy`, `cascade`, `fetch`, `orphanRemoval` |
| `#[OneToOne]` | Propiedad | Relación uno-a-uno. Parámetros: `targetEntity`, `mappedBy`, `inversedBy`, `cascade`, `fetch`, `orphanRemoval` |
| `#[ManyToMany]` | Propiedad | Relación muchos-a-muchos. Parámetros: `targetEntity`, `mappedBy`, `inversedBy`, `joinTable`, `cascade`, `fetch` |
| `#[JoinColumn]` | Propiedad | Especifica la columna de unión. Parámetros: `name`, `referencedColumnName` |
| `#[JoinColumns]` | Propiedad | Múltiples columnas de unión para claves compuestas |
| `#[Embeddable]` | Clase | Marca una clase como value object embebible |
| `#[Embedded]` | Propiedad | Mapea una propiedad a un value object. Parámetros: `class`, `columnPrefix` |
| `#[HasLifecycleHooks]` | Clase | Activa hooks de ciclo de vida en la entidad |
| `#[PrePersist]` | Método | Hook ejecutado antes de insertar |
| `#[PostPersist]` | Método | Hook ejecutado después de insertar |
| `#[PreUpdate]` | Método | Hook ejecutado antes de actualizar |
| `#[PostUpdate]` | Método | Hook ejecutado después de actualizar |
| `#[PreRemove]` | Método | Hook ejecutado antes de eliminar |
| `#[PostRemove]` | Método | Hook ejecutado después de eliminar |
| `#[SoftDelete]` | Clase | Activa eliminación lógica. Parámetro: `column` (por defecto `deleted_at`) |
| `#[InheritanceType]` | Clase | Estrategia de herencia: `TPH`, `TPT`, `TPC` |
| `#[DiscriminatorColumn]` | Clase | Columna discriminadora para herencia. Parámetros: `name`, `type` |
| `#[DiscriminatorMap]` | Clase | Mapa de valores discriminadores a clases. Parámetro: `map` |

## Relaciones

El ORM soporta relaciones `#[ManyToOne]`, `#[OneToMany]`, `#[OneToOne]` y `#[ManyToMany]` con lazy loading automático mediante proxies y eager loading vía `QueryBuilder::with()`.

Para documentación detallada de relaciones, consulte el [manual de relaciones](docs/usuario-manual/relaciones.md).

## Conexiones múltiples

Use `EntityManagerRegistry` para gestionar conexiones a múltiples bases de datos:

```php
use SybaseORM\ORM\OrmFactory;
use SybaseORM\ORM\EntityManagerRegistry;

$emDefault = OrmFactory::create(['connection' => $configDefault, 'entity_directories' => $dirs]);
$emReportes = OrmFactory::create(['connection' => $configReportes, 'entity_directories' => $dirs]);

$registry = new EntityManagerRegistry([
    'default'  => $emDefault,
    'reportes' => $emReportes,
], defaultConnection: 'default');

// Obtener un manager específico
$em = $registry->getManager('reportes');

// Obtener el manager por defecto
$em = $registry->getDefaultManager();

// Obtener el manager que gestiona una entidad (por su atributo connection)
$em = $registry->getManagerForEntity(Reporte::class);
```

## Opciones de configuración

| Opción | Tipo | Valor por defecto | Descripción |
|--------|------|-------------------|-------------|
| `host` | `string` | `'localhost'` | Host del servidor Sybase ASE |
| `port` | `int` | `5000` | Puerto de conexión |
| `dbname` | `string` | *(requerido)* | Nombre de la base de datos |
| `username` | `string` | `''` | Usuario de conexión |
| `password` | `string` | `''` | Contraseña de conexión |
| `charset` | `string` | `'UTF-8'` | Charset de la conexión |
| `persistent` | `bool` | `false` | Usar conexiones persistentes |
| `charset_conversion` | `bool` | `false` | Activar conversión automática UTF-8 ↔ ISO-8859-1 |
| `read_only` | `bool` | `false` | Modo solo lectura (previene escrituras) |
| `entity_directories` | `string[]` | `[]` | Directorios donde buscar entidades |
| `entity_classes` | `string[]` | `[]` | Clases de entidad explícitas |
| `proxy_directory` | `string` | `sys_get_temp_dir() . '/sybase-orm-proxies'` | Directorio para proxies generados |
| `metadata_cache_dir` | `string\|null` | `null` | Directorio de caché de metadatos |

## Documentación completa

- [Documentación completa](docs/README.md) — Punto de entrada central a toda la documentación
- [Manual de usuario](docs/usuario-manual/README.md) — Guía detallada de cada módulo del ORM
- [Manual de operación](docs/manual-operacion/README.md) — Despliegue, optimización y troubleshooting
- [Manual técnico](docs/manual-tecnico/README.md) — Arquitectura interna, patrones y extensibilidad

## Licencia

Este proyecto está licenciado bajo la [Licencia MIT](LICENSE).
