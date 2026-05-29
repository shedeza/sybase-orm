<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->exclude('vendor')
    ->exclude('fixtures');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_indentation' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try'],
        ],
        'multiline_whitespace_before_semicolons' => true,
        'ordered_imports' => true,
        'phpdoc_order' => true,
        'no_extra_blank_lines' => [
            'tokens' => ['break', 'continue', 'extra', 'return', 'throw', 'use'],
        ],
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
