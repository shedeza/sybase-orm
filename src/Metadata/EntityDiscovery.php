<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Discovers entity classes from filesystem directories.
 *
 * Centralizes the entity discovery logic used by EntityManager, ProxyGenerateCommand,
 * and MigrationsGenerateCommand to avoid code duplication.
 */
final class EntityDiscovery
{
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
    ) {
    }

    /**
     * Discovers entity classes from the given directories.
     *
     * @param string[] $directories Absolute paths to scan for PHP files
     * @return string[] Fully qualified class names of discovered entities
     */
    public function discoverEntityClasses(array $directories): array
    {
        $classes = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->extractClassName($file->getPathname());
                if ($className !== null && $this->metadataReader->isEntity($className)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    /**
     * Extracts the fully qualified class name from a PHP file.
     *
     * Uses PHP's tokenizer for reliable namespace and class name extraction,
     * handling PHP 8.0+ T_NAME_QUALIFIED tokens.
     */
    public function extractClassName(string $filePath): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $tokens[$j][1];
                    } elseif (is_string($tokens[$j]) && $tokens[$j] === ';') {
                        break;
                    } elseif (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
                $namespace = trim($ns);
            }

            if ($tokens[$i][0] === T_CLASS) {
                // Skip anonymous classes and ::class
                if ($i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break 2;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace . '\\' . $class : $class;

        return class_exists($fqcn) ? $fqcn : null;
    }
}
