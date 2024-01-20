<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddMethodCallBasedStrictParamTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeBasedOnPHPUnitDataProviderRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeDeclarationRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationBasedOnParentClassMethodRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths(
        [
            __DIR__ . '/src',
            //__DIR__ . '/tests',
        ]
    );

    $rectorConfig->rules(
        [
            AddMethodCallBasedStrictParamTypeRector::class,
            AddParamTypeBasedOnPHPUnitDataProviderRector::class,
            AddParamTypeDeclarationRector::class,
            AddReturnTypeDeclarationBasedOnParentClassMethodRector::class,
            AddVoidReturnTypeWhereNoReturnRector::class,
        ],
    );

    $rectorConfig->sets(
        [
            LevelSetList::UP_TO_PHP_81,
        ],
    );
};
