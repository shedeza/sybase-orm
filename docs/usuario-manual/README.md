# Manual de Usuario — Sybase ORM

Documentación completa de uso del ORM `shedeza/sybase-orm` para PHP. Este manual cubre desde la configuración inicial hasta las características más avanzadas del sistema.

## Tabla de Contenidos

| # | Sección | Descripción |
|---|---------|-------------|
| 1 | [Configuración y Conexión](./configuracion-conexion.md) | Configuración por array y URL DSN, conexiones múltiples, charset y opciones avanzadas de conexión |
| 2 | [Mapeo de Entidades](./mapeo-entidades.md) | Atributos #[Entity], #[Column], #[Id], tipos de columna, enums, tipos personalizados y embeddables |
| 3 | [Relaciones](./relaciones.md) | ManyToOne, OneToMany, OneToOne, ManyToMany, JoinColumn, lazy loading y eager loading |
| 4 | [Sistema de Consultas](./sistema-consultas.md) | Sintaxis OQL, métodos de ejecución, parametrización, funciones personalizadas y modos de hidratación |
| 5 | [QueryBuilder](./sistema-consultas-querybuilder.md) | API completa del QueryBuilder: select, from, where, join, orderBy, groupBy, limit, offset y más |
| 6 | [Ciclo de Vida y Hooks](./ciclo-vida-hooks.md) | Hooks PrePersist, PostPersist, PreUpdate, PostUpdate, PreRemove, PostRemove y sistema de eventos |
| 7 | [Soft Delete](./soft-delete.md) | Eliminación lógica con #[SoftDelete], filtrado automático y consulta de registros eliminados |
| 8 | [Herencia de Entidades](./herencia-entidades.md) | Herencia single-table con #[InheritanceType], #[DiscriminatorColumn] y #[DiscriminatorMap] |
| 9 | [Sistema de Caché](./sistema-cache.md) | Caché de primer nivel (Identity Map), segundo nivel con Redis y queryCached() |
| 10 | [Sistema de Migraciones](./sistema-migraciones.md) | Generación, ejecución, rollback y control de versiones de migraciones de esquema |
| 11 | [Transacciones](./transacciones.md) | Control transaccional, savepoints, niveles de aislamiento y manejo de errores |
| 12 | [Repositorio de Entidades](./repositorio-entidades.md) | API completa de EntityRepository: find, save, delete, count, exists y repositorios personalizados |
| 13 | [Manejo de Errores](./manejo-errores.md) | Jerarquía de excepciones, detección de deadlocks y patrones de retry |
| 14 | [Características Avanzadas](./caracteristicas-avanzadas.md) | Raw SQL, clear/detach/merge/refresh, modo read-only, caché LRU y reconexión automática |

## Otros Manuales

- [Manual de Operación](../manual-operacion/README.md) — Despliegue, optimización y troubleshooting en producción
- [Manual Técnico](../manual-tecnico/README.md) — Arquitectura interna, patrones de diseño y guías de contribución

---

[← Volver al README del proyecto](../../README.md)
