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
 *     $registry->addManager('default', $em);  // Register a named EM
 *     $registry->getManager('default');        // Get specific EM
 *     $registry->getDefaultManager();          // Get default EM
 *     $registry->getManagerForEntity($class);  // Get EM by entity's connection
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
     *
     * @throws \InvalidArgumentException If the connection name is not registered.
     */
    public function getManager(string $name): EntityManagerInterface
    {
        if (!isset($this->managers[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'EntityManager with name "%s" is not registered.',
                $name,
            ));
        }

        return $this->managers[$name];
    }

    /**
     * Checks if an EntityManager is registered under the given name.
     */
    public function hasManager(string $name): bool
    {
        return isset($this->managers[$name]);
    }

    /**
     * Returns all registered manager names.
     *
     * @return string[]
     */
    public function getManagerNames(): array
    {
        return array_keys($this->managers);
    }

    /**
     * Returns the default EntityManager.
     * Uses the designated default connection name, or the first registered manager
     * if the default connection name is not explicitly registered.
     *
     * @throws \InvalidArgumentException If no managers are registered.
     */
    public function getDefaultManager(): EntityManagerInterface
    {
        if (isset($this->managers[$this->defaultConnection])) {
            return $this->managers[$this->defaultConnection];
        }

        if ($this->managers !== []) {
            return reset($this->managers);
        }

        throw new \InvalidArgumentException(
            'No EntityManagers have been registered.',
        );
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
        $defaultManager = $this->getDefaultManager();
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
