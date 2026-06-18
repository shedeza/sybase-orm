<?php

declare(strict_types=1);

namespace SybaseORM\Testing;

use SybaseORM\ORM\EntityManagerInterface;

/**
 * Factory for creating entity instances with fake/default data for testing.
 *
 * Usage:
 *     class UserFactory extends EntityFactory {
 *         protected function definition(): array {
 *             return [
 *                 'name' => 'Test User ' . $this->sequence,
 *                 'email' => "user{$this->sequence}@test.com",
 *                 'active' => true,
 *             ];
 *         }
 *
 *         protected function entityClass(): string {
 *             return User::class;
 *         }
 *     }
 *
 *     // Create without persisting
 *     $user = UserFactory::new()->make();
 *
 *     // Create and persist
 *     $user = UserFactory::new($em)->create();
 *
 *     // Create multiple
 *     $users = UserFactory::new($em)->count(10)->create();
 *
 *     // Override attributes
 *     $admin = UserFactory::new($em)->create(['role' => 'admin']);
 */
abstract class EntityFactory
{
    protected int $sequence = 0;
    private int $count = 1;
    private ?EntityManagerInterface $entityManager;

    /** @var array<string, mixed> State overrides */
    private array $stateOverrides = [];

    public function __construct(?EntityManagerInterface $entityManager = null)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Creates a new factory instance.
     */
    public static function new(?EntityManagerInterface $entityManager = null): static
    {
        return new static($entityManager);
    }

    /**
     * Defines the default attribute values for the entity.
     *
     * @return array<string, mixed>
     */
    abstract protected function definition(): array;

    /**
     * Returns the entity class this factory creates.
     *
     * @return class-string
     */
    abstract protected function entityClass(): string;

    /**
     * Sets the number of entities to create.
     */
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    /**
     * Adds state overrides for the next creation.
     *
     * @param array<string, mixed> $state
     */
    public function state(array $state): static
    {
        $clone = clone $this;
        $clone->stateOverrides = array_merge($clone->stateOverrides, $state);

        return $clone;
    }

    /**
     * Creates entity instance(s) without persisting.
     *
     * @param array<string, mixed> $overrides Extra attribute overrides
     * @return object|object[] Single entity or array if count > 1
     */
    public function make(array $overrides = []): object|array
    {
        if ($this->count === 1) {
            return $this->makeOne($overrides);
        }

        $entities = [];
        for ($i = 0; $i < $this->count; $i++) {
            $entities[] = $this->makeOne($overrides);
        }

        return $entities;
    }

    /**
     * Creates entity instance(s) and persists them.
     *
     * @param array<string, mixed> $overrides Extra attribute overrides
     * @return object|object[] Single entity or array if count > 1
     * @throws \RuntimeException If no EntityManager is configured.
     */
    public function create(array $overrides = []): object|array
    {
        if ($this->entityManager === null) {
            throw new \RuntimeException(
                'Cannot persist entities without an EntityManager. Pass it to new() or use make() instead.',
            );
        }

        if ($this->count === 1) {
            $entity = $this->makeOne($overrides);
            $this->entityManager->persist($entity);
            $this->entityManager->flush();

            return $entity;
        }

        $entities = [];
        for ($i = 0; $i < $this->count; $i++) {
            $entity = $this->makeOne($overrides);
            $this->entityManager->persist($entity);
            $entities[] = $entity;
        }
        $this->entityManager->flush();

        return $entities;
    }

    private function makeOne(array $overrides): object
    {
        $this->sequence++;

        $attributes = array_merge(
            $this->definition(),
            $this->stateOverrides,
            $overrides,
        );

        $entityClass = $this->entityClass();
        $entity = new $entityClass();
        $reflection = new \ReflectionClass($entity);

        foreach ($attributes as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($entity, $value instanceof \Closure ? $value($this->sequence) : $value);
            }
        }

        return $entity;
    }
}
