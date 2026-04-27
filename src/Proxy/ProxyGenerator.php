<?php

declare(strict_types=1);

namespace SybaseORM\Proxy;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use SybaseORM\ORM\IdentityMapInterface;

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
        private readonly ?IdentityMapInterface $identityMap = null,
    ) {
        if (!is_dir($this->proxyDir)) {
            mkdir($this->proxyDir, 0777, true);
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
                mkdir($dir, 0777, true);
            }

            file_put_contents($filePath, $code);
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
     * @param mixed   $id           The entity identifier
     * @param Closure $initializer  Closure that receives the proxy and loads its data
     */
    public function createProxy(string $entityClass, mixed $id, Closure $initializer): object
    {
        $proxyClassName = $this->generateProxyClass($entityClass);

        $identityMap = $this->identityMap;
        $wrappedInitializer = function (object $proxy) use ($initializer, $entityClass, $id, $identityMap): void {
            $initializer($proxy);

            if ($identityMap !== null) {
                $identityMap->put($entityClass, $id, $proxy);
            }
        };

        $reflection = new ReflectionClass($proxyClassName);
        $proxy = $reflection->newInstanceWithoutConstructor();

        $proxy->__setInitializer($wrappedInitializer);

        return $proxy;
    }

    /**
     * Returns the file path where the proxy class will be stored.
     */
    private function getProxyFilePath(string $entityClass): string
    {
        return $this->proxyDir . '/' . str_replace('\\', '_', $entityClass) . 'Proxy.php';
    }

    /**
     * Generates the PHP code for the proxy class.
     */
    private function generateProxyCode(string $entityClass): string
    {
        $reflection = new ReflectionClass($entityClass);
        $shortProxyName = str_replace('\\', '_', $entityClass) . 'Proxy';
        $getterOverrides = $this->generateGetterOverrides($reflection);

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

        // Getter overrides
        $code .= $getterOverrides;

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
     * Generates method overrides for all public getter methods of the entity.
     */
    private function generateGetterOverrides(ReflectionClass $reflection): string
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

            // Only override getters (methods starting with "get" or "is" with no required params)
            if (!$this->isGetter($method)) {
                continue;
            }

            $returnType = $this->getReturnTypeString($method);
            $returnTypeDecl = $returnType !== '' ? ": {$returnType}" : '';

            $code .= "    public function {$name}(){$returnTypeDecl}\n";
            $code .= "    {\n";
            $code .= "        \$this->__initialize();\n";
            $code .= "        return parent::{$name}();\n";
            $code .= "    }\n\n";
        }

        return $code;
    }

    /**
     * Determines if a method is a getter (starts with "get" or "is", no required params).
     */
    private function isGetter(ReflectionMethod $method): bool
    {
        $name = $method->getName();
        $isGetterName = str_starts_with($name, 'get')
            || str_starts_with($name, 'is')
            || str_starts_with($name, 'has')
            || str_starts_with($name, 'can')
            || str_starts_with($name, 'should');

        if (!$isGetterName) {
            return false;
        }

        return $method->getNumberOfRequiredParameters() === 0;
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

        $typeStr = '';
        if ($returnType instanceof \ReflectionNamedType) {
            if ($returnType->allowsNull() && $returnType->getName() !== 'mixed') {
                $typeStr = '?';
            }
            if (!$returnType->isBuiltin()) {
                $typeStr .= '\\';
            }
            $typeStr .= $returnType->getName();
        } elseif ($returnType instanceof \ReflectionUnionType) {
            $parts = [];
            foreach ($returnType->getTypes() as $type) {
                $part = '';
                if (!$type->isBuiltin()) {
                    $part .= '\\';
                }
                $part .= $type->getName();
                $parts[] = $part;
            }
            $typeStr = implode('|', $parts);
        } elseif ($returnType instanceof \ReflectionIntersectionType) {
            $parts = [];
            foreach ($returnType->getTypes() as $type) {
                $part = '';
                if (!$type->isBuiltin()) {
                    $part .= '\\';
                }
                $part .= $type->getName();
                $parts[] = $part;
            }
            $typeStr = implode('&', $parts);
        }

        return $typeStr;
    }
}
