# Extensión: Tipos Personalizados

Los tipos personalizados permiten definir conversiones bidireccionales entre valores PHP y su representación en Sybase ASE. Esto es útil para Value Objects, formatos especiales o tipos de dato no soportados nativamente.

## CustomTypeInterface

La interfaz que deben implementar todos los tipos personalizados:

```php
namespace SybaseORM\Type;

interface CustomTypeInterface
{
    /**
     * Convierte un valor PHP al formato de base de datos.
     */
    public function toDatabaseValue(mixed $value): mixed;

    /**
     * Convierte un valor de base de datos al tipo PHP correspondiente.
     */
    public function toPhpValue(mixed $value): mixed;
}
```

## Registro de Tipos

Los tipos personalizados se registran en el `TypeCaster` mediante `registerType()`:

```php
use SybaseORM\Type\TypeCasterInterface;

/** @var TypeCasterInterface $typeCaster */
$typeCaster = $em->getTypeCaster();

// Registrar un tipo personalizado
$typeCaster->registerType('money', MoneyType::class);
$typeCaster->registerType('json', JsonType::class);
$typeCaster->registerType('coordenadas', CoordenadasType::class);
```

**Requisitos del registro:**
- La clase debe implementar `CustomTypeInterface`
- Si no la implementa, se lanza `\InvalidArgumentException`
- El nombre del tipo se usa en el atributo `#[Column(type: 'nombre')]`
- Las instancias se cachean internamente (se crean una sola vez)

## Uso en Entidades

Una vez registrado, el tipo se referencia en el mapeo de columnas:

```php
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Id;

#[Entity(table: 'productos')]
class Producto
{
    #[Id]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'string')]
    public string $nombre;

    #[Column(type: 'money')]
    public Money $precio;

    #[Column(type: 'json', nullable: true)]
    public ?array $metadatos = null;
}
```

## Ejemplo Completo: Tipo Money

Un Value Object que almacena valores monetarios como enteros (centavos) en la base de datos:

### Clase Value Object

```php
class Money
{
    public function __construct(
        public readonly int $centavos,
        public readonly string $moneda = 'COP',
    ) {}

    public function getValor(): float
    {
        return $this->centavos / 100;
    }

    public function __toString(): string
    {
        return number_format($this->centavos / 100, 2) . ' ' . $this->moneda;
    }
}
```

### Implementación del tipo

```php
use SybaseORM\Type\CustomTypeInterface;

class MoneyType implements CustomTypeInterface
{
    /**
     * Convierte Money a entero para almacenar en DB.
     */
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->centavos;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(
            'MoneyType espera un objeto Money o un entero, recibió: ' . get_debug_type($value)
        );
    }

    /**
     * Convierte entero de DB a objeto Money.
     */
    public function toPhpValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return new Money(centavos: (int) $value);
    }
}
```

### Registro y uso

```php
// Al configurar el EntityManager
$typeCaster = $em->getTypeCaster();
$typeCaster->registerType('money', MoneyType::class);

// Persistir
$producto = new Producto();
$producto->nombre = 'Widget';
$producto->precio = new Money(centavos: 15990, moneda: 'COP');
$em->persist($producto);
$em->flush();
// INSERT INTO productos (nombre, precio) VALUES ('Widget', 15990)

// Leer
$producto = $em->find(Producto::class, 1);
echo $producto->precio; // "159.90 COP"
echo $producto->precio->centavos; // 15990
```

## Ejemplo: Tipo JSON

Almacena arrays PHP como texto JSON en Sybase ASE:

```php
use SybaseORM\Type\CustomTypeInterface;

class JsonType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \InvalidArgumentException(
                'No se pudo serializar a JSON: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    public function toPhpValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'JSON inválido en base de datos: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }
}
```

## SqlWrappingTypeInterface

Para tipos que necesitan envolver la expresión SQL en una función de conversión (por ejemplo, para que Sybase ASE acepte el tipo correctamente):

```php
namespace SybaseORM\Type;

interface SqlWrappingTypeInterface
{
    /**
     * Envuelve una expresión SQL para conversión al tipo de base de datos.
     * Ejemplo: CONVERT(MONEY, $sqlExpr)
     */
    public function convertToDatabaseValueSQL(string $sqlExpr): string;
}
```

### Ejemplo con SQL wrapping

```php
use SybaseORM\Type\CustomTypeInterface;
use SybaseORM\Type\SqlWrappingTypeInterface;

class SybaseMoneyType implements CustomTypeInterface, SqlWrappingTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return (string) ($value instanceof Money ? $value->centavos / 100 : $value);
    }

    public function toPhpValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return new Money(centavos: (int) (((float) $value) * 100));
    }

    public function convertToDatabaseValueSQL(string $sqlExpr): string
    {
        return "CONVERT(MONEY, {$sqlExpr})";
    }
}
```

## Verificación de Tipos Registrados

```php
$typeCaster = $em->getTypeCaster();

// Verificar si un tipo está registrado
if ($typeCaster->isRegisteredType('money')) {
    echo "Tipo 'money' disponible\n";
}

// Verificar si es un tipo built-in
if ($typeCaster->isBuiltinType('datetime')) {
    echo "'datetime' es built-in\n";
}

// Listar tipos registrados
$nombres = $typeCaster->getRegisteredTypeNames();
```

## Manejo de Errores

Si la conversión falla, se lanza `TypeConversionException`:

```php
use SybaseORM\Exception\TypeConversionException;

try {
    $em->flush();
} catch (TypeConversionException $e) {
    echo "Error de conversión: " . $e->getMessage() . "\n";
    echo "Tipo origen: " . $e->getSourceType() . "\n";
    echo "Tipo destino: " . $e->getTargetType() . "\n";
}
```

---

← [Anterior](./patron-repository.md) | [Índice](./README.md) | [Siguiente →](./extension-event-subscribers.md)
