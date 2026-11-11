<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SkyRaptor\LaravelVersionedStorage\Actions\ReserveDestination;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

afterEach(function (): void {
    Str::createUuidsNormally();
});

it('retains the permanent reservation after the physical snapshot is removed', function () {
    $disk = Storage::disk('local');
    $reservation = new ReserveDestination(DB::connection(), new ResourceIdentity('reports', 'local', false), $disk);
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $destination = $reservation->reserve(['timeout' => 10, 'fork' => false]);
    $disk->put('reports/'.$destination['version'], 'retired contents');
    $disk->delete('reports/'.$destination['version']);

    expect(fn () => $reservation->reserve(['timeout' => 10, 'fork' => false]))->toThrow(QueryException::class)
        ->and(DB::table('versioned_storage_reservations')->pluck('version')->all())->toBe([(string) $uuid])
        ->and($disk->exists("reports/{$uuid}"))->toBeFalse();
});

it('rejects a UUID reserved by a published or still unpublished snapshot before physical IO', function () {
    $disk = Storage::disk('local');
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    DB::table('versioned_storage_reservations')->insert(['version' => (string) $uuid]);
    $disk->put("reports/{$uuid}/original.txt", 'original contents');
    $reservation = new ReserveDestination(DB::connection(), new ResourceIdentity('reports', 'local', true), $disk);

    expect(fn () => $reservation->reserve(['timeout' => 10, 'fork' => false]))->toThrow(QueryException::class)
        ->and($disk->allFiles('reports'))->toBe(["reports/{$uuid}/original.txt"])
        ->and($disk->get("reports/{$uuid}/original.txt"))->toBe('original contents')
        ->and(DB::table('versioned_storage_reservations')->count())->toBe(1)
        ->and(DB::table('versioned_storage_stages')->count())->toBe(0);
});

it('preserves unregistered file contents at a generated destination', function () {
    $disk = Storage::disk('local');
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $disk->put("reports/{$uuid}", 'unregistered file');
    $reservation = new ReserveDestination(DB::connection(), new ResourceIdentity('reports', 'local', false), $disk);

    expect(fn () => $reservation->reserve(['timeout' => 10, 'fork' => false]))
        ->toThrow(RuntimeException::class, 'already exists')
        ->and($disk->get("reports/{$uuid}"))->toBe('unregistered file')
        ->and(DB::table('versioned_storage_reservations')->value('version'))->toBe((string) $uuid)
        ->and(DB::table('versioned_storage_versions')->count())->toBe(0);
});

it('preserves unregistered directory contents at a generated destination', function () {
    $disk = Storage::disk('local');
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    $disk->put("reports/{$uuid}/original.txt", 'unregistered directory');
    $reservation = new ReserveDestination(DB::connection(), new ResourceIdentity('reports', 'local', true), $disk);

    expect(fn () => $reservation->reserve(['timeout' => 10, 'fork' => false]))
        ->toThrow(RuntimeException::class, 'already exists')
        ->and($disk->get("reports/{$uuid}/original.txt"))->toBe('unregistered directory')
        ->and(DB::table('versioned_storage_reservations')->value('version'))->toBe((string) $uuid)
        ->and(DB::table('versioned_storage_versions')->count())->toBe(0);
});
