<?php

declare(strict_types=1);

namespace SybaseORM\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Prevents any reference to the Symfony\ namespace in the ORM library.
 * This ensures the ORM package remains framework-agnostic.
 *
 * Detects:
 * - use Symfony\... import statements
 * - instanceof Symfony\... expressions
 * - Class-string references to Symfony\ namespace (type hints, new expressions, static calls)
 *
 * @implements Rule<Node>
 */
final class NoSymfonyImportsRule implements Rule
{
    private const FORBIDDEN_PREFIX = 'Symfony\\';
    private const ERROR_MESSAGE = 'The ORM library must not reference the Symfony namespace. Found reference to "%s". Move Symfony-dependent code to the bundle package.';

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Use_) {
            return $this->processUseStatement($node);
        }

        if ($node instanceof Node\Expr\Instanceof_) {
            return $this->processInstanceof($node);
        }

        if ($node instanceof Node\Expr\New_) {
            return $this->processNew($node);
        }

        if ($node instanceof Node\Expr\StaticCall) {
            return $this->processStaticCall($node);
        }

        if ($node instanceof Node\Stmt\Class_) {
            return $this->processClassStatement($node);
        }

        if ($node instanceof Node\Param) {
            return $this->processParam($node);
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            return $this->processReturnType($node);
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processUseStatement(Use_ $node): array
    {
        $errors = [];

        foreach ($node->uses as $use) {
            $name = $use->name->toString();

            if ($this->isSymfonyNamespace($name)) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $name)
                )->build();
            }
        }

        return $errors;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processInstanceof(Node\Expr\Instanceof_ $node): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $name = $node->class->toString();

        if ($this->isSymfonyNamespace($name)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $name)
                )->build(),
            ];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processNew(Node\Expr\New_ $node): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $name = $node->class->toString();

        if ($this->isSymfonyNamespace($name)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $name)
                )->build(),
            ];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processStaticCall(Node\Expr\StaticCall $node): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $name = $node->class->toString();

        if ($this->isSymfonyNamespace($name)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $name)
                )->build(),
            ];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processClassStatement(Node\Stmt\Class_ $node): array
    {
        $errors = [];

        if ($node->extends !== null && $this->isSymfonyNamespace($node->extends->toString())) {
            $errors[] = RuleErrorBuilder::message(
                sprintf(self::ERROR_MESSAGE, $node->extends->toString())
            )->build();
        }

        foreach ($node->implements as $interface) {
            if ($this->isSymfonyNamespace($interface->toString())) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $interface->toString())
                )->build();
            }
        }

        return $errors;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processParam(Node\Param $node): array
    {
        if ($node->type === null) {
            return [];
        }

        return $this->checkTypeNode($node->type);
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processReturnType(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): array
    {
        if ($node->returnType === null) {
            return [];
        }

        return $this->checkTypeNode($node->returnType);
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function checkTypeNode(Node $typeNode): array
    {
        if ($typeNode instanceof Name && $this->isSymfonyNamespace($typeNode->toString())) {
            return [
                RuleErrorBuilder::message(
                    sprintf(self::ERROR_MESSAGE, $typeNode->toString())
                )->build(),
            ];
        }

        if ($typeNode instanceof Node\NullableType) {
            return $this->checkTypeNode($typeNode->type);
        }

        if ($typeNode instanceof Node\UnionType || $typeNode instanceof Node\IntersectionType) {
            $errors = [];
            foreach ($typeNode->types as $type) {
                $errors = array_merge($errors, $this->checkTypeNode($type));
            }
            return $errors;
        }

        return [];
    }

    private function isSymfonyNamespace(string $name): bool
    {
        return str_starts_with($name, self::FORBIDDEN_PREFIX);
    }
}
