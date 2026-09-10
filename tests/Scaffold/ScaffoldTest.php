<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Scaffold\SchemaIntrospector;
use SybaseORM\Scaffold\EntityGenerator;

/**
 * @covers \SybaseORM\Scaffold\SchemaIntrospector
 * @covers \SybaseORM\Scaffold\EntityGenerator
 */
final class ScaffoldTest extends TestCase
{
    public function testGeneratorCreatesFileWithCorrectTypes(): void
    {
        $generator = new EntityGenerator('SybaseORM\Tests\Scaffold\Generated');
        $def = [
            'name' => 'sys_users',
            'columns' => [
                'id' => [
                    'name' => 'id',
                    'type' => 'int',
                    'length' => 4,
                    'nullable' => false,
                    'isPrimaryKey' => true,
                ],
                'username' => [
                    'name' => 'username',
                    'type' => 'varchar',
                    'length' => 255,
                    'nullable' => false,
                    'isPrimaryKey' => false,
                ],
                'created_at' => [
                    'name' => 'created_at',
                    'type' => 'datetime',
                    'length' => 8,
                    'nullable' => true,
                    'isPrimaryKey' => false,
                ],
            ],
        ];

        $tmpDir = sys_get_temp_dir() . '/scaffold_test';
        $filePath = $generator->generate($def, $tmpDir);

        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);

        $this->assertStringContainsString('class SysUsers', $content);
        $this->assertStringContainsString('#[Id]', $content);
        $this->assertStringContainsString('public int $id;', $content);
        $this->assertStringContainsString('public string $username;', $content);
        $this->assertStringContainsString('public ?\DateTime $createdAt;', $content);

        unlink($filePath);
        rmdir($tmpDir);
    }
}
