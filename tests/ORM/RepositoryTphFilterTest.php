<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\Type\TypeCaster;

final class RepositoryTphFilterTest extends TestCase
{
    public function testChildRepositoryAutomaticallyInjectsDiscriminatorCondition(): void
    {
        $metadataReader = new MetadataReader();
        $typeCaster = new TypeCaster();

        $capturedOql = null;
        $capturedParams = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataReader')->willReturn($metadataReader);
        $em->method('getTypeCaster')->willReturn($typeCaster);

        $em->method('query')
            ->willReturnCallback(function (string $oql, array $params = []) use (&$capturedOql, &$capturedParams) {
                $capturedOql = $oql;
                $capturedParams = $params;
                return [];
            });

        $repo = new EntityRepository($em, TestEmailNotification::class);
        $repo->findBy(['message' => 'Hello']);

        $this->assertIsString($capturedOql);
        // Must contain discriminator condition
        $this->assertStringContainsString("e.type = 'email'", $capturedOql);
        $this->assertStringContainsString("e.message = :p0", $capturedOql);
        $this->assertSame(['p0' => 'Hello'], $capturedParams);
    }

    public function testRootRepositoryDoesNotRestrictBySingleDiscriminator(): void
    {
        $metadataReader = new MetadataReader();
        $typeCaster = new TypeCaster();

        $capturedOql = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataReader')->willReturn($metadataReader);
        $em->method('getTypeCaster')->willReturn($typeCaster);

        $em->method('query')
            ->willReturnCallback(function (string $oql, array $params = []) use (&$capturedOql) {
                $capturedOql = $oql;
                return [];
            });

        $repo = new EntityRepository($em, TestNotification::class);
        $repo->findBy(['message' => 'Hello']);

        $this->assertIsString($capturedOql);
        // Root entity should NOT inject a specific discriminator condition
        $this->assertStringNotContainsString("e.type = 'email'", $capturedOql);
        $this->assertStringNotContainsString("e.type = 'sms'", $capturedOql);
        $this->assertStringContainsString("e.message = :p0", $capturedOql);
    }
}
