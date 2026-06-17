<?php

declare(strict_types=1);

namespace SybaseORM\Proxy;

use Closure;
use ReflectionClass;
use ReflectionMethod;

/**
 * Generates proxy classes that extend entity classes and implement lazy loading.
 *
 * Generated proxies intercept all public getter methods to trigger initialization
 * on first access. The proxy holds a $__initializer closure that loads the real
 * entity data from the database when needed.
 */
final class ProxyGenerator
{
    public function __construct(
        private readonly string $proxyDir,
        private readonly int $directoryPermissions = 0o777,
        private readonly int $filePermissions = 0o666,
    ) {
        if (!is_dir($this->proxyDir)) {
            mkdir($this->proxyDir, $this->directoryPermissions, true);
            @chmod($this->proxyDir, $this->directoryPermissions);
        }
    }

    /**
     * Returns the fully qualified proxy class name for a given entity class.
     */
    public function getProxyClassName(string $entityClass): string
    {
        return 'SybaseORM\\Proxy\\Generated\\' . str_replace('\\', '_', $entityClass) . 'Proxy';
    }

    /**
     * Generates the proxy class file for the given entity class.
     * Returns the fully qualified proxy class name.
     *
     * If the proxy file already exists (cached), generation is skipped.
     * Uses atomic write (temp file + rename) to prevent race conditions.
     */
    public function generateProxyClass(string $entityClass): string
    {
        $proxyClassName = $this->getProxyClassName($entityClass);
        $filePath = $this->getProxyFilePath($entityClass);

        // Always ensure the file exists on disk for caching
        if (!file_exists($filePath)) {
            $code = $this->generateProxyCode($entityClass);

            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, $this->directoryPermissions, true);
                @chmod($dir, $this->directoryPermissions);
            }

            // Atomic write: write to temp file then rename to avoid TOCTOU races
            $tmpFile = $filePath . '.tmp.' . getmypid();
            $written = file_put_contents($tmpFile, $code);

            if ($written === false) {
                @unlink($tmpFile);
                throw new \RuntimeException(sprintf(
                    'Failed to write proxy file: %s',
                    $filePath,
                ));
            }

            @chmod($tmpFile, $this->filePermissions);
            rename($tmpFile, $filePath);
        }

        // Load the class into memory if not already loaded
        if (!class_exists($proxyClassName, false)) {
            require_once $filePath;
        }

        return $proxyClassName;
    }

    /**
     * Creates a proxy instance for the given entity class with the provided initializer.
     *
     * @param string  $entityClass  Fully qualified entity class name
     * @param Closure $initializer  Closure that receives the proxy and loads its data
     */
    public function createProxy(string $entityClass, Closure $initializer): object
    {
        $proxyClassName = $this->generateProxyClass($entityClass);

        $reflection = new ReflectionClass($proxyClassName);
        $proxy = $reflection->newInstanceWithoutConstructor();

        $proxy->__setInitializer($initializer);

        return $proxy;
    }

    /**
     * Returns the file path where the proxy class will be stored.
     */
    private function getProxyFilePath(string $entityClass): string
    {
        $safeName = str_replace(['\\', '/', '..'], '_', $entityClass);

        return $this->proxyDir . '/' . $safeName . 'Proxy.php';
    }

    /**
     * Generates the PHP code for the proxy class.
     */
    private function generateProxyCode(string $entityClass): string
    {
        $reflection = new ReflectionClass($entityClass);
        $shortProxyName = str_replace('\\', '_', $entityClass) . 'Proxy';
        $methodOverrides = $this->generateMethodOverrides($reflection);

        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "namespace SybaseORM\\Proxy\\Generated;\n\n";
        $code .= "use SybaseORM\\Proxy\\LazyLoadingProxy;\n\n";
        $code .= "class {$shortProxyName} extends \\{$entityClass} implements LazyLoadingProxy\n";
        $code .= "{\n";
        $code .= "    private \\Closure|null \$__initializer = null;\n";
        $code .= "    private bool \$__initialized = false;\n\n";
        // __isInitialized
        $code .= "    public function __isInitialized(): bool\n";
        $code .= "    {\n";
        $code .= "        return \$this->__initialized;\n";
        $code .= "    }\n\n";

        // __initialize
        $code .= "    public function __initialize(): void\n";
        $code .= "    {\n";
        $code .= "        if (\$this->__initialized) {\n";
        $code .= "            return;\n";
        $code .= "        }\n";
        $code .= "        \$this->__initialized = true;\n";
        $code .= "        if (\$this->__initializer !== null) {\n";
        $code .= "            (\$this->__initializer)(\$this);\n";
        $code .= "        }\n";
        $code .= "    }\n\n";

        // __setInitializer
        $code .= "    public function __setInitializer(?\\Closure \$initializer): void\n";
        $code .= "    {\n";
        $code .= "        \$this->__initializer = \$initializer;\n";
        $code .= "    }\n\n";

        // __getInitializer
        $code .= "    public function __getInitializer(): ?\\Closure\n";
        $code .= "    {\n";
        $code .= "        return \$this->__initializer;\n";
        $code .= "    }\n\n";

        // Method overrides
        $code .= $methodOverrides;

        // __serialize — solo si la entidad padre define __serialize()
        if ($reflection->hasMethod('__serialize')) {
            $code .= "    public function __serialize(): array\n";
            $code .= "    {\n";
            $code .= "        \$this->__initialize();\n";
            $code .= "        return parent::__serialize();\n";
            $code .= "    }\n";
        }

        $code .= "}\n";

        return $code;
    }

    /**
     * Generates method overrides for all public methods of the entity.
     */
    private function generateMethodOverrides(ReflectionClass $reflection): string
    {
        $code = '';

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if ($method->isStatic() || $method->isFinal()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $name = $method->getName();

            // We intercept all public methods except magics and getters/setters/actions.
            // Magics handled manually or specially.
            if (str_starts_with($name, '__') && $name !== '__toString') {
                continue;
            }

            $returnType = $this->getReturnTypeString($method);
            $returnTypeDecl = $returnType !== '' ? ": {$returnType}" : '';
            $paramSignature = $this->getParameterSignature($method);
            $paramForward = $this->getParameterForwardList($method);

            $code .= "    public function {$name}({$paramSignature}){$returnTypeDecl}\n";
            $code .= "    {\n";
            $code .= "        \$this->__initialize();\n";
            if ($returnType === 'void') {
                $code .= "        parent::{$name}({$paramForward});\n";
            } else {
                $code .= "        return parent::{$name}({$paramForward});\n";
            }
            $code .= "    }\n\n";
        }

        return $code;
    }

    /**
     * Generates the parameter signature string for a method override.
     */
    private function getParameterSignature(ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $part = '';

            $type = $param->getType();
            if ($type !== null) {
                $part .= $this->getTypeString($type) . ' ';
            }

            $part .= '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                $part .= ' = ' . var_export($default, true);
            }

            $params[] = $part;
        }

        return implode(', ', $params);
    }

    /**
     * Generates the argument forwarding list for a parent:: call.
     */
    private function getParameterForwardList(ReflectionMethod $method): string
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $args[] = '$' . $param->getName();
        }

        return implode(', ', $args);
    }

    /**
     * Returns the return type declaration string for a method.
     */
    private function getReturnTypeString(ReflectionMethod $method): string
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return '';
        }

        return $this->getTypeString($returnType);
    }

    /**
     * Converts a ReflectionType to its string representation.
     * Handles named types, union types, and intersection types.
     */
    private function getTypeString(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $str = '';
            if ($type->allowsNull() && $type->getName() !== 'mixed') {
                $str = '?';
            }

            $typeName = $type->getName();
            $isSpecialType = in_array($typeName, ['static', 'self', 'parent'], true);

            if (!$type->isBuiltin() && !$isSpecialType) {
                $str .= '\\';
            }
            $str .= $typeName;

            return $str;
        }

        if ($type instanceof \ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $t) {
                $part = '';
                if ($t instanceof \ReflectionNamedType) {
                    $tName = $t->getName();
                    $isTPathSpecial = in_array($tName, ['static', 'self', 'parent'], true);
                    if (!$t->isBuiltin() && !$isTPathSpecial) {
                        $part .= '\\';
                    }
                    $part .= $tName;
                }
                $parts[] = $part;
            }

            return implode('|', $parts);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $t) {
                $part = '';
                if ($t instanceof \ReflectionNamedType) {
                    $tName = $t->getName();
                    $isTPathSpecial = in_array($tName, ['static', 'self', 'parent'], true);
                    if (!$t->isBuiltin() && !$isTPathSpecial) {
                        $part .= '\\';
                    }
                    $part .= $tName;
                }
                $parts[] = $part;
            }

            return implode('&', $parts);
        }

        return '';
    }
}
