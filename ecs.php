<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\{ClassDefinitionFixer, OrderedClassElementsFixer, OrderedTraitsFixer};
use PhpCsFixer\Fixer\Import\{NoUnusedImportsFixer, OrderedImportsFixer};
use PhpCsFixer\Fixer\LanguageConstruct\NullableTypeDeclarationFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocTypesOrderFixer;
use PhpCsFixer\Fixer\PhpUnit\PhpUnitTestCaseStaticMethodCallsFixer;
use PhpCsFixer\Fixer\StringNotation\SingleQuoteFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withConfiguredRule(
        ClassDefinitionFixer::class,
        [
            'space_before_parenthesis' => true,
        ],
    )
    ->withConfiguredRule(
        NullableTypeDeclarationFixer::class,
        [
            'syntax' => 'union',
        ],
    )
    ->withConfiguredRule(
        OrderedClassElementsFixer::class,
        [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected',
                'constant_private',
                'case',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'method_public_abstract',
                'method_protected_abstract',
                'method_public',
                'method_protected',
                'method_private',
            ],
            'sort_algorithm' => 'alpha',
        ],
    )
    ->withConfiguredRule(
        OrderedImportsFixer::class,
        [
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
            'sort_algorithm' => 'alpha',
        ],
    )
    ->withConfiguredRule(
        PhpdocTypesOrderFixer::class,
        [
            'sort_algorithm' => 'none',
            'null_adjustment' => 'always_last',
        ],
    )
    ->withConfiguredRule(
        PhpUnitTestCaseStaticMethodCallsFixer::class,
        [
            'call_type' => 'self',
        ],
    )
    ->withFileExtensions(['php'])
    ->withPaths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ],
    )
    ->withPhpCsFixerSets(perCS30: true)
    ->withPreparedSets(
        cleanCode: true,
        comments: true,
        docblocks: true,
        namespaces: true,
        strict: true,
    )
    ->withRules(
        [
            NoUnusedImportsFixer::class,
            OrderedTraitsFixer::class,
            SingleQuoteFixer::class,
        ]
    );
