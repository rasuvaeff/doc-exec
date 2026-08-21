<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
        __DIR__ . '/benchmarks',
    ])
    // The CLI entry point is declared in composer.json "bin" and is part of
    // the public contract, but it has no .php extension, so the Finder needs
    // it named explicitly.
    ->append([__DIR__ . '/bin/doc-exec']);

return (new Config())
    ->setUsingCache(false)
    ->setRules([
        '@PER-CS3.0' => true,
        '@PHP83Migration' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
    ])
    ->setFinder($finder);
