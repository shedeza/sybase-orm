# Repositorio de Entidades

El `EntityRepository` proporciona una API de alto nivel para acceder y persistir entidades sin interactuar directamente con el EntityManager. Cada entidad puede tener su propio repositorio personalizado.

## Obtener un Repositorio

```php
$repository = $em->getRepository(Usuario::class);
```

## Métodos de Búsqueda

### find()

Busca una entidad por su identificador primario. Retorna `null` si no existe.

```php
$usuario = $repository->find(42);
```

### findOrFail()

Busca por identificador y lanza `PersistenceException` si no se encuentra.

```php
$usuario = $repository->findOrFail(42);
```

### findAll()

Retorna todas las entidades de la tabla.

```php
$usuarios = $repository->findAll();
```

### findBy()

Busca entidades que cumplan criterios, con ordenamiento, límite y offset opcionales.

```php
$activos = $repository->findBy(
    criteria: ['status' => 'activo', 'rol' => 'admin'],
    orderBy: ['nombre' => 'ASC'],
    limit: 10,
    offset: 0
);
```

### findOneBy()

Retorna la primera entidad que cumpla los criterios, o `null`.

```php
$usuario = $repository->findOneBy(['email' => 'admin@ejemplo.com']);
```

### findOneByOrFail()

Como `findOneBy()` pero lanza `PersistenceException` si no encuentra resultados.

```php
$usuario = $repository->findOneByOrFail(['email' => 'admin@ejemplo.com']);
```

## Criterios de Búsqueda

Los métodos `findBy()`, `findOneBy()`, `count()` y `exists()` aceptan un array de criterios con soporte especial para distintos tipos de valores:

### Valores escalares (igualdad)

```php
$repository->findBy(['status' => 'activo']);
// WHERE e.status = :p0
```

### Valor null (IS NULL)

```php
$repository->findBy(['deleted_at' => null]);
// WHERE e.deleted_at IS NULL
```

### Arrays (cláusula IN)

```php
$repository->findBy(['rol' => ['admin', 'editor', 'moderador']]);
// WHERE e.rol IN (:p0)  -- expansión automática del array
```

### Combinación de criterios

```php
$repository->findBy([
    'departamento' => 'ventas',
    'status' => ['activo', 'pendiente'],
    'supervisor_id' => null,
]);
// WHERE e.departamento = :p0 AND e.status IN (:p1) AND e.supervisor_id IS NULL
```

### Soft Delete y _withTrashed

Si la entidad usa `#[SoftDelete]`, los registros eliminados se filtran automáticamente. Para incluirlos:

```php
$todos = $repository->findBy(['_withTrashed' => true, 'status' => 'inactivo']);
```

## Métodos de Persistencia

### save()

Persiste y hace flush de una entidad en una sola llamada.

```php
$usuario = new Usuario();
$usuario->nombre = 'Juan';
$repository->save($usuario);
```

### saveMany()

Persiste múltiples entidades y ejecuta flush una sola vez.

```php
$repository->saveMany([$usuario1, $usuario2, $usuario3]);
```

### delete()

Marca una entidad para eliminación y ejecuta flush.

```php
$repository->delete($usuario);
```

### deleteMany()

Elimina múltiples entidades en un solo flush.

```php
$repository->deleteMany([$usuario1, $usuario2]);
```

## Conteo y Existencia

### count()

Cuenta entidades que cumplan los criterios (sin criterios, cuenta todas).

```php
$total = $repository->count();
$activos = $repository->count(['status' => 'activo']);
```

### exists()

Verifica si existe al menos una entidad que cumpla los criterios.

```php
if ($repository->exists(['email' => 'admin@ejemplo.com'])) {
    // El email ya está registrado
}
```

## Repositorios Personalizados

Para agregar lógica de negocio, extiende `EntityRepository`:

```php
use SybaseORM\ORM\EntityRepository;

class UsuarioRepository extends EntityRepository
{
    public function findActivos(): array
    {
        return $this->findBy(['status' => 'activo'], ['nombre' => 'ASC']);
    }

    public function findByDepartamento(string $depto, int $limit = 50): array
    {
        return $this->findBy(
            criteria: ['departamento' => $depto],
            orderBy: ['fecha_ingreso' => 'DESC'],
            limit: $limit
        );
    }

    public function contarPorRol(string $rol): int
    {
        return $this->count(['rol' => $rol]);
    }

    public function buscarConQueryBuilder(string $termino): array
    {
        return $this->createQueryBuilder()
            ->where('e.nombre LIKE :termino')
            ->orWhere('e.email LIKE :termino')
            ->orderBy('e.nombre', 'ASC')
            ->setParameter('termino', "%{$termino}%")
            ->getResult();
    }
}
```

### Registro del repositorio personalizado

El repositorio se obtiene desde el EntityManager indicando la clase de entidad:

```php
/** @var UsuarioRepository $repo */
$repo = $em->getRepository(Usuario::class);
$activos = $repo->findActivos();
```

## Métodos Adicionales

### refresh()

Recarga la entidad desde la base de datos, descartando cambios en memoria.

```php
$repository->refresh($usuario);
```

### transactional()

Ejecuta una operación dentro de una transacción.

```php
$resultado = $repository->transactional(function () use ($repository) {
    $repository->save($entidad1);
    $repository->save($entidad2);
    return 'ok';
});
```

### createQueryBuilder()

Crea un QueryBuilder preconfigurado para la entidad del repositorio.

```php
$qb = $repository->createQueryBuilder();
$resultados = $qb->where('e.edad > :edad')
    ->setParameter('edad', 18)
    ->getResult();
```

---

← [Anterior](./transacciones.md) | [Índice](./README.md) | [Siguiente →](./manejo-errores.md)
