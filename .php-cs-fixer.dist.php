<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/tests',
        __DIR__.'/examples',
    ])
    ->append([__FILE__])
    ->name('*.php');

$config = new PhpCsFixer\Config();

// Runs on the PHP versions newer than the one the rules were written for
if (method_exists($config, 'setUnsupportedPhpVersionAllowed')) {
    $config->setUnsupportedPhpVersionAllowed(true);
}

if (class_exists(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::class)) {
    $config->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect());
}

return $config
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // It renames classes after their file: the examples name theirs after what they do
        'psr_autoloading' => false,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'phpdoc_to_comment' => [
            'ignored_tags' => ['var'],
        ],
        'php_unit_test_case_static_method_calls' => [
            'call_type' => 'this',
        ],
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
