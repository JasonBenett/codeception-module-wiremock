<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->name('*.php');

$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'no_extra_blank_lines' => true,
        'no_trailing_whitespace' => true,
        'single_quote' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
