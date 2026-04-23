<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\Proxy\LazyLoadingProxy;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\Tests\Proxy\Fixtures\ArticleEntity;

/**
 * @covers \SybaseORM\Proxy\ProxyGenerator
 */
final class ProxyGeneratorTest extends TestCase
{
    private string $proxyDir;
    private ProxyGenerator $generator;

    protected function setUp(): void
    {
        $this->proxyDir = sys_get_temp_dir() . '/sybase_orm_proxy_test_' . uniqid();
        $this->generator = new ProxyGenerator($this->proxyDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->proxyDir);
    }

    // ── Task 14.1: Proxy class generation ──

    public function testGenerateProxyClassCreatesFileInConfiguredDirectory(): void
    {
        $proxyClass = $this->generator->generateProxyClass(ArticleEntity::class);

        $expectedFile = $this->proxyDir . '/' . str_replace('\\', '_', ArticleEntity::class) . 'Proxy.php';
        $this->assertFileExists($expectedFile);
        $this->assertTrue(class_exists($proxyClass, false));
    }

    public function testProxyExtendsEntityClass(): void
    {
        $proxyClass = $this->generator->generateProxyClass(ArticleEntity::class);

        $this->assertTrue(is_subclass_of($proxyClass, ArticleEntity::class));
    }

    public function testProxyImplementsLazyLoadingProxyInterface(): void
    {
        $proxyClass = $this->generator->generateProxyClass(ArticleEntity::class);

        $reflection = new \ReflectionClass($proxyClass);
        $this->assertTrue($reflection->implementsInterface(LazyLoadingProxy::class));
    }

    public function testGenerateProxyClassReturnsSameClassName(): void
    {
        $class1 = $this->generator->generateProxyClass(ArticleEntity::class);
        $class2 = $this->generator->generateProxyClass(ArticleEntity::class);

        $this->assertSame($class1, $class2);
    }

    public function testProxyFileIsCachedOnDisk(): void
    {
        $this->generator->generateProxyClass(ArticleEntity::class);

        $filePath = $this->proxyDir . '/' . str_replace('\\', '_', ArticleEntity::class) . 'Proxy.php';
        $this->assertFileExists($filePath);

        // A second generator instance pointing to the same dir should find the cached file
        $generator2 = new ProxyGenerator($this->proxyDir);
        $class = $generator2->generateProxyClass(ArticleEntity::class);
        $this->assertTrue(class_exists($class, false));
    }

    // ── Task 14.1: Lazy loading on property access ──

    public function testAccessingGetterTriggersInitializer(): void
    {
        $initialized = false;

        $proxy = $this->generator->createProxy(
            ArticleEntity::class,
            1,
            function (object $proxy) use (&$initialized): void {
                $initialized = true;
                $this->setPrivateProperty($proxy, 'title', 'Loaded Title');
            }
        );

        $this->assertFalse($initialized);
        $this->assertInstanceOf(LazyLoadingProxy::class, $proxy);
        $this->assertFalse($proxy->__isInitialized());

        // Accessing getter triggers initialization
        $title = $proxy->getTitle();

        $this->assertTrue($initialized);
        $this->assertTrue($proxy->__isInitialized());
        $this->assertSame('Loaded Title', $title);
    }

    public function testIsGetterAlsoTriggersInitializer(): void
    {
        $initialized = false;

        $proxy = $this->generator->createProxy(
            ArticleEntity::class,
            2,
            function (object $proxy) use (&$initialized): void {
                $initialized = true;
                $this->setPrivateProperty($proxy, 'published', true);
            }
        );

        $this->assertFalse($initialized);

        $result = $proxy->isPublished();

        $this->assertTrue($initialized);
        $this->assertTrue($result);
    }

    public function testInitializerIsCalledOnlyOnce(): void
    {
        $callCount = 0;

        $proxy = $this->generator->createProxy(
            ArticleEntity::class,
            3,
            function (object $proxy) use (&$callCount): void {
                $callCount++;
                $this->setPrivateProperty($proxy, 'title', 'Once');
            }
        );

        $proxy->getTitle();
        $proxy->getTitle();
        $proxy->getContent();

        $this->assertSame(1, $callCount);
    }

    // ── Task 14.1: Serialization forces loading ──

    public function testSerializationForcesInitialization(): void
    {
        $initialized = false;

        $proxy = $this->generator->createProxy(
            ArticleEntity::class,
            4,
            function (object $proxy) use (&$initialized): void {
                $initialized = true;
                $this->setPrivateProperty($proxy, 'id', 4);
                $this->setPrivateProperty($proxy, 'title', 'Serialized');
                $this->setPrivateProperty($proxy, 'content', 'Body');
                $this->setPrivateProperty($proxy, 'published', true);
            }
        );

        $this->assertFalse($initialized);

        $serialized = serialize($proxy);

        $this->assertTrue($initialized);
        $this->assertStringContainsString('Serialized', $serialized);
    }

    // ── Task 14.2: Proxy registers in Identity Map after initialization ──

    public function testProxyRegistersInIdentityMapAfterInitialization(): void
    {
        $identityMap = new IdentityMap();
        $generator = new ProxyGenerator($this->proxyDir, $identityMap);

        $proxy = $generator->createProxy(
            ArticleEntity::class,
            10,
            function (object $proxy): void {
                $this->setPrivateProperty($proxy, 'title', 'Mapped');
            }
        );

        // Before initialization, not in identity map
        $this->assertFalse($identityMap->contains(ArticleEntity::class, 10));

        // Trigger initialization
        $proxy->getTitle();

        // After initialization, registered in identity map
        $this->assertTrue($identityMap->contains(ArticleEntity::class, 10));
        $this->assertSame($proxy, $identityMap->get(ArticleEntity::class, 10));
    }

    // ── Task 14.2: Lazy relationship integration ──

    public function testProxyWorksWithLazyRelationshipPattern(): void
    {
        $identityMap = new IdentityMap();
        $generator = new ProxyGenerator($this->proxyDir, $identityMap);

        // Simulate a LAZY relationship: create a proxy for the related entity
        $authorProxy = $generator->createProxy(
            ArticleEntity::class,
            99,
            function (object $proxy): void {
                $this->setPrivateProperty($proxy, 'title', 'Author Article');
                $this->setPrivateProperty($proxy, 'content', 'Author content');
            }
        );

        // The proxy should not be initialized until accessed
        $this->assertFalse($authorProxy->__isInitialized());

        // Accessing a property triggers lazy load
        $this->assertSame('Author Article', $authorProxy->getTitle());
        $this->assertTrue($authorProxy->__isInitialized());

        // And it's now in the identity map
        $this->assertTrue($identityMap->contains(ArticleEntity::class, 99));
    }

    // ── Helpers ──

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(ArticleEntity::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
