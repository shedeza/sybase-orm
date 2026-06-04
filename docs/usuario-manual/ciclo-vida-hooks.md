# Ciclo de Vida y Hooks

El ORM proporciona un sistema de lifecycle hooks que permite ejecutar lógica personalizada en momentos clave del ciclo de vida de una entidad: antes y después de insertar, actualizar o eliminar. Esto es útil para timestamps automáticos, validaciones, auditoría y notificaciones.

## Hooks Disponibles

| Hook | Momento de ejecución |
|------|---------------------|
| `#[PrePersist]` | Antes de insertar la entidad en la base de datos |
| `#[PostPersist]` | Después de insertar la entidad en la base de datos |
| `#[PreUpdate]` | Antes de actualizar la entidad en la base de datos |
| `#[PostUpdate]` | Después de actualizar la entidad en la base de datos |
| `#[PreRemove]` | Antes de eliminar la entidad de la base de datos |
| `#[PostRemove]` | Después de eliminar la entidad de la base de datos |

Cada hook acepta un parámetro opcional `priority` (por defecto `0`). Los métodos con mayor prioridad se ejecutan primero.

## Activar Hooks con #[HasLifecycleHooks]

Para que el ORM inspeccione los métodos de una entidad en busca de hooks, la clase debe estar decorada con `#[HasLifecycleHooks]`:

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\HasLifecycleHooks;

#[Entity(table: 'articulos')]
#[HasLifecycleHooks]
class Articulo
{
    // ...
}
```

Sin este atributo, los hooks definidos en la entidad no serán ejecutados por el `HookDispatcher`.

## Ejemplo Completo: Timestamps y Validaciones

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PreUpdate;
use SybaseORM\Attribute\PostPersist;

#[Entity(table: 'articulos')]
#[HasLifecycleHooks]
class Articulo
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private ?int $id = null;

    #[Column(type: 'string', length: 200)]
    private string $titulo;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $creadoEn = null;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeImmutable $actualizadoEn = null;

    #[PrePersist]
    public function asignarFechaCreacion(): void
    {
        $this->creadoEn = new \DateTimeImmutable();
        $this->actualizadoEn = $this->creadoEn;
    }

    #[PrePersist(priority: 10)]
    public function validarTitulo(): void
    {
        if (empty(trim($this->titulo))) {
            throw new \InvalidArgumentException('El título no puede estar vacío.');
        }
    }

    #[PreUpdate]
    public function actualizarTimestamp(): void
    {
        $this->actualizadoEn = new \DateTimeImmutable();
    }

    #[PostPersist]
    public function notificarCreacion(): void
    {
        // Lógica post-inserción (logging, eventos, etc.)
    }
}
```

En este ejemplo:
- `validarTitulo()` se ejecuta primero (priority 10) antes de la inserción.
- `asignarFechaCreacion()` se ejecuta después (priority 0) antes de la inserción.
- `actualizarTimestamp()` se ejecuta antes de cada actualización.
- `notificarCreacion()` se ejecuta después de la inserción exitosa.

## Múltiples Hooks del Mismo Tipo

Se pueden definir múltiples métodos para el mismo hook. El parámetro `priority` controla el orden de ejecución:

```php
#[PrePersist(priority: 20)]
public function validar(): void { /* se ejecuta primero */ }

#[PrePersist(priority: 0)]
public function prepararDatos(): void { /* se ejecuta después */ }
```

## HookDispatcher

El `HookDispatcher` es el componente central que ejecuta los hooks. Lee el mapa de lifecycle hooks desde los metadatos de la entidad e invoca los métodos anotados en el momento apropiado.

```php
use SybaseORM\Hook\HookDispatcher;

// El HookDispatcher se crea internamente por el ORM
// y se invoca desde el UnitOfWork durante persist/update/remove
$hookDispatcher->dispatch($entidad, 'PrePersist');
```

Si un método de hook lanza una excepción, esta se propaga sin modificar, lo que permite al `UnitOfWork` cancelar la operación.

## Event Subscribers Externos

Para lógica transversal (auditoría, logging, notificaciones) sin modificar las entidades, se usa `EventSubscriberInterface`:

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
        // Registrar auditoría del cambio
        $clase = $entity::class;
        error_log("[Audit] {$hookType} en {$clase}");
    }
}
```

### Registrar un Subscriber

```php
$hookDispatcher->addSubscriber(new AuditSubscriber());
```

El subscriber será notificado cada vez que se dispare un hook al que está suscrito, **después** de ejecutar los hooks a nivel de entidad.

## Integración con Symfony EventDispatcher

El ORM incluye `SymfonyEventDispatcherSubscriber`, un puente que redirige los hooks de ciclo de vida al `EventDispatcher` de Symfony (vía la interfaz PSR-14 `Psr\EventDispatcher\EventDispatcherInterface`).

### Configuración

```php
use SybaseORM\Hook\SymfonyEventDispatcherSubscriber;

// $eventDispatcher es una instancia de Psr\EventDispatcher\EventDispatcherInterface
$symfonySubscriber = new SymfonyEventDispatcherSubscriber($eventDispatcher);
$hookDispatcher->addSubscriber($symfonySubscriber);
```

Este subscriber escucha los eventos `PostPersist`, `PostUpdate` y `PostRemove`, y despacha un `EntityChangedEvent` a través de Symfony.

### EntityChangedEvent

El evento despachado contiene:

```php
use SybaseORM\Hook\EntityChangedEvent;

// Propiedades públicas de solo lectura:
$event->entity;      // object - La instancia de la entidad
$event->entityClass; // string - Nombre completo de la clase
$event->hookType;    // string - Tipo de hook ('PostPersist', 'PostUpdate', 'PostRemove')
```

### Listener en Symfony

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use SybaseORM\Hook\EntityChangedEvent;

#[AsEventListener(event: EntityChangedEvent::class)]
class RecalcularTotalesListener
{
    public function __invoke(EntityChangedEvent $event): void
    {
        if ($event->entityClass === Pedido::class && $event->hookType === 'PostPersist') {
            // Recalcular totales del pedido...
        }
    }
}
```

## Resumen del Flujo

1. El `UnitOfWork` detecta una operación (persist/update/remove).
2. El `HookDispatcher` consulta los metadatos de la entidad.
3. Se ejecutan los hooks a nivel de entidad (ordenados por prioridad).
4. Se notifican los subscribers externos registrados.
5. Si hay un `SymfonyEventDispatcherSubscriber`, se despacha `EntityChangedEvent`.

---

← [Anterior](./sistema-consultas-querybuilder.md) | [Índice](./README.md) | [Siguiente →](./soft-delete.md)
