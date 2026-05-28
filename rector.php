<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withSetProviders(LaravelSetProvider::class)
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_IF_HELPERS,
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withComposerBased(laravel: true)
    ->withCache(
        cacheDirectory: '/tmp/rector-laravel-ragno',
        cacheClass: FileCacheStorage::class,
    )
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // We override many Illuminate\Database\Connection methods; the
        // #[\Override] attribute is noise here and matches the org baseline.
        AddOverrideAttributeToOverriddenMethodsRector::class,
        // Keep readable interpolation ("Checking {$name}...") over sprintf().
        EncapsedStringsToSprintfRector::class,
        // The following property-to-attribute rules emit Laravel-13-only
        // attributes (Illuminate\Console\Attributes\* and
        // Illuminate\Database\Eloquent\Attributes\Connection / Table /
        // WithoutTimestamps). This package supports both Laravel 12 and 13,
        // so we keep the cross-version property form.
        RectorLaravel\Rector\Class_\DescriptionPropertyToDescriptionAttributeRector::class,
        RectorLaravel\Rector\Class_\SignaturePropertyToSignatureAttributeRector::class,
        RectorLaravel\Rector\Class_\ConnectionPropertyToConnectionAttributeRector::class,
        RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector::class,
        RectorLaravel\Rector\Class_\WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets();
