<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\SubprocessFixture;

it('owns its database and processes and restores the previous configuration', function () {
    $previous = config('laravel-versioned-storage.connection');
    $configuration = ['driver' => 'sqlite', 'database' => ':memory:'];
    config()->set('database.connections.fixture-test', $configuration);
    $fixture = new SubprocessFixture('fixture-test');
    $process = null;

    try {
        expect(DB::connection('fixture-test')->getSchemaBuilder()->hasTable('versioned_storage_operations'))->toBeTrue();
        $process = $fixture->start([PHP_BINARY, '-r', 'echo "ready"; fflush(STDOUT); sleep(30);']);
        ProcessWait::until(fn (): bool => str_contains($process->getOutput(), 'ready'), [$process], 5);
        expect(is_file($fixture->database))->toBeTrue();
    } finally {
        $fixture->close();
    }

    expect($process->isRunning())->toBeFalse()
        ->and(is_dir($fixture->directory))->toBeFalse()
        ->and(config('laravel-versioned-storage.connection'))->toBe($previous)
        ->and(config('database.connections.fixture-test'))->toBe($configuration);
});

it('does not replace or purge an already resolved database connection', function () {
    $connection = DB::connection();
    $name = $connection->getName();
    $previous = config('laravel-versioned-storage.connection');

    expect(fn () => new SubprocessFixture($name))
        ->toThrow(RuntimeException::class, 'already resolved')
        ->and(DB::connection($name))->toBe($connection)
        ->and(config('laravel-versioned-storage.connection'))->toBe($previous);
});
