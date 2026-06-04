# Extensión: Event Subscribers

Los Event Subscribers permiten implementar lógica transversal (auditoría, logging, notificaciones) que se ejecuta en respuesta a eventos del ciclo de vida de entidades, sin modificar las clases de entidad.

## EventSubscriberInterface

La interfaz que deben implementar todos los subscribers externos:

```php
namespace SybaseORM\Hook;

interface EventSubscriberInterface
{
    /**
     * Retorna los tipos de hook a los que está suscrito.
     *
     * @return string[] Ejemplo: ['PrePersist', 'PostUpdate', 'PostRemove']
     */
    public function getSubscribedEvents(): array;

    /**
     * Invocado cuando ocurre un evento suscrito.
     *
     * @param object $entity   La instancia de la entidad
     * @param string $hookType El tipo de hook (ej: 'PrePersist')
     */
    public function onEvent(object $entity, string $hookType): void;
}
```

## Eventos Disponibles

El ORM soporta los siguientes tipos de hooks:

| Evento | Momento | Uso típico |
|--------|---------|------------|
| `PrePersist` | Antes de insertar una entidad nueva | Validación, asignar timestamps |
| `PostPersist` | Después de insertar (ID ya asignado) | Notificaciones, auditoría |
| `PreUpdate` | Antes de actualizar una entidad | Validación, actualizar `updated_at` |
| `PostUpdate` | Después de actualizar | Notificaciones, recalcular agregados |
| `PreRemove` | Antes de eliminar una entidad | Validación, limpieza de recursos |
| `PostRemove` | Después de eliminar | Auditoría, notificaciones |

Estos eventos se obtienen programáticamente con:

```php
use SybaseORM\Hook\HookDispatcher;

$eventos = HookDispatcher::getSupportedHookTypes();
// ['PrePersist', 'PostPersist', 'PreUpdate', 'PostUpdate', 'PreRemove', 'PostRemove']
```

## Registro de Subscribers

Los subscribers se registran en el `HookDispatcher` mediante `addSubscriber()`:

```php
use SybaseORM\Hook\HookDispatcher;

/** @var HookDispatcher $hookDispatcher */
$hookDispatcher->addSubscriber(new AuditSubscriber());
$hookDispatcher->addSubscriber(new NotificacionSubscriber());
```

Para obtener los subscribers registrados:

```php
$subscribers = $hookDispatcher->getSubscribers();
```

## Ejemplo: Subscriber de Auditoría

```php
use SybaseORM\Hook\EventSubscriberInterface;

class AuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {}

    public function getSubscribedEvents(): array
    {
        return ['PostPersist', 'PostUpdate', 'PostRemove'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        $entityClass = $entity::class;
        $entityId = method_exists($entity, 'getId') ? $entity->getId() : 'unknown';

        $this->logger->info("Auditoría: {$hookType} en {$entityClass}#{$entityId}");
    }
}
```

## Ejemplo: Subscriber de Timestamps

```php
use SybaseORM\Hook\EventSubscriberInterface;

class TimestampSubscriber implements EventSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return ['PrePersist', 'PreUpdate'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        $now = new \DateTime();

        if ($hookType === 'PrePersist' && property_exists($entity, 'createdAt')) {
            $entity->createdAt = $now;
        }

        if (property_exists($entity, 'updatedAt')) {
            $entity->updatedAt = $now;
        }
    }
}
```

## Ejemplo: Subscriber de Notificaciones

```php
use SybaseORM\Hook\EventSubscriberInterface;

class NotificacionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function getSubscribedEvents(): array
    {
        return ['PostPersist'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        if ($entity instanceof Orden && $hookType === 'PostPersist') {
            $this->mailer->enviar(
                destinatario: $entity->cliente->email,
                asunto: "Orden #{$entity->id} creada",
                cuerpo: "Tu orden ha sido registrada."
            );
        }
    }
}
```

## Diferencia con Hooks de Entidad

| Aspecto | Hooks de Entidad (`#[PrePersist]`) | Event Subscribers |
|---------|-------------------------------------|-------------------|
| Ubicación | Dentro de la entidad | Clase externa separada |
| Scope | Solo esa entidad | Todas las entidades |
| Registro | Automático con `#[HasLifecycleHooks]` | Manual con `addSubscriber()` |
| Uso típico | Lógica de negocio de la entidad | Concerns transversales |

## Integración con Symfony EventDispatcher

El ORM incluye `SymfonyEventDispatcherSubscriber` que actúa como puente entre los hooks del ORM y el EventDispatcher de Symfony (PSR-14):

```php
use SybaseORM\Hook\SymfonyEventDispatcherSubscriber;
use Psr\EventDispatcher\EventDispatcherInterface;

// $eventDispatcher es tu instancia de Symfony EventDispatcher
$symfonyBridge = new SymfonyEventDispatcherSubscriber($eventDispatcher);
$hookDispatcher->addSubscriber($symfonyBridge);
```

### Eventos despachados

El bridge despacha eventos `PostPersist`, `PostUpdate` y `PostRemove` como instancias de `EntityChangedEvent`:

```php
namespace SybaseORM\Hook;

final class EntityChangedEvent
{
    public function __construct(
        public readonly object $entity,      // La entidad afectada
        public readonly string $entityClass,  // FQCN de la entidad
        public readonly string $hookType,     // 'PostPersist', 'PostUpdate', 'PostRemove'
    ) {}
}
```

### Listener de Symfony

```php
use SybaseORM\Hook\EntityChangedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: EntityChangedEvent::class)]
class RecalcularConsecutivosListener
{
    public function __invoke(EntityChangedEvent $event): void
    {
        if ($event->entityClass === Actividad::class && $event->hookType === 'PostPersist') {
            // Recalcular consecutivos...
        }
    }
}
```

### Configuración completa con Symfony

```php
use Symfony\Component\EventDispatcher\EventDispatcher;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hook\SymfonyEventDispatcherSubscriber;

// 1. Crear el EventDispatcher de Symfony
$symfonyDispatcher = new EventDispatcher();
$symfonyDispatcher->addListener(EntityChangedEvent::class, new RecalcularConsecutivosListener());
$symfonyDispatcher->addListener(EntityChangedEvent::class, new EnviarNotificacionListener());

// 2. Crear el bridge
$bridge = new SymfonyEventDispatcherSubscriber($symfonyDispatcher);

// 3. Registrar en el HookDispatcher del ORM
$hookDispatcher->addSubscriber($bridge);

// Ahora, cuando se ejecute flush() y se persista/actualice/elimine una entidad:
// ORM Hook → SymfonyEventDispatcherSubscriber → EntityChangedEvent → Listeners de Symfony
```

## Orden de Ejecución

1. Se ejecutan los métodos de hook de la entidad (si tiene `#[HasLifecycleHooks]`)
2. Se notifica a cada subscriber externo registrado en orden de adición
3. Si un hook o subscriber lanza una excepción, se propaga sin capturar (puede cancelar la operación en hooks `Pre*`)

## Consideraciones

- Los subscribers `Pre*` pueden lanzar excepciones para cancelar la operación
- Los subscribers `Post*` se ejecutan después del commit de la operación individual (pero dentro del flush general)
- No modifiques otras entidades en subscribers `Post*` sin cuidado: puede generar bucles
- El HookDispatcher no garantiza orden entre subscribers del mismo evento

---

← [Anterior](./extension-tipos-personalizados.md) | [Índice](./README.md) | [Siguiente →](./extension-funciones-oql.md)
