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

### Requisito 17: Claves Primarias Compuestas — Metadatos

**Historia de Usuario:** Como desarrollador mapeando entidades con claves compuestas, quiero anotar múltiples propiedades con `#[Id]` en una sola entidad, para que el ORM reconozca claves primarias compuestas.

#### Criterios de Aceptación

1. WHEN el MetadataReader encuentra múltiples propiedades anotadas con `#[Id]` en una clase de entidad, THE MetadataReader SHALL recopilar todos los nombres de propiedad anotados en un array almacenado en `ClassMetadata.$idFields`
2. WHEN el MetadataReader encuentra exactamente una propiedad anotada con `#[Id]`, THE MetadataReader SHALL almacenar ese nombre de propiedad en `ClassMetadata.$idFields` como un array de un solo elemento
3. THE ClassMetadata SHALL proporcionar un método `getIdColumns()` que retorne un array de objetos ColumnMetadata para todos los campos de clave primaria
4. THE ClassMetadata SHALL continuar proporcionando el método existente `getIdColumn()`, retornando la primera columna Id para compatibilidad con entidades de clave simple
5. WHEN se define una sola propiedad `#[Id]`, THE ClassMetadata `$idField` property SHALL permanecer disponible y retornar ese nombre de propiedad para compatibilidad

### Requisito 18: Claves Primarias Compuestas — Persistencia

**Historia de Usuario:** Como desarrollador persistiendo entidades con claves compuestas, quiero que el UnitOfWork genere cláusulas WHERE correctas usando todas las columnas de clave, para que las operaciones UPDATE y DELETE apunten a la fila correcta.

#### Criterios de Aceptación

1. WHEN el UnitOfWork ejecuta un UPDATE para una entidad con clave primaria compuesta, THE UnitOfWork SHALL construir una cláusula WHERE que incluya todas las columnas de clave primaria unidas con AND
2. WHEN el UnitOfWork ejecuta un DELETE para una entidad con clave primaria compuesta, THE UnitOfWork SHALL construir una cláusula WHERE que incluya todas las columnas de clave primaria unidas con AND
3. WHEN el UnitOfWork ejecuta un UPDATE para una entidad con clave primaria simple, THE UnitOfWork SHALL continuar construyendo una cláusula WHERE con una sola columna de clave
4. WHEN el UnitOfWork ejecuta un DELETE para una entidad con clave primaria simple, THE UnitOfWork SHALL continuar construyendo una cláusula WHERE con una sola columna de clave

### Requisito 19: Claves Primarias Compuestas — Identity Map

**Historia de Usuario:** Como desarrollador trabajando con entidades de clave compuesta, quiero que el IdentityMap almacene y recupere correctamente entidades usando claves compuestas, para que la identidad de objetos esté garantizada dentro de una sesión.

#### Criterios de Aceptación

1. WHEN el IdentityMap almacena una entidad con clave primaria compuesta, THE IdentityMap SHALL usar un string determinístico derivado de todos los valores de clave como clave del mapa
2. WHEN el IdentityMap recibe una búsqueda de clave compuesta via `get()`, THE IdentityMap SHALL retornar la entidad que coincida con todos los valores de clave
3. WHEN el IdentityMap recibe una clave escalar para una entidad de clave simple, THE IdentityMap SHALL continuar funcionando idénticamente al comportamiento actual
4. THE Hydrator SHALL resolver entidades del IdentityMap usando todos los valores de columna de clave primaria cuando la entidad tiene clave primaria compuesta
5. THE Hydrator SHALL almacenar entidades en el IdentityMap usando todos los valores de columna de clave primaria cuando la entidad tiene clave primaria compuesta

### Requisito 20: Claves Primarias Compuestas en EntityManager::find()

**Historia de Usuario:** Como desarrollador consultando entidades por clave compuesta, quiero que `EntityManager::find()` acepte un array asociativo de valores de clave, para poder buscar entidades con claves primarias de múltiples columnas.

#### Criterios de Aceptación

1. WHEN `EntityManager::find()` recibe un array asociativo como parámetro `$id`, THE EntityManager SHALL construir una cláusula WHERE con una condición por columna de clave unidas con AND
2. WHEN `EntityManager::find()` recibe un valor escalar como parámetro `$id`, THE EntityManager SHALL continuar construyendo una cláusula WHERE de una sola columna como lo hace actualmente
3. IF `EntityManager::find()` recibe un array asociativo cuyas claves no coinciden con los campos Id declarados de la entidad, THEN THE EntityManager SHALL lanzar una `PersistenceException`

### Requisito 21: Soporte OQL para IS NULL e IS NOT NULL

**Historia de Usuario:** Como desarrollador escribiendo consultas OQL, quiero usar condiciones `IS NULL` e `IS NOT NULL` en cláusulas WHERE, para poder filtrar por columnas nullable.

#### Criterios de Aceptación

1. WHEN el OqlParser encuentra `alias.property IS NULL` en una cláusula WHERE, THE OqlParser SHALL producir un nodo AST IsNullExpression con `negated` en false
2. WHEN el OqlParser encuentra `alias.property IS NOT NULL` en una cláusula WHERE, THE OqlParser SHALL producir un nodo AST IsNullExpression con `negated` en true
3. WHEN el OqlToSqlTranslator recibe un nodo IsNullExpression, THE OqlToSqlTranslator SHALL emitir `column IS NULL` o `column IS NOT NULL` en la salida SQL
4. WHEN el OqlPrinter recibe un nodo IsNullExpression, THE OqlPrinter SHALL emitir el texto OQL correspondiente `alias.property IS NULL` o `alias.property IS NOT NULL`
5. PARA TODOS los nodos AST IsNullExpression válidos, parsear luego imprimir luego parsear SHALL producir un AST equivalente (propiedad de ida y vuelta)

### Requisito 22: Soporte OQL para IN y NOT IN

**Historia de Usuario:** Como desarrollador escribiendo consultas OQL, quiero usar condiciones `IN (:param)` y `NOT IN (:param)`, para poder filtrar por conjuntos de valores.

#### Criterios de Aceptación

1. WHEN el OqlParser encuentra `alias.property IN (:param)` en una cláusula WHERE, THE OqlParser SHALL producir un nodo AST InExpression con `negated` en false
2. WHEN el OqlParser encuentra `alias.property NOT IN (:param)` en una cláusula WHERE, THE OqlParser SHALL producir un nodo AST InExpression con `negated` en true
3. WHEN el OqlParser encuentra `alias.property IN (value1, value2, ...)` con valores literales, THE OqlParser SHALL producir un nodo AST InExpression conteniendo una lista de nodos Literal
4. WHEN el EntityManager expande parámetros de consulta para un InExpression vinculado a un parámetro array, THE EntityManager SHALL reemplazar el placeholder nombrado único con un placeholder por cada elemento del array
5. WHEN el OqlToSqlTranslator recibe un nodo InExpression, THE OqlToSqlTranslator SHALL emitir `column IN (...)` o `column NOT IN (...)` en la salida SQL
6. WHEN el OqlPrinter recibe un nodo InExpression, THE OqlPrinter SHALL emitir el texto OQL correspondiente
7. PARA TODOS los nodos AST InExpression válidos, parsear luego imprimir luego parsear SHALL producir un AST equivalente (propiedad de ida y vuelta)

### Requisito 23: Soporte OQL para Funciones de Agregación

**Historia de Usuario:** Como desarrollador escribiendo consultas OQL para reportes, quiero usar funciones de agregación (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) y `DISTINCT` en cláusulas SELECT y HAVING, para poder realizar cálculos agrupados.

#### Criterios de Aceptación

1. WHEN el OqlParser encuentra `COUNT(alias.property)` en una cláusula SELECT o HAVING, THE OqlParser SHALL producir un nodo AST FunctionCall con nombre de función `COUNT`
2. WHEN el OqlParser encuentra `DISTINCT` dentro de una función de agregación como `COUNT(DISTINCT alias.property)`, THE OqlParser SHALL producir un nodo AST FunctionCall con el flag `distinct` en true
3. WHEN el OqlParser encuentra `SUM(...)`, `AVG(...)`, `MIN(...)`, o `MAX(...)`, THE OqlParser SHALL producir un nodo AST FunctionCall con el nombre de función correspondiente
4. WHEN el OqlParser encuentra una cláusula `HAVING` después de `GROUP BY`, THE OqlParser SHALL producir un nodo AST HavingClause conteniendo la expresión de condición
5. WHEN el OqlToSqlTranslator recibe un nodo FunctionCall, THE OqlToSqlTranslator SHALL emitir la función de agregación SQL con nombres de columna resueltos
6. WHEN el OqlToSqlTranslator recibe un nodo HavingClause, THE OqlToSqlTranslator SHALL emitir una cláusula `HAVING` en la salida SQL
7. THE SelectStatement AST node SHALL incluir un campo opcional `havingClause`
8. WHEN el OqlPrinter recibe un nodo FunctionCall, THE OqlPrinter SHALL emitir el texto OQL correspondiente incluyendo DISTINCT cuando aplique
9. WHEN el OqlPrinter recibe un nodo HavingClause, THE OqlPrinter SHALL emitir la cláusula `HAVING` en texto OQL
10. PARA TODOS los ASTs SelectStatement válidos conteniendo funciones de agregación y cláusulas HAVING, parsear luego imprimir luego parsear SHALL producir un AST equivalente (propiedad de ida y vuelta)

### Requisito 24: JOIN OQL Basado en Entidad con Condición WITH

**Historia de Usuario:** Como desarrollador escribiendo consultas OQL, quiero unir entidades usando condiciones arbitrarias con la palabra clave `WITH`, para poder expresar joins que no están basados en relaciones mapeadas.

#### Criterios de Aceptación

1. WHEN el OqlParser encuentra `JOIN EntityName alias WITH condition` en una consulta OQL, THE OqlParser SHALL producir un nodo AST JoinClause conteniendo el nombre de entidad, alias y la expresión de condición WITH
2. WHEN el OqlParser encuentra `LEFT JOIN EntityName alias WITH condition`, THE OqlParser SHALL producir un nodo AST JoinClause con tipo de join `LEFT JOIN`
3. WHEN el OqlToSqlTranslator recibe un JoinClause con condición WITH, THE OqlToSqlTranslator SHALL resolver el nombre de entidad a nombre de tabla y emitir la condición como cláusula ON
4. THE OqlParser SHALL continuar soportando la sintaxis JOIN existente basada en relaciones (`JOIN alias.property joinAlias`) sin cambios
5. WHEN el OqlPrinter recibe un JoinClause con condición WITH, THE OqlPrinter SHALL emitir el texto OQL `JOIN EntityName alias WITH condition`
6. PARA TODOS los nodos AST JoinClause válidos con condiciones WITH, parsear luego imprimir luego parsear SHALL producir un AST equivalente (propiedad de ida y vuelta)

### Requisito 25: Wildcard SELECT y Alias de Columna en OQL

**Historia de Usuario:** Como desarrollador escribiendo consultas OQL, quiero usar `SELECT *` y alias de columna (`AS`), para poder escribir consultas concisas y nombrar columnas de resultado.

#### Criterios de Aceptación

1. WHEN el OqlParser encuentra `SELECT *` en una consulta OQL, THE OqlParser SHALL producir un nodo AST SelectExpression representando una selección wildcard
2. WHEN el OqlParser encuentra `expression AS alias` en la cláusula SELECT, THE OqlParser SHALL producir un nodo AST SelectExpression con el campo alias poblado
3. WHEN el OqlToSqlTranslator recibe un SelectExpression wildcard, THE OqlToSqlTranslator SHALL emitir `*` en la cláusula SQL SELECT
4. WHEN el OqlToSqlTranslator recibe un SelectExpression con alias, THE OqlToSqlTranslator SHALL emitir `expression AS alias` en la salida SQL
5. WHEN el OqlPrinter recibe un SelectExpression con alias, THE OqlPrinter SHALL emitir `expression AS alias` en el texto OQL
6. PARA TODOS los ASTs SelectStatement válidos conteniendo wildcards o aliases, parsear luego imprimir luego parsear SHALL producir un AST equivalente (propiedad de ida y vuelta)

### Requisito 26: Modo de Hidratación Escalar y Array Asociativo

**Historia de Usuario:** Como desarrollador ejecutando consultas OQL de agregación o multi-entidad, quiero recibir resultados como arrays asociativos en lugar de objetos entidad, para poder trabajar con resultados escalares y proyecciones personalizadas.

#### Criterios de Aceptación

1. WHEN `EntityManager::query()` se invoca con modo de hidratación `HYDRATE_ARRAY`, THE EntityManager SHALL retornar resultados como un array de arrays asociativos en lugar de objetos entidad
2. WHEN `EntityManager::query()` se invoca con modo de hidratación `HYDRATE_OBJECT` o sin especificar modo, THE EntityManager SHALL retornar resultados como objetos entidad hidratados (comportamiento actual)
3. WHEN la consulta OQL contiene funciones de agregación, alias de columna, o selecciona de múltiples entidades, THE EntityManager SHALL usar por defecto el modo `HYDRATE_ARRAY`
4. THE EntityManager::query() method signature SHALL aceptar un parámetro opcional de modo de hidratación manteniendo compatibilidad

### Requisito 27: Soporte HAVING en QueryBuilder

**Historia de Usuario:** Como desarrollador construyendo consultas programáticas, quiero usar un método `having()` en el QueryBuilder, para poder agregar condiciones HAVING a consultas agrupadas.

#### Criterios de Aceptación

1. WHEN `QueryBuilder::having()` se invoca con un string de condición, THE QueryBuilder SHALL almacenar la condición HAVING
2. WHEN `QueryBuilder::getSQL()` se invoca y se ha establecido una condición HAVING, THE QueryBuilder SHALL emitir una cláusula `HAVING` después de la cláusula `GROUP BY` en el SQL generado
3. IF `QueryBuilder::having()` se invoca sin un `groupBy()` previo, THEN THE QueryBuilder SHALL incluir la cláusula HAVING en el SQL generado (la base de datos validará)
4. THE QueryBuilderInterface SHALL declarar la firma del método `having()`

### Requisito 28: Conversión Transparente de Charset UTF-8 a ISO-8859-1

**Historia de Usuario:** Como desarrollador conectando a un servidor Sybase ASE que usa codificación ISO-8859-1 desde una aplicación PHP que usa UTF-8, quiero que el ORM convierta transparentemente strings entre UTF-8 e ISO-8859-1, para que los caracteres especiales se preserven sin conversión manual.

#### Criterios de Aceptación

1. WHERE la opción de configuración `charset_conversion` está habilitada, THE ConnectionManager SHALL convertir valores string de UTF-8 a ISO-8859-1 antes de enviarlos a la base de datos
2. WHERE la opción de configuración `charset_conversion` está habilitada, THE ConnectionManager SHALL convertir valores string de resultado de ISO-8859-1 a UTF-8 después de leerlos de la base de datos
3. WHERE la opción de configuración `charset_conversion` está deshabilitada o no establecida, THE ConnectionManager SHALL pasar valores string sin conversión (comportamiento actual)
4. IF un valor string contiene caracteres que no pueden representarse en ISO-8859-1, THEN THE ConnectionManager SHALL preservar los bytes originales y no corromper los datos
5. THE opción de configuración `charset_conversion` SHALL aceptar un valor booleano y tener false como valor por defecto


### Requisito 29: Sentencias OQL UPDATE — Parsing, Impresión y Traducción

**Historia de Usuario:** Como desarrollador migrando desde Doctrine, quiero escribir sentencias UPDATE usando OQL con nombres de entidad y propiedades, para ejecutar actualizaciones masivas sin cargar entidades en memoria.

#### Criterios de Aceptación

1. WHEN el OQL_Parser recibe `UPDATE EntityName alias SET prop = val`, THE OQL_Parser SHALL producir un nodo AST UpdateStatement con nombre de entidad, alias, lista de SetClause y WHERE opcional
2. WHEN la sentencia UPDATE contiene múltiples asignaciones (`SET prop1 = val1, prop2 = val2`), THE OQL_Parser SHALL producir un array de nodos SetClause
3. THE OQL_Parser SHALL soportar valores de asignación: parámetros nombrados, literales NULL/string/numéricos, y llamadas a funciones personalizadas (CustomFunctionCall)
4. WHEN el OQL_Printer recibe un UpdateStatement, SHALL producir texto OQL válido; parsear la salida SHALL producir un AST equivalente (round-trip)
5. WHEN el OQL_Translator traduce un UpdateStatement, SHALL resolver entidad→tabla y propiedad→columna, recopilar parámetros SET antes de WHERE, emitir NULL/literales directamente y funciones como pass-through

### Requisito 30: Sentencias OQL DELETE — Parsing, Impresión y Traducción

**Historia de Usuario:** Como desarrollador migrando desde Doctrine, quiero escribir sentencias DELETE usando OQL con nombres de entidad, para ejecutar eliminaciones masivas sin cargar entidades en memoria.

#### Criterios de Aceptación

1. WHEN el OQL_Parser recibe `DELETE FROM EntityName alias [WHERE condition]`, THE OQL_Parser SHALL producir un nodo AST DeleteStatement
2. THE OQL_Printer y OQL_Translator SHALL soportar DeleteStatement con la misma lógica de resolución y round-trip que UpdateStatement
3. IF DELETE no contiene FROM, THEN THE OQL_Parser SHALL lanzar OqlParseException

### Requisito 31: Ejecución de UPDATE/DELETE vía EntityManager

**Historia de Usuario:** Como desarrollador, quiero ejecutar sentencias OQL UPDATE y DELETE a través del EntityManager y obtener el número de filas afectadas.

#### Criterios de Aceptación

1. THE EntityManagerInterface SHALL declarar `executeUpdate(string $oql, array $params = []): int`
2. THE EntityManager SHALL parsear, traducir, ejecutar vía ConnectionManager::executeStatement() y retornar rowCount
3. IF recibe SELECT, SHALL lanzar OqlParseException
4. SHALL expandir parámetros array para IN de la misma forma que `query()`

### Requisito 32: Funciones OQL Personalizadas — CONVERT y RAND

**Historia de Usuario:** Como desarrollador, quiero usar `CONVERT(expression AS type)` y `RAND()` en sentencias OQL.

#### Criterios de Aceptación

1. THE OQL_Parser SHALL producir nodos CustomFunctionCall para CONVERT (con castType) y RAND (sin argumentos), soportando anidamiento
2. THE OQL_Translator SHALL emitir funciones personalizadas como pass-through a Sybase ASE
3. THE OQL_Parser SHALL distinguir funciones de agregación (COUNT, SUM, AVG, MIN, MAX) de funciones personalizadas (CONVERT, RAND)

### Requisito 33: Métodos de Resultado — queryOne y queryScalar

**Historia de Usuario:** Como desarrollador migrando desde Doctrine, quiero métodos `queryOne()` y `queryScalar()` para obtener un solo resultado o un valor escalar.

#### Criterios de Aceptación

1. `queryOne()` SHALL aplicar límite de 1 resultado y retornar la entidad o null
2. `queryScalar()` SHALL aplicar límite de 1, ejecutar en HYDRATE_ARRAY y retornar el primer valor o null

### Requisito 34: Tipo Personalizado con Envolvimiento SQL

**Historia de Usuario:** Como desarrollador, quiero tipos personalizados que envuelvan el placeholder SQL con una expresión (e.g. `CONVERT(REAL, ?)`).

#### Criterios de Aceptación

1. THE bundle SHALL proporcionar SqlWrappingTypeInterface que extienda CustomTypeInterface con `convertToDatabaseValueSQL(string $sqlExpr): string`
2. THE UnitOfWork SHALL usar la expresión envolvente en INSERT y UPDATE para columnas con tipos SqlWrapping
3. THE TypeCaster SHALL exponer `getDatabaseValueSQL()` para detectar tipos con envolvimiento
4. THE Dialect SHALL aceptar un array opcional de expresiones de valor en generateInsert/generateUpdate

### Requisito 35: Mejoras de Calidad — Validación, Logging, Convenciones y API

**Historia de Usuario:** Como desarrollador, quiero que el ORM tenga validación robusta, logging de errores, convenciones consistentes y métodos de conveniencia.

#### Criterios de Aceptación

1. THE OQL_Parser SHALL validar que las listas IN no estén vacías, lanzando OqlParseException
2. THE ConnectionManager SHALL loguear warnings via PSR LoggerInterface cuando la conversión iconv falla
3. Las clases de excepción y TypeCaster SHALL ser `final` según las convenciones del proyecto
4. THE EntityRepository SHALL proporcionar métodos `count(array $criteria = []): int` y `exists(array $criteria): bool`
5. THE EntityManager SHALL proporcionar `queryIterator()` para streaming de resultados grandes sin cargar todo en memoria
6. THE EntityManager::shouldAutoDetectArrayMode() SHALL detectar GROUP BY y aliases de columna además de FunctionCall
