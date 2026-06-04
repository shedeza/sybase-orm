# Soft Delete

El soft delete (eliminación lógica) permite marcar registros como eliminados sin borrarlos físicamente de la base de datos. En lugar de ejecutar un `DELETE`, el ORM actualiza una columna de timestamp que indica cuándo se eliminó el registro.

## Atributo #[SoftDelete]

Se aplica a nivel de clase junto con `#[Entity]` para habilitar la eliminación lógica.

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\SoftDelete;

#[Entity(table: 'usuarios')]
#[SoftDelete(column: 'deleted_at')]
class Usuario
{
    // ...
}
```

### Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `column` | `string` | `'deleted_at'` | Nombre de la columna en la tabla que almacena la fecha de eliminación |

Si no se especifica el parámetro `column`, se usa `deleted_at` por defecto:

```php
#[Entity(table: 'articulos')]
#[SoftDelete] // Usa column: 'deleted_at' por defecto
class Articulo
{
    // ...
}
```

La columna debe existir en la tabla y aceptar valores `NULL` (un valor `NULL` indica que el registro no está eliminado). Cuando se elimina un registro, el ORM asigna la fecha actual mediante `GETDATE()`.

## Comportamiento automático

### Filtrado en consultas

Cuando una entidad tiene `#[SoftDelete]`, los métodos del repositorio filtran automáticamente los registros eliminados añadiendo la condición `WHERE deleted_at IS NULL`:

```php
$repo = $em->getRepository(Usuario::class);

// Solo retorna usuarios NO eliminados
$usuarios = $repo->findAll();
// OQL generado: SELECT ... FROM Usuario e WHERE e.deletedAt IS NULL

// Buscar por criterios (excluye eliminados automáticamente)
$activos = $repo->findBy(['estado' => 'activo']);
// OQL generado: SELECT ... WHERE e.deletedAt IS NULL AND e.estado = :p0
```

Este filtrado se aplica automáticamente en:

- `findAll()`
- `findBy()`
- `findOneBy()`
- `findOneByOrFail()`

### Incluir registros eliminados con `_withTrashed`

Para consultar registros eliminados junto con los activos, se pasa el parámetro especial `_withTrashed` en los criterios:

```php
// Incluye TODOS los registros (activos y eliminados)
$todos = $repo->findBy(['_withTrashed' => true]);

// Combinar con otros criterios
$todosAdmins = $repo->findBy([
    'rol' => 'admin',
    '_withTrashed' => true,
]);
```

El parámetro `_withTrashed` no se envía como condición SQL: se extrae internamente y solo controla si se aplica el filtro de soft delete.

### Eliminación (UPDATE en lugar de DELETE)

Cuando se elimina una entidad con soft delete mediante `remove()` o `delete()`, el ORM ejecuta un `UPDATE` en lugar de un `DELETE` físico:

```php
// Soft delete: marca el registro como eliminado
$repo->delete($usuario);
// SQL generado: UPDATE usuarios SET deleted_at = GETDATE() WHERE id = @p0

// También funciona con el EntityManager directamente
$em->remove($usuario);
$em->flush();
// Mismo SQL: UPDATE usuarios SET deleted_at = GETDATE() WHERE id = @p0
```

## Ejemplo completo

```php
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\SoftDelete;

#[Entity(table: 'publicaciones')]
#[SoftDelete(column: 'deleted_at')]
class Publicacion
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string', length: 200)]
    public string $titulo;

    #[Column(type: 'text')]
    public string $contenido;

    #[Column(type: 'string', length: 50)]
    public string $estado = 'borrador';

    #[Column(name: 'deleted_at', type: 'datetime', nullable: true)]
    public ?\DateTimeInterface $deletedAt = null;

    #[Column(name: 'created_at', type: 'datetime')]
    public \DateTimeInterface $createdAt;
}
```

### Operaciones con soft delete

```php
$repo = $em->getRepository(Publicacion::class);

// --- Crear y persistir ---
$pub = new Publicacion();
$pub->titulo = 'Mi artículo';
$pub->contenido = 'Contenido del artículo...';
$pub->createdAt = new \DateTimeImmutable();
$repo->save($pub);

// --- Consultar (excluye eliminados) ---
$publicaciones = $repo->findAll();
$borradores = $repo->findBy(['estado' => 'borrador']);

// --- Soft delete ---
$repo->delete($pub);
// SQL: UPDATE publicaciones SET deleted_at = GETDATE() WHERE id = 1

// --- Verificar: ya no aparece en consultas normales ---
$resultado = $repo->findBy(['id' => $pub->id]);
// Retorna array vacío (el registro está "eliminado")

// --- Incluir eliminados ---
$todos = $repo->findBy(['_withTrashed' => true]);
// Retorna todas las publicaciones, incluyendo eliminadas

$eliminado = $repo->findBy([
    'id' => $pub->id,
    '_withTrashed' => true,
]);
// Retorna la publicación eliminada
```

## Consideraciones

- La columna de soft delete debe ser de tipo `datetime` y permitir valores `NULL`.
- Un registro con la columna en `NULL` se considera **activo** (no eliminado).
- Un registro con un valor de fecha se considera **eliminado lógicamente**.
- Los hooks `PreRemove` y `PostRemove` se disparan normalmente durante un soft delete.
- El soft delete es transparente: el código de negocio usa `remove()` / `delete()` sin cambios.
- Para consultas OQL manuales vía `query()` o `QueryBuilder`, el filtro de soft delete **no se aplica automáticamente**: debes añadir la condición `WHERE deleted_at IS NULL` manualmente si es necesario.

## Estructura de tabla requerida

```sql
CREATE TABLE publicaciones (
    id          INT IDENTITY PRIMARY KEY,
    titulo      VARCHAR(200) NOT NULL,
    contenido   TEXT NOT NULL,
    estado      VARCHAR(50) NOT NULL DEFAULT 'borrador',
    deleted_at  DATETIME NULL,       -- Columna para soft delete
    created_at  DATETIME NOT NULL
)
```

---

← [Anterior](./ciclo-vida-hooks.md) | [Índice](./README.md) | [Siguiente →](./herencia-entidades.md)
