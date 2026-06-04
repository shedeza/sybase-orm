# Mapeo de Entidades

El sistema de mapeo permite definir cómo las clases PHP se corresponden con tablas en Sybase ASE usando atributos nativos de PHP 8.1+. No se requieren archivos XML ni YAML: toda la configuración reside directamente en el código fuente.

## Atributo #[Entity]

Marca una clase como entidad mapeada a una tabla de base de datos.

```php
use SybaseORM\Attribute\Entity;

#[Entity(table: 'productos', schema: 'dbo')]
class Producto
{
    // ...
}
```

### Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `table` | `?string` | `null` | Nombre de la tabla. Si es `null`, se deriva del nombre de clase en snake_case |
| `schema` | `?string` | `null` | Esquema de la tabla (ej: `dbo`) |
| `repositoryClass` | `?string` | `null` | Clase de repositorio personalizado |
| `connection` | `string` | `'default'` | Nombre de la conexión a utilizar |

Si no se especifica `table`, el MetadataReader convierte el nombre de la clase a snake_case automáticamente. Por ejemplo, `OrdenCompra` se mapea a `orden_compra`.

## Atributo #[Column]

Mapea una propiedad a una columna de la tabla.

```php
use SybaseORM\Attribute\Column;

#[Column(name: 'nombre_completo', type: 'string', length: 100, nullable: false)]
public string $nombreCompleto;
```

### Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `name` | `?string` | `null` | Nombre de la columna. Si es `null`, se deriva de la propiedad en snake_case |
| `type` | `string` | `'string'` | Tipo de dato (ver tabla de tipos abajo) |
| `nullable` | `bool` | `false` | Si la columna acepta `NULL` |
| `length` | `?int` | `null` | Longitud máxima (para `string`, `text`) |
| `precision` | `?int` | `null` | Precisión total (para `decimal`, `numeric`) |
| `scale` | `?int` | `null` | Decimales (para `decimal`, `numeric`) |

Cuando no se especifica `name`, el MetadataReader convierte la propiedad usando snake_case: `fechaCreacion` → `fecha_creacion`.

## Identidad: #[Id] y #[GeneratedValue]

Toda entidad requiere al menos una propiedad marcada con `#[Id]` como clave primaria. Para valores autogenerados, se combina con `#[GeneratedValue]`.

```php
use SybaseORM\Attribute\{Entity, Id, GeneratedValue, Column};

#[Entity(table: 'usuarios')]
class Usuario
{
    #[Id(strategy: 'identity')]
    #[GeneratedValue(strategy: 'IDENTITY')]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string', length: 80)]
    public string $email;
}
```

### #[Id] — Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `strategy` | `string` | `'identity'` | Estrategia de generación de ID |

### #[GeneratedValue] — Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `strategy` | `string` | `'IDENTITY'` | Estrategia de generación. Usa `@@identity` de Sybase ASE |

La estrategia `IDENTITY` recupera el ID generado inmediatamente después del INSERT usando `@@identity` de Sybase ASE.

## Tipos de Columna Soportados

| Tipo | PHP | Sybase ASE | Notas |
|------|-----|-----------|-------|
| `integer` | `int` | `INT` | Entero estándar de 32 bits |
| `bigint` | `int` | `BIGINT` | Entero de 64 bits |
| `smallint` | `int` | `SMALLINT` | Entero de 16 bits |
| `tinyint` | `int` | `TINYINT` | Entero de 8 bits (0-255) |
| `string` | `string` | `VARCHAR(length)` | Cadena de longitud variable |
| `text` | `string` | `TEXT` | Texto largo |
| `boolean` | `bool` | `BIT` | Verdadero/falso |
| `datetime` | `string` | `DATETIME` | Fecha y hora |
| `float` | `float` | `FLOAT` | Punto flotante |
| `real` | `float` | `REAL` | Punto flotante de precisión simple |
| `decimal` | `string` | `DECIMAL(p,s)` | Decimal exacto con precisión y escala |
| `numeric` | `string` | `NUMERIC(p,s)` | Equivalente a decimal |

```php
#[Column(type: 'decimal', precision: 10, scale: 2)]
public string $precio;

#[Column(type: 'bigint')]
public int $visitas;

#[Column(type: 'boolean')]
public bool $activo;
```

## BackedEnum como Tipo de Columna

El ORM soporta `BackedEnum` de PHP 8.1 como tipo de columna. Se almacena el valor escalar del enum y se rehidrata automáticamente al tipo enum correspondiente.

```php
enum EstadoPedido: string
{
    case Pendiente = 'pendiente';
    case Procesando = 'procesando';
    case Completado = 'completado';
    case Cancelado = 'cancelado';
}

#[Entity(table: 'pedidos')]
class Pedido
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: EstadoPedido::class)]
    public EstadoPedido $estado = EstadoPedido::Pendiente;
}
```

Para usar un `BackedEnum`, se indica el FQCN de la clase enum como valor del parámetro `type` en `#[Column]`. El ORM persiste `$estado->value` en la base de datos y reconstruye la instancia del enum al hidratar.

## Tipos Personalizados (CustomTypeInterface)

Cuando los tipos incorporados no son suficientes, se puede implementar `CustomTypeInterface` para definir conversiones bidireccionales entre PHP y la base de datos.

```php
use SybaseORM\Type\CustomTypeInterface;

class MoneyType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        // Money value object → entero en centavos
        return $value instanceof Money ? $value->getCents() : (int) $value;
    }

    public function toPhpValue(mixed $value): mixed
    {
        // Entero en centavos → Money value object
        return new Money((int) $value);
    }
}
```

### Registro del tipo personalizado

Se registra mediante el `TypeCaster` accesible desde el `EntityManager`:

```php
$em->getTypeCaster()->registerType('money', MoneyType::class);
```

Luego se usa en las columnas:

```php
#[Column(type: 'money')]
public Money $precio;
```

La clase del tipo debe implementar `SybaseORM\Type\CustomTypeInterface` con los métodos `toDatabaseValue()` y `toPhpValue()`.

## Value Objects: #[Embeddable] y #[Embedded]

Los embeddables permiten extraer un grupo de columnas a una clase reutilizable (value object) sin crear una tabla separada. Las propiedades del embeddable se almacenan como columnas en la tabla de la entidad padre, con un prefijo automático.

### Definir un Embeddable

```php
use SybaseORM\Attribute\{Embeddable, Column};

#[Embeddable]
class Address
{
    #[Column(type: 'string', length: 150)]
    public string $street = '';

    #[Column(type: 'string', length: 80)]
    public string $city = '';

    #[Column(type: 'string', length: 10)]
    public string $zipCode = '';
}
```

### Usar un Embedded en una Entidad

```php
use SybaseORM\Attribute\{Entity, Id, GeneratedValue, Column, Embedded};

#[Entity(table: 'users')]
class User
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string', length: 100)]
    public string $name;

    #[Embedded(class: Address::class)]
    public Address $address;
}
```

### #[Embedded] — Parámetros

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `class` | `string` | — (requerido) | FQCN de la clase embeddable |
| `columnPrefix` | `?string` | `null` | Prefijo de columnas. Si es `null`, usa el nombre de la propiedad + `_` |

En el ejemplo anterior, las columnas resultantes en la tabla `users` son:

| Propiedad | Columna en BD |
|-----------|---------------|
| `$address->street` | `address_street` |
| `$address->city` | `address_city` |
| `$address->zipCode` | `address_zip_code` |

Se puede personalizar el prefijo:

```php
#[Embedded(class: Address::class, columnPrefix: 'dir_')]
public Address $address;
// Columnas: dir_street, dir_city, dir_zip_code
```

---

← [Anterior](./configuracion-conexion.md) | [Índice](./README.md) | [Siguiente →](./relaciones.md)
