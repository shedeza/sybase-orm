# Requirements Document

## Introduction

This document specifies the requirements for closing 10 implementation gaps identified during the migration of the "Insaculación" project from Doctrine ORM to the `sybase-ase-orm-bundle`. Each gap represents a missing capability that the Insaculación domain requires. All changes must maintain backward compatibility with the existing 524 tests and preserve the current API contracts for single-key entities.

## Glossary

- **ORM**: The `sybase-ase-orm-bundle` Object-Relational Mapper system
- **ClassMetadata**: Value object (`SybaseORM\Metadata\ClassMetadata`) holding the complete mapping metadata for an entity class
- **MetadataReader**: Component (`SybaseORM\Metadata\MetadataReader`) that reads PHP attributes from entity classes and builds ClassMetadata objects
- **UnitOfWork**: Component (`SybaseORM\ORM\UnitOfWork`) that tracks entity changes and coordinates persistence within a transaction
- **EntityManager**: Central orchestrator (`SybaseORM\ORM\EntityManager`) that coordinates all ORM components for the entity lifecycle
- **IdentityMap**: Component (`SybaseORM\ORM\IdentityMap`) that guarantees one entity instance per class and identifier within a session
- **Hydrator**: Component (`SybaseORM\Hydrator\Hydrator`) that converts database result rows into entity instances
- **OqlParser**: Recursive-descent parser (`SybaseORM\Query\OqlParser`) that converts OQL strings into AST nodes
- **OqlPrinter**: Component (`SybaseORM\Query\OqlPrinter`) that traverses an OQL AST and produces a valid OQL text representation
- **OqlToSqlTranslator**: Component (`SybaseORM\Query\OqlToSqlTranslator`) that translates OQL AST nodes into Sybase-compatible SQL
- **QueryBuilder**: Fluent query builder (`SybaseORM\Query\QueryBuilder`) that generates parameterized SQL
- **ConnectionManager**: Component (`SybaseORM\Connection\ConnectionManager`) that manages PDO dblib connections to Sybase ASE
- **AST**: Abstract Syntax Tree — the intermediate representation produced by the OqlParser
- **OQL**: Object Query Language — the custom query language used by the ORM
- **Composite_Primary_Key**: A primary key composed of two or more columns that together uniquely identify a row
- **Composite_Identity_Key**: A string key derived from multiple column values, used to index entities in the IdentityMap
- **IsNullExpression**: A new AST node representing `IS NULL` or `IS NOT NULL` conditions in OQL
- **InExpression**: A new AST node representing `IN (...)` or `NOT IN (...)` conditions in OQL
- **FunctionCall**: A new AST node representing aggregate function calls such as `COUNT()`, `SUM()`, `AVG()`, `MIN()`, `MAX()` in OQL
- **HavingClause**: A new AST node representing the `HAVING` clause in OQL SELECT statements
- **Hydration_Mode**: An enumeration specifying how query results are returned — as entity objects (`HYDRATE_OBJECT`) or as associative arrays (`HYDRATE_ARRAY`)

## Requirements

### Requirement 1: Composite Primary Key Metadata

**User Story:** As a developer mapping Insaculación entities, I want to annotate multiple properties with `#[Id]` on a single entity, so that the ORM recognizes composite primary keys.

#### Acceptance Criteria

1. WHEN the MetadataReader encounters multiple properties annotated with `#[Id]` on a single entity class, THE MetadataReader SHALL collect all annotated property names into an array stored in `ClassMetadata.$idFields`
2. WHEN the MetadataReader encounters exactly one property annotated with `#[Id]`, THE MetadataReader SHALL store that property name in `ClassMetadata.$idFields` as a single-element array
3. THE ClassMetadata SHALL provide a `getIdColumns()` method that returns an array of ColumnMetadata objects for all primary key fields
4. THE ClassMetadata SHALL continue to provide the existing `getIdColumn()` method, returning the first Id column for backward compatibility with single-key entities
5. WHEN a single `#[Id]` property is defined, THE ClassMetadata `$idField` property SHALL remain available and return that property name for backward compatibility

### Requirement 2: Composite Primary Key Persistence

**User Story:** As a developer persisting Insaculación entities with composite keys, I want the UnitOfWork to generate correct WHERE clauses using all key columns, so that UPDATE and DELETE operations target the correct row.

#### Acceptance Criteria

1. WHEN the UnitOfWork executes an UPDATE for an entity with a composite primary key, THE UnitOfWork SHALL build a WHERE clause that includes all primary key columns joined with AND
2. WHEN the UnitOfWork executes a DELETE for an entity with a composite primary key, THE UnitOfWork SHALL build a WHERE clause that includes all primary key columns joined with AND
3. WHEN the UnitOfWork executes an UPDATE for an entity with a single primary key, THE UnitOfWork SHALL continue to build a WHERE clause with a single key column
4. WHEN the UnitOfWork executes a DELETE for an entity with a single primary key, THE UnitOfWork SHALL continue to build a WHERE clause with a single key column

### Requirement 3: Composite Primary Key Identity Map

**User Story:** As a developer working with composite-key entities, I want the IdentityMap to correctly store and retrieve entities using composite keys, so that object identity is guaranteed within a session.

#### Acceptance Criteria

1. WHEN the IdentityMap stores an entity with a composite primary key, THE IdentityMap SHALL use a deterministic string derived from all key values as the map key
2. WHEN the IdentityMap receives a composite key lookup via `get()`, THE IdentityMap SHALL return the entity matching all key values
3. WHEN the IdentityMap receives a scalar key for a single-key entity, THE IdentityMap SHALL continue to function identically to the current behavior
4. THE Hydrator SHALL resolve entities from the IdentityMap using all primary key column values when the entity has a composite primary key
5. THE Hydrator SHALL store entities in the IdentityMap using all primary key column values when the entity has a composite primary key

### Requirement 4: Composite Primary Key in EntityManager::find()

**User Story:** As a developer querying Insaculación entities by composite key, I want `EntityManager::find()` to accept an associative array of key values, so that I can look up entities with multi-column primary keys.

#### Acceptance Criteria

1. WHEN `EntityManager::find()` receives an associative array as the `$id` parameter, THE EntityManager SHALL build a WHERE clause with one condition per key column joined with AND
2. WHEN `EntityManager::find()` receives a scalar value as the `$id` parameter, THE EntityManager SHALL continue to build a single-column WHERE clause as it does today
3. IF `EntityManager::find()` receives an associative array whose keys do not match the entity's declared Id fields, THEN THE EntityManager SHALL throw a `PersistenceException`

### Requirement 5: OQL IS NULL and IS NOT NULL Support

**User Story:** As a developer writing OQL queries for Insaculación, I want to use `IS NULL` and `IS NOT NULL` conditions in WHERE clauses, so that I can filter on nullable columns.

#### Acceptance Criteria

1. WHEN the OqlParser encounters `alias.property IS NULL` in a WHERE clause, THE OqlParser SHALL produce an IsNullExpression AST node with `negated` set to false
2. WHEN the OqlParser encounters `alias.property IS NOT NULL` in a WHERE clause, THE OqlParser SHALL produce an IsNullExpression AST node with `negated` set to true
3. WHEN the OqlToSqlTranslator receives an IsNullExpression node, THE OqlToSqlTranslator SHALL emit `column IS NULL` or `column IS NOT NULL` in the SQL output
4. WHEN the OqlPrinter receives an IsNullExpression node, THE OqlPrinter SHALL emit the corresponding `alias.property IS NULL` or `alias.property IS NOT NULL` OQL text
5. FOR ALL valid IsNullExpression AST nodes, parsing then printing then parsing SHALL produce an equivalent AST (round-trip property)

### Requirement 6: OQL IN and NOT IN Support

**User Story:** As a developer writing OQL queries for Insaculación, I want to use `IN (:param)` and `NOT IN (:param)` conditions, so that I can filter by sets of values.

#### Acceptance Criteria

1. WHEN the OqlParser encounters `alias.property IN (:param)` in a WHERE clause, THE OqlParser SHALL produce an InExpression AST node with `negated` set to false
2. WHEN the OqlParser encounters `alias.property NOT IN (:param)` in a WHERE clause, THE OqlParser SHALL produce an InExpression AST node with `negated` set to true
3. WHEN the OqlParser encounters `alias.property IN (value1, value2, ...)` with literal values, THE OqlParser SHALL produce an InExpression AST node containing a list of Literal nodes
4. WHEN the EntityManager expands query parameters for an InExpression bound to an array parameter, THE EntityManager SHALL replace the single named placeholder with one placeholder per array element
5. WHEN the OqlToSqlTranslator receives an InExpression node, THE OqlToSqlTranslator SHALL emit `column IN (...)` or `column NOT IN (...)` in the SQL output
6. WHEN the OqlPrinter receives an InExpression node, THE OqlPrinter SHALL emit the corresponding OQL text
7. FOR ALL valid InExpression AST nodes, parsing then printing then parsing SHALL produce an equivalent AST (round-trip property)

### Requirement 7: OQL Aggregate Functions Support

**User Story:** As a developer writing OQL queries for Insaculación reports, I want to use aggregate functions (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) and `DISTINCT` in SELECT and HAVING clauses, so that I can perform grouped calculations.

#### Acceptance Criteria

1. WHEN the OqlParser encounters `COUNT(alias.property)` in a SELECT or HAVING clause, THE OqlParser SHALL produce a FunctionCall AST node with function name `COUNT`
2. WHEN the OqlParser encounters `DISTINCT` inside an aggregate function such as `COUNT(DISTINCT alias.property)`, THE OqlParser SHALL produce a FunctionCall AST node with the `distinct` flag set to true
3. WHEN the OqlParser encounters `SUM(...)`, `AVG(...)`, `MIN(...)`, or `MAX(...)`, THE OqlParser SHALL produce a FunctionCall AST node with the corresponding function name
4. WHEN the OqlParser encounters a `HAVING` clause after `GROUP BY`, THE OqlParser SHALL produce a HavingClause AST node containing the condition expression
5. WHEN the OqlToSqlTranslator receives a FunctionCall node, THE OqlToSqlTranslator SHALL emit the SQL aggregate function with resolved column names
6. WHEN the OqlToSqlTranslator receives a HavingClause node, THE OqlToSqlTranslator SHALL emit a `HAVING` clause in the SQL output
7. THE SelectStatement AST node SHALL include an optional `havingClause` field
8. WHEN the OqlPrinter receives a FunctionCall node, THE OqlPrinter SHALL emit the corresponding OQL text including DISTINCT when applicable
9. WHEN the OqlPrinter receives a HavingClause node, THE OqlPrinter SHALL emit the `HAVING` clause in OQL text
10. FOR ALL valid SelectStatement ASTs containing aggregate functions and HAVING clauses, parsing then printing then parsing SHALL produce an equivalent AST (round-trip property)

### Requirement 8: OQL Entity-Based JOIN with WITH Condition

**User Story:** As a developer writing OQL queries for Insaculación, I want to join entities using arbitrary conditions with the `WITH` keyword, so that I can express joins that are not based on mapped relationships.

#### Acceptance Criteria

1. WHEN the OqlParser encounters `JOIN EntityName alias WITH condition` in an OQL query, THE OqlParser SHALL produce a JoinClause AST node containing the entity name, alias, and the WITH condition expression
2. WHEN the OqlParser encounters `LEFT JOIN EntityName alias WITH condition`, THE OqlParser SHALL produce a JoinClause AST node with join type `LEFT JOIN`
3. WHEN the OqlToSqlTranslator receives a JoinClause with a WITH condition, THE OqlToSqlTranslator SHALL resolve the entity name to a table name and emit the condition as the ON clause
4. THE OqlParser SHALL continue to support the existing relationship-based JOIN syntax (`JOIN alias.property joinAlias`) without changes
5. WHEN the OqlPrinter receives a JoinClause with a WITH condition, THE OqlPrinter SHALL emit the `JOIN EntityName alias WITH condition` OQL text
6. FOR ALL valid JoinClause AST nodes with WITH conditions, parsing then printing then parsing SHALL produce an equivalent AST (round-trip property)

### Requirement 9: OQL SELECT Wildcard and Column Aliases

**User Story:** As a developer writing OQL queries for Insaculación, I want to use `SELECT *` and column aliases (`AS`), so that I can write concise queries and name result columns.

#### Acceptance Criteria

1. WHEN the OqlParser encounters `SELECT *` in an OQL query, THE OqlParser SHALL produce a SelectExpression AST node representing a wildcard selection
2. WHEN the OqlParser encounters `expression AS alias` in the SELECT clause, THE OqlParser SHALL produce a SelectExpression AST node with the alias field populated
3. WHEN the OqlToSqlTranslator receives a wildcard SelectExpression, THE OqlToSqlTranslator SHALL emit `*` in the SQL SELECT clause
4. WHEN the OqlToSqlTranslator receives a SelectExpression with an alias, THE OqlToSqlTranslator SHALL emit `expression AS alias` in the SQL output
5. WHEN the OqlPrinter receives a SelectExpression with an alias, THE OqlPrinter SHALL emit `expression AS alias` in the OQL text
6. FOR ALL valid SelectStatement ASTs containing wildcards or aliases, parsing then printing then parsing SHALL produce an equivalent AST (round-trip property)

### Requirement 10: Hydrator Scalar and Associative Array Mode

**User Story:** As a developer running aggregate or multi-entity OQL queries for Insaculación, I want to receive results as associative arrays instead of entity objects, so that I can work with scalar results and custom projections.

#### Acceptance Criteria

1. WHEN `EntityManager::query()` is called with hydration mode `HYDRATE_ARRAY`, THE EntityManager SHALL return results as an array of associative arrays instead of entity objects
2. WHEN `EntityManager::query()` is called with hydration mode `HYDRATE_OBJECT` or without specifying a mode, THE EntityManager SHALL return results as hydrated entity objects (current behavior)
3. WHEN the OQL query contains aggregate functions, column aliases, or selects from multiple entities, THE EntityManager SHALL default to `HYDRATE_ARRAY` mode
4. THE EntityManager::query() method signature SHALL accept an optional hydration mode parameter while maintaining backward compatibility

### Requirement 11: QueryBuilder HAVING Support

**User Story:** As a developer building programmatic queries for Insaculación, I want to use a `having()` method on the QueryBuilder, so that I can add HAVING conditions to grouped queries.

#### Acceptance Criteria

1. WHEN `QueryBuilder::having()` is called with a condition string, THE QueryBuilder SHALL store the HAVING condition
2. WHEN `QueryBuilder::getSQL()` is called and a HAVING condition has been set, THE QueryBuilder SHALL emit a `HAVING` clause after the `GROUP BY` clause in the generated SQL
3. IF `QueryBuilder::having()` is called without a prior `groupBy()`, THEN THE QueryBuilder SHALL still include the HAVING clause in the generated SQL (the database will enforce validity)
4. THE QueryBuilderInterface SHALL declare the `having()` method signature

### Requirement 12: Transparent UTF-8 to ISO-8859-1 Charset Conversion

**User Story:** As a developer connecting to a Sybase ASE server that uses ISO-8859-1 encoding from a PHP application that uses UTF-8, I want the ORM to transparently convert strings between UTF-8 and ISO-8859-1, so that special characters are preserved without manual conversion.

#### Acceptance Criteria

1. WHERE the `charset_conversion` configuration option is enabled, THE ConnectionManager SHALL convert string values from UTF-8 to ISO-8859-1 before sending them to the database
2. WHERE the `charset_conversion` configuration option is enabled, THE ConnectionManager SHALL convert string result values from ISO-8859-1 to UTF-8 after reading them from the database
3. WHERE the `charset_conversion` configuration option is disabled or not set, THE ConnectionManager SHALL pass string values through without conversion (current behavior)
4. IF a string value contains characters that cannot be represented in ISO-8859-1, THEN THE ConnectionManager SHALL preserve the original bytes and not corrupt the data
5. THE `charset_conversion` configuration option SHALL accept a boolean value and default to false
