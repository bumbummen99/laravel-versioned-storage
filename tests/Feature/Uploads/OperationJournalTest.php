<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('commits one mutation journal before each operation and removes it before the next', function () {
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $seen = [];
    foreach (['createDirectory', 'write', 'writeStream', 'copy', 'move', 'setVisibility', 'delete', 'deleteDirectory'] as $method) {
        $adapter->shouldReceive($method)->andReturnUsing(function (...$arguments) use ($disk, $method, &$seen): void {
            $journal = DB::table('versioned_storage_operations')->sole();
            expect($journal->operation)->toBe($method)
                ->and(DB::transactionLevel())->toBe(0);
            $seen[] = $method;
            $disk->getAdapter()->{$method}(...$arguments);
        });
    }
    Storage::set('journaled', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    $stage = VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files): void {
        expect(DB::transactionLevel())->toBe(0)
            ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
        $files->put('one', 'contents');
        expect(DB::table('versioned_storage_operations')->count())->toBe(0);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'stream');

        try {
            $files->put('stream', $stream);
        } finally {
            fclose($stream);
        }
        $files->copy('one', 'two');
        $files->move('two', 'three');
        $files->setVisibility('three', 'private');
        $files->delete('one');
        $files->makeDirectory('nested');
        $files->deleteDirectory('nested');
    }, disk: 'journaled');

    expect($seen)->toBe(['createDirectory', 'write', 'writeStream', 'copy', 'move', 'setVisibility', 'delete', 'createDirectory', 'deleteDirectory'])
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0)
        ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');
});

it('does not start an operation whose committed journal was reclaimed while the parent was paused', function () {
    $this->freezeTime();
    $claimed = false;
    $called = false;
    Event::listen(TransactionCommitted::class, function () use (&$claimed): void {
        if ($claimed || DB::table('versioned_storage_operations')->count() === 0) {
            return;
        }
        $claimed = true;
        $this->travelTo(Carbon::parse(DB::table('versioned_storage_operations')->value('cleanup_after')));
        VersionedStorage::directory('reports')->cleanup();
    });

    try {
        expect(fn () => VersionedStorage::directory('reports', function () use (&$called): void {
            $called = true;
        }))->toThrow(RuntimeException::class, 'timed out before it could start');
    } finally {
        Event::forget(TransactionCommitted::class);
    }

    expect($claimed)->toBeTrue()->and($called)->toBeFalse()
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
    Storage::disk('local')->assertMissing('reports');
});

it('rejects filesystem calls retained after a snapshot callback has completed', function () {
    $retained = null;
    $stage = VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files) use (&$retained): void {
        $files->put('one', 'contents');
        $retained = $files;
    });

    expect(fn () => $retained->put('late', 'contents'))->toThrow(RuntimeException::class, 'callback has already finished')
        ->and($stage->publish())->toBe($stage->version);
    Storage::disk('local')->assertMissing('reports/'.$stage->version.'/late');
});

it('does not perform a mutation if its durable operation journal cannot be recorded', function () {
    DB::statement("CREATE TRIGGER reject_operation BEFORE INSERT ON versioned_storage_operations BEGIN SELECT RAISE(FAIL, 'operation intent unavailable'); END");

    try {
        expect(fn () => VersionedStorage::put('report', 'contents'))
            ->toThrow(QueryException::class, 'operation intent unavailable');
    } finally {
        DB::statement('DROP TRIGGER reject_operation');
    }

    expect(VersionedStorage::versions('report'))->toBe([])
        ->and(DB::table('versioned_storage_operations')->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('report');
});

it('retains the journal and never publishes if acknowledging a successful mutation fails', function () {
    DB::statement("CREATE TRIGGER reject_operation_completion BEFORE DELETE ON versioned_storage_operations BEGIN SELECT RAISE(FAIL, 'acknowledgement unavailable'); END");

    try {
        expect(fn () => VersionedStorage::put('report', 'contents'))
            ->toThrow(QueryException::class, 'acknowledgement unavailable');
    } finally {
        DB::statement('DROP TRIGGER reject_operation_completion');
    }

    $journal = DB::table('versioned_storage_operations')->sole();
    expect(VersionedStorage::versions('report'))->toBe([])
        ->and(DB::table('versioned_storage_stages')->value('failed'))->toBe(1);
    Storage::disk('local')->assertExists('report/'.$journal->version);
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_operations')->count())->toBe(1);

    $this->travelTo(Carbon::parse($journal->cleanup_after));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_operations')->count())->toBe(0);
    Storage::disk('local')->assertMissing('report/'.$journal->version);
});

it('fails the whole stage after a caught mutation failure and retains only its uncertain journal', function () {
    $current = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('old', 'original'));
    $disk = Storage::disk('local');
    $adapter = Mockery::mock($disk->getAdapter())->makePartial();
    $adapter->shouldReceive('write')->withArgs(fn (string $path): bool => str_ends_with($path, '/third'))
        ->once()->andThrow(UnableToWriteFile::atLocation('third'));
    Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, $disk->getConfig()));

    expect(fn () => VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('first', 'one');
        $files->put('second', 'two');

        try {
            $files->put('third', 'three');
        } catch (UnableToWriteFile) {
            expect(fn () => $files->put('fourth', 'four'))->toThrow(RuntimeException::class, 'failed');
        }
    }, cleanup: true))->toThrow(RuntimeException::class, 'failed');

    $journal = DB::table('versioned_storage_operations')->sole();
    expect($journal->operation)->toBe('write')
        ->and(VersionedStorage::directory('reports')->versions())->toBe([$current])
        ->and(VersionedStorage::directory('reports')->get('old'))->toBe('original');
    $this->travelTo(Carbon::parse($journal->cleanup_after));
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
    expect(DB::table('versioned_storage_operations')->count())->toBe(0);
    Storage::disk('local')->assertMissing('reports/'.$journal->version);
});

it('gives each operation a fresh deadline without retaining successful operation journals', function () {
    $this->freezeTime();
    config()->set('laravel-versioned-storage.upload_timeout', 10);
    config()->set('laravel-versioned-storage.cleanup_grace', 100);
    $start = now()->copy();

    $stage = VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files) use ($start): void {
        $files->put('first', 'one');
        $this->travel(20)->seconds();
        $files->put('second', 'two');
        expect(DB::table('versioned_storage_operations')->count())->toBe(0)
            ->and(DB::table('versioned_storage_stages')->value('cleanup_after'))
            ->toBe($start->copy()->addSeconds(130)->ceilSecond()->toDateTimeString());
    });

    expect($stage->publish())->toBe($stage->version);
});
