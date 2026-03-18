<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([__DIR__ . '/config', __DIR__ . '/src', __DIR__ . '/tests'])
    ->withConfiguredRule(
        ArraySyntaxFixer::class,
        ['syntax' => 'short']
    )
    ->withRules([
        DeclareStrictTypesFixer::class,
        NoUnusedImportsFixer::class
    ])
    ->withPreparedSets(psr12: true);
