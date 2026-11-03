<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

$source = getenv('VERSIONED_STORAGE_BENCHMARK_SOURCE');
$root = realpath($source === false ? __DIR__.'/..' : $source);

if ($root === false || ! is_dir($root.'/src') || ! is_dir($root.'/database/migrations')) {
    throw new RuntimeException('Benchmark source must point to a package checkout with src and database/migrations directories.');
}

define('VERSIONED_STORAGE_BENCHMARK_ROOT', $root);

// Use one harness and dependency installation while selecting each revision's package code.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'SkyRaptor\\LaravelVersionedStorage\\';

    if (! str_starts_with($class, $prefix) || str_starts_with($class, $prefix.'Tests\\')) {
        return;
    }

    $path = $root.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (! is_file($path)) {
        throw new RuntimeException("Benchmark source does not contain class [{$class}].");
    }

    require_once $path;
}, true, true);

require_once __DIR__.'/StorageEnvironment.php';
