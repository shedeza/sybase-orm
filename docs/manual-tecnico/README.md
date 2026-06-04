# Manual Técnico — Sybase ORM

Este manual documenta la arquitectura interna, los patrones de diseño, los puntos de extensión y los flujos internos del ORM `shedeza/sybase-orm`. Está orientado a desarrolladores que contribuyen al proyecto o necesitan comprender su funcionamiento interno para extenderlo.

## Tabla de Contenidos

| # | Sección | Descripción |
|---|---------|-------------|
| 1 | [Arquitectura General](./arquitectura-general.md) | Capas del ORM (Connection, Metadata, ORM, Query, Cache, Proxy, Hook, Migration), responsabilidades y dependencias entre ellas |
| 2 | [Organización del Código](./organizacion-codigo.md) | Estructura de namespaces, propósito de cada directorio en `src/` |
| 3 | [Patrón Unit of Work](./patron-unit-of-work.md) | Gestión del changeset, detección de entidades dirty, orden de ejecución y commit transaccional |
| 4 | [Patrón Identity Map](./patron-identity-map.md) | Ciclo de vida de entidades gestionadas, interacción con Unit of Work y limpieza con `clear()` |
| 5 | [Patrón Data Mapper](./patron-data-mapper.md) | Separación dominio/persistencia y rol del Hydrator en la transformación de resultados a objetos |
| 6 | [Patrón Proxy](./patron-proxy.md) | Generación de proxies para lazy loading, ProxyGenerator y LazyLoadingProxy |
| 7 | [Patrón Repository](./patron-repository.md) | EntityRepository como base, métodos disponibles y repositorios personalizados |
| 8 | [Extensión: Tipos Personalizados](./extension-tipos-personalizados.md) | Implementación de CustomTypeInterface, registro y conversión de valores PHP ↔ base de datos |
| 9 | [Extensión: Event Subscribers](./extension-event-subscribers.md) | EventSubscriberInterface, eventos disponibles, registro e integración con Symfony EventDispatcher |
| 10 | [Extensión: Funciones OQL](./extension-funciones-oql.md) | OqlFunctionInterface, registro mediante `registerOqlFunction()` y ejemplo completo |
| 11 | [Flujo de Persistencia](./flujo-persistencia.md) | Flujo interno desde `persist()` hasta la ejecución SQL, pasando por UnitOfWork, MetadataReader y ConnectionManager |
| 12 | [Flujo de Consultas](./flujo-consultas.md) | Flujo desde OQL/QueryBuilder hasta resultados hidratados: Lexer → Parser → AST → SqlWalker → SQL → Hydrator |
| 13 | [Flujo de Hidratación](./flujo-hidratacion.md) | Transformación de result sets de base de datos a objetos PHP, incluyendo relaciones y embeddables |
| 14 | [Diagramas de Clases](./diagramas-clases.md) | Diagramas Mermaid de las clases principales y sus relaciones por componente |
| 15 | [Guías de Contribución](./guias-contribucion.md) | Requisitos para PRs, estándares de código, estructura de tests y convenciones de commits |

## Navegación

- [← Manual de Usuario](../usuario-manual/README.md)
- [← Manual de Operación](../manual-operacion/README.md)
- [← README del Proyecto](../../README.md)
