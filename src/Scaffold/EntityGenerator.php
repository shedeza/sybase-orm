<?php

declare(strict_types=1);

namespace SybaseORM\Scaffold;

class EntityGenerator
{
    private array $typeMap = [
        'int' => 'int',
        'tinyint' => 'int',
        'smallint' => 'int',
        'bigint' => 'int', // Should be string or int depending on system, fallback to int
        'numeric' => 'float',
        'decimal' => 'float',
        'float' => 'float',
        'real' => 'float',
        'money' => 'float',
        'smallmoney' => 'float',
        'varchar' => 'string',
        'char' => 'string',
        'text' => 'string',
        'nchar' => 'string',
        'nvarchar' => 'string',
        'datetime' => '\DateTime',
        'smalldatetime' => '\DateTime',
        'date' => '\DateTime',
        'time' => '\DateTime',
        'bit' => 'bool',
        'binary' => 'string',
        'varbinary' => 'string',
        'image' => 'string',
    ];

    public function __construct(
        private readonly string $namespace = 'App\\Entity'
    ) {}

    public function generate(array $tableDefinition, string $targetDirectory): string
    {
        $tableName = $tableDefinition['name'];
        $className = $this->camelize($tableName);

        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "namespace " . $this->namespace . ";\n\n";
        $code .= "use SybaseORM\\Attribute\\Entity;\n";
        $code .= "use SybaseORM\\Attribute\\Column;\n";
        $code .= "use SybaseORM\\Attribute\\Id;\n\n";

        $code .= "#[Entity(table: '$tableName')]\n";
        $code .= "class $className\n{\n";

        foreach ($tableDefinition['columns'] as $colName => $colDef) {
            $propName = $this->camelize($colName, true);
            $phpType = $this->typeMap[strtolower($colDef['type'])] ?? 'string';
            $nullable = $colDef['nullable'] ? '?' : '';

            $isId = $colDef['isPrimaryKey'];

            if ($isId) {
                $code .= "    #[Id]\n";
            }

            $columnParams = ["name: '$colName'"];
            if ($phpType !== 'string' && $phpType !== 'int') {
                $columnParams[] = "type: '" . $colDef['type'] . "'";
            }
            if ($colDef['nullable']) {
                $columnParams[] = 'nullable: true';
            }

            $code .= "    #[Column(" . implode(', ', $columnParams) . ")]\n";
            $code .= "    public $nullable$phpType $$propName;\n\n";
        }

        $code .= "}\n";

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0o755, true);
        }

        $filePath = rtrim($targetDirectory, '/') . '/' . $className . '.php';
        file_put_contents($filePath, $code);

        return $filePath;
    }

    private function camelize(string $input, bool $lcfirst = false): string
    {
        $str = str_replace(' ', '', ucwords(str_replace('_', ' ', $input)));
        return $lcfirst ? lcfirst($str) : $str;
    }
}
