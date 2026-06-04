# Herencia de Entidades

El ORM soporta herencia de entidades, permitiendo modelar jerarquías de clases PHP que se persisten en base de datos. Esto facilita la representación de conceptos como "un Empleado y un Cliente son ambos Persona" directamente en el modelo de dominio, con mapeo transparente a tablas Sybase ASE.

## Estrategias de Herencia

El atributo `#[InheritanceType]` define la estrategia de mapeo. Se aplica sobre la clase raíz de la jerarquía:

```php
use SybaseORM\Attribute\InheritanceType;

#[InheritanceType(strategy: 'TPH')]
class Animal { /* ... */ }
```

### Estrategias soportadas

| Estrategia | Nombre completo | Descripción |
|-----------|-----------------|-------------|
| `TPH` | Table Per Hierarchy | Una sola tabla para toda la jerarquía, con columna discriminadora |
| `TPT` | Table Per Type | Tabla base + una tabla por subclase, unidas por clave primaria |
| `TPC` | Table Per Concrete Class | Tabla independiente por cada clase concreta |

La estrategia más común y recomendada para jerarquías simples es **TPH** (Single Table Inheritance), ya que evita JOINs y es la más eficiente en lectura.

## Columna Discriminadora

El atributo `#[DiscriminatorColumn]` define la columna que almacena el tipo de cada fila:

```php
use SybaseORM\Attribute\DiscriminatorColumn;

#[DiscriminatorColumn(name: 'tipo', type: 'string')]
class Animal { /* ... */ }
```

### Parámetros

| Parámetro | Tipo | Por defecto | Descripción |
|-----------|------|-------------|-------------|
| `name` | `string` | *(requerido)* | Nombre de la columna en la tabla |
| `type` | `string` | `'string'` | Tipo de dato de la columna |

La columna discriminadora no se mapea como propiedad de la entidad; el ORM la gestiona automáticamente durante las operaciones de persistencia y consulta.

## Mapa Discriminador

El atributo `#[DiscriminatorMap]` asocia valores de la columna discriminadora con clases concretas:

```php
use SybaseORM\Attribute\DiscriminatorMap;

#[DiscriminatorMap(map: [
    'perro' => Dog::class,
    'gato'  => Cat::class,
    'ave'   => Bird::class,
])]
class Animal { /* ... */ }
```

### Parámetros

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `map` | `array<string, string>` | Mapa de valor discriminador → FQCN de la clase concreta |

Cada valor del mapa es un string que se almacena en la columna discriminadora. Las claves del array se utilizan para resolver qué clase PHP instanciar al leer una fila.

## Ejemplo Completo: Herencia Single-Table (TPH)

A continuación se muestra un ejemplo completo de jerarquía de entidades usando la estrategia TPH con una tabla `notifications`:

### Clase padre

```php
<?php

namespace App\Entity;

use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\InheritanceType;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;

#[Entity(table: 'notifications')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
#[DiscriminatorMap(map: [
    'email' => EmailNotification::class,
    'sms'   => SmsNotification::class,
    'push'  => PushNotification::class,
])]
class Notification
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private ?int $id = null;

    #[Column(type: 'string', length: 255)]
    private string $message;

    #[Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
```

### Clases hijas

```php
#[Entity(table: 'notifications')]
class EmailNotification extends Notification
{
    #[Column(type: 'string', length: 255, nullable: true)]
    private ?string $emailAddress = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(string $email): void
    {
        $this->emailAddress = $email;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }
}
```

```php
#[Entity(table: 'notifications')]
class SmsNotification extends Notification
{
    #[Column(type: 'string', length: 20, nullable: true)]
    private ?string $phoneNumber = null;

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phone): void
    {
        $this->phoneNumber = $phone;
    }
}
```

```php
#[Entity(table: 'notifications')]
class PushNotification extends Notification
{
    #[Column(type: 'string', length: 255, nullable: true)]
    private ?string $deviceToken = null;

    public function getDeviceToken(): ?string
    {
        return $this->deviceToken;
    }

    public function setDeviceToken(string $token): void
    {
        $this->deviceToken = $token;
    }
}
```

### Estructura de la tabla resultante

Con TPH, todas las subclases comparten una sola tabla:

```
notifications
├── id          (integer, PK, IDENTITY)
├── type        (string) ← columna discriminadora
├── message     (string)
├── created_at  (datetime)
├── email_address (string, nullable)  ← solo EmailNotification
├── subject       (string, nullable)  ← solo EmailNotification
├── phone_number  (string, nullable)  ← solo SmsNotification
├── device_token  (string, nullable)  ← solo PushNotification
```

Las columnas específicas de cada subclase son `nullable` porque no aplican a todas las filas.

### Uso

```php
// Persistir una notificación email
$notification = new EmailNotification();
$notification->setMessage('Bienvenido al sistema');
$notification->setEmailAddress('usuario@ejemplo.com');
$notification->setSubject('Registro exitoso');

$em->persist($notification);
$em->flush();
// El ORM inserta automáticamente 'email' en la columna 'type'

// Consultar todas las notificaciones (devuelve instancias mixtas)
$todas = $em->getRepository(Notification::class)->findAll();

// Consultar solo notificaciones SMS
$sms = $em->getRepository(SmsNotification::class)->findAll();
```

## Hidratación: Resolución de la Clase Concreta

Cuando el ORM lee filas de una tabla con herencia TPH, el `InheritanceHandler` determina qué clase instanciar siguiendo este proceso:

1. **Lee la columna discriminadora** — Obtiene el valor de la columna definida en `#[DiscriminatorColumn]` para cada fila del resultado.

2. **Busca en el mapa** — Compara el valor contra las entradas del `#[DiscriminatorMap]`. Si encuentra una coincidencia, usa la clase asociada.

3. **Fallback a la clase base** — Si el valor discriminador es `null` o no se encuentra en el mapa, el ORM instancia la clase padre (raíz de la jerarquía).

4. **Hidrata la instancia** — Una vez determinada la clase concreta, el Hydrator crea una instancia de esa clase y asigna las propiedades mapeadas.

```
┌─────────────────────────────────────────────────────┐
│                 Fila de base de datos                │
│  id=1, type='email', message='...', email='...'     │
└──────────────────────────┬──────────────────────────┘
                           │
                    ┌──────▼──────┐
                    │ Leer columna │
                    │ 'type'       │
                    └──────┬──────┘
                           │ valor = 'email'
                    ┌──────▼──────────────┐
                    │ Buscar en           │
                    │ discriminatorMap     │
                    │ 'email' → EmailNot. │
                    └──────┬──────────────┘
                           │
                    ┌──────▼───────────────┐
                    │ Instanciar           │
                    │ EmailNotification    │
                    │ y asignar propiedades│
                    └──────────────────────┘
```

### Inserción automática del discriminador

Al persistir una entidad, el ORM determina automáticamente el valor discriminador. Busca en el mapa inverso (clase → valor) y lo inserta en la columna discriminadora sin intervención del desarrollador.

## Consideraciones

- **Columnas nullable**: En TPH, las columnas específicas de subclases deben ser `nullable` porque no todas las filas las utilizan.
- **Rendimiento**: TPH es la estrategia más eficiente para lectura ya que no requiere JOINs.
- **TPT y TPC**: Para jerarquías complejas con muchas columnas por subclase, considere TPT (tabla por tipo con JOINs) o TPC (tabla independiente por clase concreta).
- **Consultas polimórficas**: Consultar por la clase padre devuelve instancias de todas las subclases, cada una correctamente tipada.

---

← [Anterior](./soft-delete.md) | [Índice](./README.md) | [Siguiente →](./sistema-cache.md)
