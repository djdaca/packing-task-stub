<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'array_syntax' => ['syntax' => 'short'],
        'braces' => true,
        'binary_operator_spaces' => true,
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'method_chaining_indentation' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'single_line_throw' => false,
        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_empty_phpdoc' => true,

        'phpdoc_scalar' => true,

        'visibility_required' => ['elements' => ['property', 'method', 'const']],
        'yoda_style' => false,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
    ])
    ->setFinder(
        Finder::create()
            ->in(['src', 'tests'])
            ->append([__FILE__])
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
    )
    ->setUsingCache(true);
