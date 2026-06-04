# Relaciones entre Entidades

El ORM soporta las cuatro relaciones estándar entre entidades: ManyToOne, OneToMany, OneToOne y ManyToMany. Cada relación se define mediante atributos PHP 8.1 en las propiedades de la entidad.

## #[ManyToOne]

Define el lado propietario de una relación muchos-a-uno. La tabla de la entidad que declara esta relación contiene la columna de clave foránea.

### Parámetros

| Parámetro | Tipo | Obligatorio | Descripción |
|-----------|------|-------------|-------------|
| `targetEntity` | `string` | Sí | Clase FQCN de la entidad relacionada |
| `inversedBy` | `?string` | No | Nombre de la propiedad inversa en la entidad destino |
| `cascade` | `array` | No | Operaciones en cascada (`['persist', 'remove']`) |
| `fetch` | `string` | No | Estrategia de carga: `'LAZY'` (default) o `'EAGER'` |

### Ejemplo

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\JoinColumn;

#[Entity(table: 'comentarios')]
class Comentario
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'text')]
    private string $contenido;

    #[ManyToOne(targetEntity: Articulo::class, inversedBy: 'comentarios')]
    #[JoinColumn(name: 'articulo_id', referencedColumnName: 'id')]
    private ?Articulo $articulo = null;
}
```

## #[OneToMany]

Define el lado inverso de una relación uno-a-muchos. Esta propiedad no posee clave foránea; es el reflejo de un `#[ManyToOne]` en la entidad relacionada.

### Parámetros

| Parámetro | Tipo | Obligatorio | Descripción |
|-----------|------|-------------|-------------|
| `targetEntity` | `string` | Sí | Clase FQCN de la entidad relacionada |
| `mappedBy` | `string` | Sí | Propiedad en la entidad destino que posee el `#[ManyToOne]` |
| `cascade` | `array` | No | Operaciones en cascada |
| `fetch` | `string` | No | Estrategia de carga: `'LAZY'` (default) o `'EAGER'` |
| `orphanRemoval` | `bool` | No | Eliminar entidades huérfanas automáticamente (default `false`) |

### Ejemplo

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Collection\Collection;

#[Entity(table: 'articulos')]
class Articulo
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 200)]
    private string $titulo;

    #[OneToMany(targetEntity: Comentario::class, mappedBy: 'articulo', cascade: ['persist', 'remove'])]
    private Collection $comentarios;
}
```

## #[OneToOne]

Define una relación uno-a-uno. El **lado propietario** usa `inversedBy` y contiene la clave foránea. El **lado inverso** usa `mappedBy`.

### Parámetros

| Parámetro | Tipo | Obligatorio | Descripción |
|-----------|------|-------------|-------------|
| `targetEntity` | `string` | Sí | Clase FQCN de la entidad relacionada |
| `mappedBy` | `?string` | No | Solo en lado inverso: propiedad propietaria en la entidad destino |
| `inversedBy` | `?string` | No | Solo en lado propietario: propiedad inversa en la entidad destino |
| `cascade` | `array` | No | Operaciones en cascada |
| `fetch` | `string` | No | Estrategia de carga: `'LAZY'` (default) o `'EAGER'` |
| `orphanRemoval` | `bool` | No | Eliminar entidad huérfana automáticamente (default `false`) |

### Ejemplo — Lado propietario

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\OneToOne;
use SybaseORM\Attribute\JoinColumn;

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[OneToOne(targetEntity: Perfil::class, inversedBy: 'usuario', cascade: ['persist'])]
    #[JoinColumn(name: 'perfil_id', referencedColumnName: 'id')]
    private ?Perfil $perfil = null;
}
```

### Ejemplo — Lado inverso

```php
#[Entity(table: 'perfiles')]
class Perfil
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[OneToOne(targetEntity: Usuario::class, mappedBy: 'perfil')]
    private ?Usuario $usuario = null;
}
```

## #[ManyToMany]

Define una relación muchos-a-muchos. Requiere una tabla intermedia (join table). El lado propietario especifica `joinTable` e `inversedBy`; el lado inverso usa `mappedBy`.

### Parámetros

| Parámetro | Tipo | Obligatorio | Descripción |
|-----------|------|-------------|-------------|
| `targetEntity` | `string` | Sí | Clase FQCN de la entidad relacionada |
| `mappedBy` | `?string` | No | Solo lado inverso |
| `inversedBy` | `?string` | No | Solo lado propietario |
| `joinTable` | `?string` | No | Nombre de la tabla intermedia |
| `cascade` | `array` | No | Operaciones en cascada |
| `fetch` | `string` | No | Estrategia de carga: `'LAZY'` (default) o `'EAGER'` |

### Ejemplo

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\ManyToMany;
use SybaseORM\Collection\Collection;

#[Entity(table: 'estudiantes')]
class Estudiante
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[ManyToMany(targetEntity: Curso::class, inversedBy: 'estudiantes', joinTable: 'estudiante_curso')]
    private Collection $cursos;
}

#[Entity(table: 'cursos')]
class Curso
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[ManyToMany(targetEntity: Estudiante::class, mappedBy: 'cursos')]
    private Collection $estudiantes;
}
```

La tabla intermedia `estudiante_curso` contiene las columnas de clave foránea que enlazan ambas entidades.

## #[JoinColumn] y #[JoinColumns]

### #[JoinColumn]

Especifica la columna de clave foránea en la tabla propietaria.

| Parámetro | Tipo | Obligatorio | Descripción |
|-----------|------|-------------|-------------|
| `name` | `string` | Sí | Nombre de la columna FK en la tabla actual |
| `referencedColumnName` | `string` | No | Columna referenciada en la tabla destino (default `'id'`) |

```php
#[ManyToOne(targetEntity: Departamento::class)]
#[JoinColumn(name: 'dept_id', referencedColumnName: 'id')]
private ?Departamento $departamento = null;
```

### #[JoinColumns]

Para relaciones hacia entidades con claves primarias compuestas, se usa `#[JoinColumns]` con un array de `JoinColumn`:

```php
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\JoinColumns;
use SybaseORM\Attribute\JoinColumn;

#[ManyToOne(targetEntity: Inscripcion::class)]
#[JoinColumns([
    new JoinColumn(name: 'estudiante_id', referencedColumnName: 'estudiante_id'),
    new JoinColumn(name: 'curso_id', referencedColumnName: 'curso_id'),
])]
private ?Inscripcion $inscripcion = null;
```

## Estrategias de carga

### Lazy Loading (carga diferida)

Por defecto todas las relaciones usan `fetch: 'LAZY'`. El ORM genera un proxy que implementa la interfaz `LazyLoadingProxy`. Al acceder a una propiedad de la entidad relacionada, el proxy ejecuta la consulta automáticamente.

```php
// $comentario->getArticulo() devuelve un proxy inicialmente
$articulo = $comentario->getArticulo();

// Al acceder a una propiedad del proxy, se carga la entidad real
echo $articulo->getTitulo(); // Aquí se ejecuta la consulta SQL
```

La interfaz `LazyLoadingProxy` expone métodos para inspeccionar el estado del proxy:

```php
$articulo->__isInitialized();  // false antes de acceder a datos
$articulo->__initialize();     // Forzar carga inmediata
```

### Eager Loading (carga anticipada)

Para cargar relaciones en la consulta inicial y evitar el problema N+1, se usa el método `with()` del `QueryBuilder`:

```php
$qb = $em->createQueryBuilder();
$articulos = $qb->select('a.*')
    ->from('articulos', 'a')
    ->with('comentarios', 'autor')
    ->where('a.publicado = :pub', ['pub' => true])
    ->limit(10);
```

El método `with()` acepta uno o más nombres de relación y genera los JOINs necesarios para cargar los datos relacionados en una sola consulta.

También se puede definir eager loading a nivel de atributo:

```php
#[ManyToOne(targetEntity: Categoria::class, fetch: 'EAGER')]
#[JoinColumn(name: 'categoria_id')]
private ?Categoria $categoria = null;
```

---

← [Anterior](./mapeo-entidades.md) | [Índice](./README.md) | [Siguiente →](./sistema-consultas.md)
