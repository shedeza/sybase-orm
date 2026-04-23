# Documento de Requisitos

## Introducción

Este documento define los requisitos para un Symfony Bundle que implementa un ORM (Object-Relational Mapper) para Sybase ASE, basado en los patrones de arquitectura de Doctrine. El bundle utiliza PDO dblib para la conexión a base de datos y PHP Attributes para la descripción de entidades. El objetivo es proporcionar una capa de abstracción completa que permita a los desarrolladores trabajar con Sybase ASE de forma orientada a objetos, con soporte para mapeo de entidades, gestión de estado, relaciones, herencia, caché, migraciones y características específicas del dialecto Sybase ASE.

## Glosario

- **ORM_Bundle**: El Symfony Bundle que proporciona la funcionalidad completa de mapeo objeto-relacional para Sybase ASE.
- **Entity_Manager**: Componente central que gestiona el ciclo de vida de las entidades, siguiendo el patrón Data Mapper al estilo Doctrine.
- **Unit_of_Work**: Componente que rastrea los cambios en los objetos durante una transacción y envía todas las actualizaciones juntas al hacer commit.
- **Identity_Map**: Componente que garantiza que la misma fila de base de datos se represente por una única instancia de objeto dentro de una sesión.
- **Metadata_Reader**: Componente que lee y procesa los PHP Attributes de las clases de entidad para extraer la configuración de mapeo.
- **Query_Builder**: Componente que construye consultas SQL de forma programática y segura.
- **OQL_Parser**: Componente que analiza y traduce consultas escritas en Object Query Language (OQL) a SQL nativo de Sybase ASE.
- **OQL_Printer**: Componente que formatea objetos de consulta OQL de vuelta a su representación textual.
- **Sybase_Dialect**: Componente que adapta la generación de SQL a la sintaxis y limitaciones específicas de Sybase ASE.
- **Hydrator**: Componente que convierte los arrays resultantes de pdo->fetch() en instancias de clases de entidad.
- **Proxy_Generator**: Componente que genera clases proxy temporales para implementar Lazy Loading.
- **Migration_Manager**: Componente que gestiona la evolución del esquema de base de datos de forma versionada.
- **Cache_Manager**: Componente que gestiona el caché de primer y segundo nivel.
- **Connection_Manager**: Componente que gestiona las conexiones PDO dblib a Sybase ASE.
- **Connection_Url_Parser**: Componente que parsea URLs de conexión estilo DSN (`sybase://user:pass@host:port/db?charset=UTF-8`) en arrays de configuración compatibles con Connection_Manager.
- **Type_Caster**: Componente que convierte tipos de datos entre PHP y Sybase ASE.
- **Hook_Dispatcher**: Componente que ejecuta lógica antes y después de operaciones de persistencia (interceptores/hooks).
- **Inheritance_Handler**: Componente que gestiona las estrategias de herencia de entidades (TPH, TPT, TPC).
- **Entity_Repository**: Componente base que proporciona métodos de consulta comunes (find, findAll, findBy, findOneBy) delegando al Entity_Manager.
- **Entidad**: Clase PHP que representa una tabla de base de datos, decorada con PHP Attributes.
- **Proxy**: Clase generada automáticamente que hereda de una entidad e implementa Lazy Loading.
- **Flush**: Operación que sincroniza todos los cambios rastreados por el Unit_of_Work con la base de datos.
- **Dirty_Checking**: Proceso de detección de propiedades modificadas en una entidad para generar UPDATEs parciales.
- **Schema**: Esquema de base de datos que puede especificarse opcionalmente en el Attribute Entity para generar nombres de tabla calificados (e.g. `billing.invoices`).

## Requisitos

### Requisito 1: Mapeo de Entidades mediante PHP Attributes

**Historia de Usuario:** Como desarrollador, quiero definir el mapeo de mis entidades usando PHP Attributes, para que la configuración de la base de datos esté junto al código de la entidad y sea fácil de mantener.

#### Criterios de Aceptación

1. WHEN un PHP Attribute de tipo Entity se aplica a una clase, THE Metadata_Reader SHALL extraer el nombre de tabla, esquema, columnas y relaciones de dicha clase.
2. WHEN no se especifica un nombre de tabla en el Attribute, THE Metadata_Reader SHALL derivar el nombre de tabla a partir del nombre de la clase usando convención snake_case.
3. WHEN no se especifica un nombre de columna en el Attribute, THE Metadata_Reader SHALL derivar el nombre de columna a partir del nombre de la propiedad usando convención snake_case.
4. WHEN una propiedad se anota con un Attribute de tipo Id sin estrategia de generación explícita, THE Metadata_Reader SHALL asumir generación automática mediante @@identity de Sybase ASE.
5. WHEN una propiedad se anota con un Attribute de tipo Column con un tipo personalizado (enum, Value Object, fecha con zona horaria), THE Type_Caster SHALL registrar la conversión bidireccional entre el tipo PHP y el tipo Sybase ASE correspondiente.
6. THE Metadata_Reader SHALL cachear los metadatos procesados de cada clase de entidad para evitar reprocesamiento en solicitudes posteriores.
7. WHEN se especifica un esquema en el Attribute Entity (e.g. `#[Entity(table: 'invoices', schema: 'billing')]`), THE Metadata_Reader SHALL almacenar el esquema en ClassMetadata y THE ClassMetadata SHALL proporcionar un método `getQualifiedTableName()` que retorne `schema.table` o solo `table` si no hay esquema.
8. WHEN una clase de entidad hereda de otra clase con propiedades privadas anotadas con `#[Column]`, THE Metadata_Reader SHALL incluir dichas propiedades heredadas en los metadatos de la subclase, recorriendo toda la jerarquía de clases.

### Requisito 2: Conversión de Tipos de Datos entre PHP y Sybase ASE

**Historia de Usuario:** Como desarrollador, quiero que el ORM convierta automáticamente los tipos de datos entre PHP y Sybase ASE, para no tener que hacer conversiones manuales en mi código de negocio.

#### Criterios de Aceptación

1. WHEN un valor PHP de tipo bool se persiste, THE Type_Caster SHALL convertirlo al tipo BIT de Sybase ASE usando 1 para true y 0 para false.
2. WHEN un valor PHP de tipo DateTime se persiste, THE Type_Caster SHALL convertirlo al formato Sybase ASE "YYYY-MM-DD HH:MM:SS.mmm".
3. WHEN un valor PHP de tipo enum se persiste, THE Type_Caster SHALL convertirlo al valor escalar correspondiente (string o int) definido en el enum.
4. WHEN un resultado de base de datos se hidrata a una entidad, THE Type_Caster SHALL convertir los valores de columna a los tipos PHP declarados en los Attributes de la entidad.
5. WHEN un desarrollador registra un tipo personalizado (Value Object), THE Type_Caster SHALL utilizar los métodos de conversión definidos por el desarrollador para la transformación bidireccional. THE Type_Caster SHALL cachear las instancias de tipos personalizados para evitar recrearlas en cada conversión.
6. IF un valor de base de datos no puede convertirse al tipo PHP esperado, THEN THE Type_Caster SHALL lanzar una excepción TypeConversionException con el tipo origen, tipo destino y valor problemático.

### Requisito 3: Gestión de Conexión a Sybase ASE

**Historia de Usuario:** Como desarrollador, quiero que el bundle gestione las conexiones PDO dblib a Sybase ASE con la configuración adecuada, para que las consultas se ejecuten correctamente sin configuración manual de cada conexión.

#### Criterios de Aceptación

1. WHEN se establece una nueva conexión a Sybase ASE, THE Connection_Manager SHALL ejecutar "SET ANSINULL ON" y "SET QUOTED_IDENTIFIER ON" automáticamente.
2. THE Connection_Manager SHALL utilizar el driver PDO dblib para todas las conexiones a Sybase ASE.
3. WHEN una sentencia PDO finaliza su uso, THE Connection_Manager SHALL liberar el recurso PDOStatement a la brevedad para respetar la limitación de cursores de Sybase ASE.
4. WHERE se configura el uso de conexiones persistentes, THE Connection_Manager SHALL reutilizar conexiones PDO existentes entre solicitudes.
5. IF la conexión a Sybase ASE se pierde durante una operación, THEN THE Connection_Manager SHALL lanzar una ConnectionLostException con los detalles de la conexión fallida.
6. WHEN se configura el bundle en Symfony, THE Connection_Manager SHALL leer los parámetros de conexión (host, puerto, base de datos, usuario, contraseña, charset) desde la configuración del bundle.
7. IF ocurre un error de base de datos no relacionado con la conexión (syntax error, constraint violation), THEN THE Connection_Manager SHALL lanzar una PersistenceException en lugar de ConnectionLostException.
8. WHEN se proporciona una URL de conexión (e.g. `sybase://user:pass@host:port/database?charset=UTF-8`), THE Connection_Manager SHALL parsear la URL mediante ConnectionUrlParser y usar los parámetros extraídos para establecer la conexión. Los caracteres especiales en el password deben estar URL-encoded.
9. WHEN la URL de conexión se configura mediante `%env(DATABASE_URL)%`, THE SybaseORMExtension SHALL usar un factory method que resuelva la URL en runtime (no en compile-time del contenedor) para compatibilidad con variables de entorno de Symfony.

### Requisito 4: Lenguaje de Consulta de Objetos (OQL)

**Historia de Usuario:** Como desarrollador, quiero escribir consultas usando un lenguaje orientado a objetos (OQL), para que mis consultas referencien entidades y propiedades en lugar de tablas y columnas directamente.

#### Criterios de Aceptación

1. WHEN se recibe una consulta OQL válida, THE OQL_Parser SHALL transformarla en un árbol de sintaxis abstracta (AST) que represente la consulta.
2. WHEN se genera SQL a partir de un AST de OQL, THE Sybase_Dialect SHALL producir SQL compatible con la sintaxis de Sybase ASE.
3. WHEN una consulta OQL referencia propiedades de entidades, THE OQL_Parser SHALL resolver los nombres de tabla y columna correspondientes usando los metadatos del Metadata_Reader.
4. WHEN una consulta OQL incluye parámetros proporcionados por el usuario, THE Query_Builder SHALL parametrizar todos los valores usando sentencias preparadas de PDO para prevenir inyección SQL.
5. THE OQL_Printer SHALL formatear objetos AST de consulta OQL de vuelta a su representación textual OQL válida.
6. PARA TODOS los AST de OQL válidos, parsear la salida del OQL_Printer SHALL producir un AST equivalente al original (propiedad de ida y vuelta).

### Requisito 5: Construcción Programática de Consultas

**Historia de Usuario:** Como desarrollador, quiero construir consultas de forma programática usando un Query Builder, para que pueda componer consultas dinámicas de forma segura y legible.

#### Criterios de Aceptación

1. THE Query_Builder SHALL proporcionar métodos encadenables para las cláusulas SELECT, FROM, WHERE, JOIN, ORDER BY y GROUP BY.
2. WHEN se ejecuta una consulta construida con el Query_Builder, THE Query_Builder SHALL parametrizar automáticamente todos los valores proporcionados por el usuario.
3. WHEN se solicita paginación, THE Sybase_Dialect SHALL generar SQL usando TOP o subconsultas con ROW_NUMBER() en lugar de LIMIT/OFFSET.
4. WHEN se especifica una relación para Eager Loading mediante el método with(), THE Query_Builder SHALL generar JOINs o cláusulas WHERE IN para cargar las relaciones en una sola consulta.
5. WHEN se construye una consulta con múltiples condiciones WHERE, THE Query_Builder SHALL combinarlas usando operadores lógicos AND/OR según lo especificado por el desarrollador.

### Requisito 6: Hidratación de Resultados

**Historia de Usuario:** Como desarrollador, quiero que los resultados de las consultas se conviertan automáticamente en instancias de mis entidades, para trabajar con objetos PHP en lugar de arrays asociativos.

#### Criterios de Aceptación

1. WHEN se ejecuta una consulta que retorna filas de base de datos, THE Hydrator SHALL convertir cada fila en una instancia de la clase de entidad correspondiente.
2. THE Hydrator SHALL utilizar la Reflection API de PHP para asignar valores a propiedades privadas sin requerir herencia de una clase base.
3. WHEN una fila contiene columnas de relaciones cargadas con Eager Loading, THE Hydrator SHALL crear e inyectar las instancias de entidades relacionadas.
4. WHEN una entidad ya existe en el Identity_Map para un identificador dado, THE Hydrator SHALL retornar la instancia existente en lugar de crear una nueva.
5. IF una fila de base de datos contiene columnas que no corresponden a ninguna propiedad mapeada de la entidad, THEN THE Hydrator SHALL ignorar dichas columnas sin lanzar error.

### Requisito 7: Unit of Work y Gestión de Estado

**Historia de Usuario:** Como desarrollador, quiero que el ORM rastree automáticamente los cambios en mis entidades y los persista de forma eficiente, para no tener que escribir SQL manual para cada operación.

#### Criterios de Aceptación

1. WHEN se registra una entidad nueva mediante persist(), THE Unit_of_Work SHALL marcar la entidad como "new" en su registro de cambios.
2. WHEN se solicita la eliminación de una entidad mediante remove(), THE Unit_of_Work SHALL marcar la entidad como "deleted" en su registro de cambios.
3. WHEN se invoca flush(), THE Unit_of_Work SHALL abrir una transacción Sybase ASE, ejecutar los INSERTs primero, luego los UPDATEs, y finalmente los DELETEs, y hacer COMMIT al finalizar.
4. IF ocurre un error durante flush(), THEN THE Unit_of_Work SHALL ejecutar ROLLBACK de la transacción y lanzar una PersistenceException con los detalles del error.
5. THE Unit_of_Work SHALL utilizar Dirty_Checking para detectar propiedades modificadas y generar sentencias UPDATE que afecten únicamente las columnas cambiadas.
6. WHEN se solicita una entidad por su identificador y dicha entidad ya fue cargada en la sesión actual, THE Identity_Map SHALL retornar la misma instancia de objeto.
7. WHEN se inserta una entidad nueva en Sybase ASE, THE Unit_of_Work SHALL recuperar el identificador generado usando @@identity en la misma conexión inmediatamente después del INSERT.
8. WHEN se inserta una entidad padre con ID generado y existen entidades dependientes con FK hacia ella, THE Unit_of_Work SHALL propagar el ID generado a las columnas FK de las entidades dependientes antes de ejecutar sus INSERTs.
9. THE Unit_of_Work SHALL cachear instancias de ReflectionProperty para evitar recrearlas en cada operación de snapshot, changeset y persistencia.
10. IF se detecta una dependencia circular entre entidades durante el ordenamiento para INSERT, THEN THE Unit_of_Work SHALL lanzar una PersistenceException indicando la clase involucrada en el ciclo.
11. THE Unit_of_Work SHALL disparar los hooks PostPersist después de cada INSERT, PreUpdate y PostUpdate alrededor de cada UPDATE, y PostRemove después de cada DELETE, delegando al Hook_Dispatcher.

### Requisito 8: Gestión de Relaciones entre Entidades

**Historia de Usuario:** Como desarrollador, quiero definir relaciones entre mis entidades (1:1, 1:N, N:M), para que el ORM gestione automáticamente las claves foráneas y tablas intermedias.

#### Criterios de Aceptación

1. WHEN se define una relación OneToOne mediante Attribute, THE Entity_Manager SHALL gestionar la clave foránea correspondiente en la tabla propietaria.
2. WHEN se define una relación OneToMany mediante Attribute, THE Entity_Manager SHALL gestionar la clave foránea en la tabla del lado "muchos".
3. WHEN se define una relación ManyToMany mediante Attribute, THE Entity_Manager SHALL gestionar automáticamente la tabla intermedia con las claves foráneas de ambas entidades.
4. WHEN se accede a una propiedad de relación configurada como Lazy Loading, THE Proxy_Generator SHALL cargar los datos relacionados desde la base de datos en ese momento.
5. WHEN se especifica Eager Loading para una relación mediante with(), THE Query_Builder SHALL cargar los datos relacionados usando JOIN o WHERE IN para evitar el problema N+1.
6. WHEN se persiste una entidad con relaciones en cascada, THE Unit_of_Work SHALL persistir las entidades relacionadas en el orden correcto respetando las dependencias de claves foráneas.

### Requisito 9: Estrategias de Herencia de Entidades

**Historia de Usuario:** Como desarrollador, quiero usar herencia en mis entidades y que el ORM la mapee correctamente a tablas de base de datos, para reutilizar código y modelar jerarquías de dominio.

#### Criterios de Aceptación

1. WHEN se configura la estrategia Table Per Hierarchy (TPH), THE Entity_Manager SHALL almacenar todas las clases de la jerarquía en una sola tabla con una columna discriminadora.
2. WHEN se configura la estrategia Table Per Type (TPT), THE Entity_Manager SHALL crear una tabla para la clase base y una tabla adicional por cada clase derivada, unidas por clave primaria.
3. WHEN se configura la estrategia Table Per Concrete Class (TPC), THE Entity_Manager SHALL crear una tabla independiente por cada clase concreta con todas las columnas heredadas.
4. WHEN se consulta la clase base en una jerarquía TPH, THE Hydrator SHALL usar el valor de la columna discriminadora para instanciar la subclase correcta.
5. WHEN se consulta la clase base en una jerarquía TPT, THE Query_Builder SHALL generar JOINs automáticos entre la tabla base y las tablas derivadas.

### Requisito 10: Generación de Proxies para Lazy Loading

**Historia de Usuario:** Como desarrollador, quiero que las relaciones se carguen bajo demanda sin que yo tenga que gestionar la carga manual, para que mi aplicación sea eficiente en el uso de recursos.

#### Criterios de Aceptación

1. THE Proxy_Generator SHALL generar clases que hereden de la entidad original e intercepten el acceso a propiedades para disparar la carga de datos.
2. WHEN se accede a un getter de una propiedad no inicializada en un Proxy, THE Proxy SHALL ejecutar la consulta correspondiente para cargar los datos desde la base de datos.
3. WHEN se serializa un Proxy, THE Proxy SHALL cargar todos los datos pendientes antes de la serialización para garantizar la integridad del objeto.
4. THE Proxy_Generator SHALL generar los proxies en un directorio configurable y cachearlos para evitar regeneración en cada solicitud.
5. WHEN se inicializa un Proxy, THE Proxy SHALL registrarse en el Identity_Map para mantener la consistencia de identidad de objetos.

### Requisito 11: Dialecto SQL para Sybase ASE

**Historia de Usuario:** Como desarrollador, quiero que el ORM genere SQL compatible con Sybase ASE automáticamente, para no tener que preocuparme por las diferencias de sintaxis entre motores de base de datos.

#### Criterios de Aceptación

1. WHEN se requiere paginación, THE Sybase_Dialect SHALL generar consultas usando TOP para la primera página o subconsultas con ROW_NUMBER() para páginas subsiguientes, en lugar de LIMIT/OFFSET.
2. WHEN se genera un INSERT para una tabla con columna identity, THE Sybase_Dialect SHALL omitir la columna identity de la lista de columnas del INSERT.
3. WHEN se necesita obtener el último identificador generado, THE Sybase_Dialect SHALL usar SELECT @@identity en la misma conexión inmediatamente después del INSERT.
4. THE Sybase_Dialect SHALL generar identificadores de tabla y columna compatibles con la sintaxis de Sybase ASE, usando corchetes (e.g. `[tabla]`, `[columna]`).
5. WHEN se genera SQL para valores NULL, THE Sybase_Dialect SHALL respetar el comportamiento configurado por SET ANSINULL ON.
6. THE Sybase_Dialect SHALL proporcionar una interfaz que permita reemplazarlo por otro dialecto (PostgreSQL, SQL Server) sin modificar el código de negocio.
7. WHEN se recibe un identificador con esquema (e.g. `billing.invoices`), THE Sybase_Dialect SHALL quotear cada segmento por separado produciendo `[billing].[invoices]`.

### Requisito 12: Sistema de Migraciones

**Historia de Usuario:** Como desarrollador, quiero gestionar la evolución del esquema de base de datos de forma versionada, para que los cambios de esquema sean reproducibles y rastreables.

#### Criterios de Aceptación

1. WHEN se detectan diferencias entre los metadatos de las entidades y el esquema actual de la base de datos, THE Migration_Manager SHALL generar un archivo de migración con las sentencias SQL necesarias para sincronizar el esquema.
2. WHEN se ejecuta una migración, THE Migration_Manager SHALL registrar la versión ejecutada en una tabla de control de migraciones en la base de datos.
3. WHEN se solicita revertir una migración, THE Migration_Manager SHALL ejecutar las sentencias SQL inversas para volver al estado anterior del esquema.
4. THE Migration_Manager SHALL generar sentencias SQL compatibles con la sintaxis de Sybase ASE usando el Sybase_Dialect.
5. IF una migración falla durante su ejecución, THEN THE Migration_Manager SHALL ejecutar ROLLBACK y reportar el error con el número de migración y la sentencia SQL que falló.

### Requisito 13: Sistema de Caché de Primer y Segundo Nivel

**Historia de Usuario:** Como desarrollador, quiero que el ORM utilice caché para reducir consultas repetitivas a la base de datos, para mejorar el rendimiento de mi aplicación.

#### Criterios de Aceptación

1. THE Cache_Manager SHALL mantener un caché de primer nivel (Identity_Map) que almacene las entidades cargadas durante la sesión actual del Entity_Manager.
2. WHEN se solicita una entidad por su identificador y existe en el caché de primer nivel, THE Cache_Manager SHALL retornar la instancia cacheada sin ejecutar consulta a la base de datos.
3. WHERE se configura caché de segundo nivel (Redis u otro adaptador), THE Cache_Manager SHALL almacenar resultados de consultas frecuentes compartidos entre sesiones de distintos usuarios.
4. WHEN se modifica una entidad y se ejecuta flush(), THE Cache_Manager SHALL invalidar las entradas de caché correspondientes a la entidad modificada en ambos niveles.
5. WHEN se configura el caché de segundo nivel, THE Cache_Manager SHALL permitir definir tiempo de expiración (TTL) por entidad o por consulta.
6. IF el adaptador de caché de segundo nivel no está disponible, THEN THE Cache_Manager SHALL continuar operando únicamente con el caché de primer nivel y registrar una advertencia en el log.

### Requisito 14: Gestión de Transacciones

**Historia de Usuario:** Como desarrollador, quiero gestionar transacciones de base de datos con soporte ACID completo, para garantizar la integridad de los datos en operaciones complejas.

#### Criterios de Aceptación

1. THE Entity_Manager SHALL proporcionar métodos beginTransaction(), commit() y rollback() para gestión explícita de transacciones.
2. WHEN se invoca beginTransaction(), THE Connection_Manager SHALL iniciar una transacción nativa de Sybase ASE.
3. WHEN se invoca commit(), THE Connection_Manager SHALL confirmar la transacción activa en Sybase ASE.
4. WHEN se invoca rollback(), THE Connection_Manager SHALL revertir la transacción activa en Sybase ASE.
5. WHERE se especifica un nivel de aislamiento (READ UNCOMMITTED, READ COMMITTED, REPEATABLE READ, SERIALIZABLE), THE Connection_Manager SHALL configurar el nivel de aislamiento de la transacción en Sybase ASE.
6. IF se invoca commit() o rollback() sin una transacción activa, THEN THE Entity_Manager SHALL lanzar una TransactionException indicando que no hay transacción activa.

### Requisito 15: Hooks e Interceptores de Ciclo de Vida

**Historia de Usuario:** Como desarrollador, quiero ejecutar lógica personalizada antes y después de operaciones de persistencia, para implementar funcionalidades transversales como auditoría de fechas.

#### Criterios de Aceptación

1. WHEN se define un Attribute de tipo PrePersist en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método antes de insertar la entidad en la base de datos.
2. WHEN se define un Attribute de tipo PostPersist en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método después de insertar la entidad en la base de datos.
3. WHEN se define un Attribute de tipo PreUpdate en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método antes de actualizar la entidad en la base de datos.
4. WHEN se define un Attribute de tipo PostUpdate en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método después de actualizar la entidad en la base de datos.
5. WHEN se define un Attribute de tipo PreRemove en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método antes de eliminar la entidad de la base de datos.
6. WHEN se define un Attribute de tipo PostRemove en un método de una entidad, THE Hook_Dispatcher SHALL ejecutar dicho método después de eliminar la entidad de la base de datos.
7. IF un método de hook lanza una excepción, THEN THE Hook_Dispatcher SHALL propagar la excepción y cancelar la operación de persistencia en curso.

### Requisito 16: Integración con Symfony

**Historia de Usuario:** Como desarrollador Symfony, quiero que el ORM se integre nativamente con el framework, para que pueda configurarlo y usarlo siguiendo las convenciones de Symfony.

#### Criterios de Aceptación

1. THE ORM_Bundle SHALL registrarse como un bundle de Symfony y exponer su configuración mediante el sistema de configuración de Symfony (YAML/PHP).
2. THE ORM_Bundle SHALL registrar el Entity_Manager, Connection_Manager y demás servicios en el contenedor de inyección de dependencias de Symfony.
3. WHEN se configura el bundle en config/packages/, THE ORM_Bundle SHALL leer los parámetros de conexión, directorios de entidades, directorio de proxies y configuración de caché.
4. THE ORM_Bundle SHALL permitir inyectar el Entity_Manager en controladores y servicios mediante autowiring de Symfony.
5. WHEN se ejecutan comandos de consola de Symfony, THE ORM_Bundle SHALL registrar comandos para generar migraciones, ejecutar migraciones, generar proxies y limpiar caché.
6. THE ORM_Bundle SHALL incluir una receta Symfony Flex (`manifest.json`) que auto-registre el bundle en `bundles.php`, copie la configuración por defecto a `config/packages/sybase_orm.yaml`, y defina la variable `DATABASE_URL` en `.env`.
