<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

it('keeps directory handler workflows bound to their captured connection and filesystem', function () {
    $connection = DB::connection();
    $original = Storage::disk('local');
    $replacement = Storage::fake('replacement');
    $handler = new VersionedStorageHandler('lifecycle', 'local', true, $connection);
    $previousConnection = config('laravel-versioned-storage.connection');

    try {
        $stage = $handler->stage(function (FilesystemAdapter $files) use ($replacement): void {
            $files->put('one', 'initial');
            Storage::set('local', $replacement);
            config()->set('laravel-versioned-storage.connection', 'unconfigured');
            $files->put('one', 'completed');
        });
        $snapshot = "lifecycle/{$stage->version}/one";
        $replacement->put($snapshot, 'unrelated');

        expect($stage->publish())->toBe($stage->version)
            ->and($handler->get('one'))->toBe('completed')
            ->and($handler->exists('one'))->toBeTrue()
            ->and($handler->versions())->toBe([$stage->version]);

        $discarded = $handler->stage(function (FilesystemAdapter $files): void {
            $files->put('one', 'discarded');
        });
        $discarded->discard();
        $discarded->discard();
        $original->assertMissing("lifecycle/{$discarded->version}");

        $current = $handler->write(function (FilesystemAdapter $files): void {
            $files->put('one', 'current');
        }, cleanup: true);
        $handler->cleanup();
        $handler->recoverStages();

        expect($handler->versions())->toBe([$current])
            ->and($handler->get('one'))->toBe('current')
            ->and($replacement->get($snapshot))->toBe('unrelated')
            ->and($connection->table('versioned_storage_operations')->count())->toBe(0);
        $original->assertMissing($snapshot);
        $original->assertExists("lifecycle/{$current}/one");
    } finally {
        Storage::set('local', $original);
        config()->set('laravel-versioned-storage.connection', $previousConnection);
    }
});

it('isolates overlapping file stage lifetimes and never revives a retired publication on retry', function () {
    $firstHandler = new VersionedStorageHandler('interleaved', 'local', false);
    $secondHandler = new VersionedStorageHandler('interleaved', 'local', false);
    $writer = static fn (string $contents): Closure => static function (FilesystemAdapter $files, string $version) use ($contents): void {
        $files->put($version, $contents);
    };
    $first = $firstHandler->stage($writer('first'));
    $second = $secondHandler->stage($writer('second'));

    $second->publish(cleanup: true);
    $firstHandler->recoverStages();
    expect($firstHandler->get('one'))->toBe('second')
        ->and($firstHandler->versions())->toBe([$second->version]);

    $first->publish(cleanup: true);
    $secondHandler->cleanup();
    $secondHandler->cleanup();
    $second->publish(cleanup: true);

    expect($firstHandler->get('one'))->toBe('first')
        ->and($secondHandler->versions())->toBe([$first->version])
        ->and($secondHandler->exists('one', $second->version))->toBeFalse()
        ->and(DB::table('versioned_storage_stages')->where('version', $second->version)->value('state'))->toBe('published');
    Storage::disk('local')->assertMissing("interleaved/{$second->version}");
});
