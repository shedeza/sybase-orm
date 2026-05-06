<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Registry that manages multiple EntityManager instances for multi-database support.
 *
 * Each EntityManager is associated with a named connection. Entities declare which
 * connection they belong to via #[Entity(connection: 'name')].
 *
 * Usage:
 *     $registry->getManager('default');       // Get specific EM
 *     $registry->getManager();                // Get default EM
 *     $registry->getManagerForEntity($class); // Get EM by entity's connection
 */
final class EntityManagerRegistry
{
    /** @var array<string, EntityManagerInterface> */
    private array $managers = [];

    private string $defaultConnection;

    /**
     * @param array<string, EntityManagerInterface> $managers Named EntityManagers
     * @param string $defaultConnection Name of the default connection
     */
    public function __construct(array $managers = [], string $defaultConnection = 'default')
    {
        $this->managers = $managers;
        $this->defaultConnection = $defaultConnection;
    }

    /**
     * Registers an EntityManager for a named connection.
     */
    public function addManager(string $name, EntityManagerInterface $manager): void
    {
        $this->managers[$name] = $manager;
    }

    /**
     * Returns the EntityManager for the given connection name.
     * If no name is provided, returns the default EntityManager.
     *
     * @throws \InvalidArgumentException If the connection name is not registered.
     */
    public function getManager(?string $name = null): EntityManagerInterface
    {
        $name ??= $this->defaultConnection;

        if (!isset($this->managers[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'No EntityManager registered for connection "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->managers)) ?: '(none)',
            ));
        }

        return $this->managers[$name];
    }

    /**
     * Returns the EntityManager that manages the given entity class,
     * based on the entity's #[Entity(connection: '...')] attribute.
     *
     * @param class-string $entityClass
     */
    public function getManagerForEntity(string $entityClass): EntityManagerInterface
    {
        // Read the connection name from the entity's metadata
        $defaultManager = $this->getManager();
        $metadata = $defaultManager->getMetadataReader()->getClassMetadata($entityClass);

        return $this->getManager($metadata->connection);
    }

    /**
     * Returns the repository for an entity, automatically selecting the correct EntityManager.
     *
     * @param class-string $entityClass
     */
    public function getRepository(string $entityClass): EntityRepository
    {
        return $this->getManagerForEntity($entityClass)->getRepository($entityClass);
    }

    /**
     * Returns all registered connection names.
     *
     * @return string[]
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->managers);
    }

    /**
     * Returns the default connection name.
     */
    public function getDefaultConnectionName(): string
    {
        return $this->defaultConnection;
    }

    /**
     * Clears all EntityManagers (useful for long-running processes).
     */
    public function clearAll(): void
    {
        foreach ($this->managers as $manager) {
            $manager->clear();
        }
    }
}
