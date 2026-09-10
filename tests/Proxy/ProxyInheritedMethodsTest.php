<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use SybaseORM\Proxy\ProxyGenerator;

class BaseEntity
{
    protected ?int $id = null;
    protected ?string $name = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}

class ChildEntity extends BaseEntity
{
    protected ?string $role = null;

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }
}

final class ProxyInheritedMethodsTest extends TestCase
{
    private string $proxyDir;
    private ProxyGenerator $generator;

    protected function setUp(): void
    {
        $this->proxyDir = sys_get_temp_dir() . '/sybase_orm_inherited_proxy_' . uniqid();
        $this->generator = new ProxyGenerator($this->proxyDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->proxyDir)) {
            $files = glob($this->proxyDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->proxyDir);
        }
    }

    public function testInheritedGetterTriggersInitialization(): void
    {
        $initialized = false;

        $proxy = $this->generator->createProxy(ChildEntity::class, function ($p) use (&$initialized) {
            $initialized = true;
            $p->setName('Initialized Name');
            $p->setRole('Admin');
        });

        $this->assertFalse($initialized);
        $this->assertFalse($proxy->__isInitialized());

        // Calling inherited method should trigger initialization
        $name = $proxy->getName();

        $this->assertTrue($initialized);
        $this->assertTrue($proxy->__isInitialized());
        $this->assertSame('Initialized Name', $name);
        $this->assertSame('Admin', $proxy->getRole());
    }
}
