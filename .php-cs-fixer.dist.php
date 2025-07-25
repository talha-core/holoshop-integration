<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in(__DIR__)
    ->exclude(['var', 'config'])
;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        '@PhpCsFixer' => true,
        'php_unit_test_class_requires_covers' => false,
    ])
    ->setFinder($finder)
;
