<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PreUpdate;
use SybaseORM\Attribute\PostUpdate;
use SybaseORM\Attribute\PreRemove;
use SybaseORM\Attribute\PostRemove;

final class LifecycleHooksTest extends TestCase
{
    public function testHasLifecycleHooksTargetsClass(): void
    {
        $ref = new \ReflectionClass(HasLifecycleHooks::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attribute->flags);
    }

    public function testHasLifecycleHooksOnEntity(): void
    {
        $ref = new \ReflectionClass(Fixtures\AuditableEntity::class);
        $attrs = $ref->getAttributes(HasLifecycleHooks::class);
        $this->assertCount(1, $attrs);
    }

    /**
     * @dataProvider hookAttributeProvider
     */
    public function testHookAttributeTargetsMethod(string $attributeClass): void
    {
        $ref = new \ReflectionClass($attributeClass);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);

        $attribute = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_METHOD, $attribute->flags);
    }

    /**
     * @dataProvider hookMethodProvider
     */
    public function testHookAttributeOnMethod(string $methodName, string $attributeClass): void
    {
        $ref = new \ReflectionMethod(Fixtures\AuditableEntity::class, $methodName);
        $attrs = $ref->getAttributes($attributeClass);
        $this->assertCount(1, $attrs);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hookAttributeProvider(): array
    {
        return [
            'PrePersist'  => [PrePersist::class],
            'PostPersist' => [PostPersist::class],
            'PreUpdate'   => [PreUpdate::class],
            'PostUpdate'  => [PostUpdate::class],
            'PreRemove'   => [PreRemove::class],
            'PostRemove'  => [PostRemove::class],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hookMethodProvider(): array
    {
        return [
            'PrePersist'  => ['onPrePersist', PrePersist::class],
            'PostPersist' => ['onPostPersist', PostPersist::class],
            'PreUpdate'   => ['onPreUpdate', PreUpdate::class],
            'PostUpdate'  => ['onPostUpdate', PostUpdate::class],
            'PreRemove'   => ['onPreRemove', PreRemove::class],
            'PostRemove'  => ['onPostRemove', PostRemove::class],
        ];
    }
}
