<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

function registerOwnershipResource(string $path, string $disk = 'local', bool $directory = false): void
{
    $resource = new ResourceIdentity($path, $disk, $directory);
    DB::table('versioned_storage_resources')->insert([
        'key' => $resource->key,
        'disk' => $disk,
        'path' => $resource->path,
        'is_directory' => $directory,
    ]);
}

function resourceOwnershipFor(string $path, bool $directory = false): ResourceOwnership
{
    return new ResourceOwnership(
        DB::connection(),
        new ResourceIdentity($path, 'local', $directory),
        Storage::disk('local'),
        app(FilesystemManager::class),
        app('config'),
    );
}

it('rejects overlapping registered roots in either direction', function (string $existing, string $candidate) {
    registerOwnershipResource($existing);

    expect(fn () => resourceOwnershipFor($candidate, true)->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, "Versioned resource [{$candidate}] overlaps registered resource [{$existing}] on disk [local].");
    expect(DB::table('versioned_storage_resources')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'parent exists' => ['reports', 'reports/monthly'],
    'child exists' => ['reports/monthly', 'reports'],
    'same path different kind' => ['reports', 'reports'],
]);

it('allows similarly named sibling resource roots in either order', function (string $existing, string $candidate) {
    registerOwnershipResource($existing);

    resourceOwnershipFor($candidate)->assertRegistrationAllowed();

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
})->with([
    'shorter first' => ['reports', 'reports-archive'],
    'longer first' => ['reports-archive', 'reports'],
]);

it('excludes its own registration from overlap checks', function () {
    registerOwnershipResource('reports');

    resourceOwnershipFor('reports')->assertRegistrationAllowed();

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
});

it('resolves aliases without requiring configuration entries', function (bool $existingDirectory, bool $candidateDirectory) {
    Storage::set('alias', Storage::disk('local'));
    registerOwnershipResource('reports', 'alias', $existingDirectory);

    expect(fn () => resourceOwnershipFor('reports', $candidateDirectory)->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');
})->with([
    'file roots' => [false, false],
    'directory roots' => [true, true],
    'file over directory' => [true, false],
    'directory over file' => [false, true],
]);

it('rejects a file root at a registered directory on the same disk', function () {
    registerOwnershipResource('reports', directory: true);

    expect(fn () => resourceOwnershipFor('reports')->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');
});

it('protects registration inside a completed stage even without a published version', function () {
    registerOwnershipResource('reports', directory: true);
    DB::table('versioned_storage_stages')->insert([
        'resource_id' => DB::table('versioned_storage_resources')->value('id'),
        'version' => 'pending', 'state' => 'ready',
    ]);
    Storage::disk('local')->put('reports/pending/one', 'staged contents');

    expect(fn () => resourceOwnershipFor('reports/pending/nested')->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, 'overlaps registered resource')
        ->and(DB::table('versioned_storage_versions')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe(['reports/pending/one'])
        ->and(Storage::disk('local')->get('reports/pending/one'))->toBe('staged contents');
});

it('allows the same logical root on an independent adapter', function () {
    Storage::fake('independent');
    registerOwnershipResource('reports', 'independent');

    resourceOwnershipFor('reports')->assertRegistrationAllowed();

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
});

it('does not resolve unrelated configured disks', function () {
    config()->set('filesystems.disks.broken', ['driver' => 'unsupported']);
    registerOwnershipResource('unrelated', 'broken');

    resourceOwnershipFor('reports')->assertRegistrationAllowed();

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
});

it('ignores a retired unconfigured disk during registration', function () {
    $retired = Storage::fake('retired');
    $retired->put('reports/archived-version', 'archived contents');
    Storage::forgetDisk('retired');
    registerOwnershipResource('reports', 'retired');
    DB::table('versioned_storage_resources')->where('disk', 'retired')->update(['current_version' => 'archived-version']);

    resourceOwnershipFor('reports')->assertRegistrationAllowed();

    expect(DB::table('versioned_storage_resources')->where('disk', 'retired')->value('current_version'))->toBe('archived-version')
        ->and($retired->get('reports/archived-version'))->toBe('archived contents');
    expect(fn () => Storage::disk('retired'))
        ->toThrow(InvalidArgumentException::class, 'Disk [retired] does not have a configured driver.');
});

it('surfaces resolution errors from configured overlapping disks', function (string $driver, string $message) {
    registerOwnershipResource('reports', 'broken');
    config()->set('filesystems.disks.broken', ['driver' => $driver]);
    Storage::extend('failing', function (): never {
        throw new InvalidArgumentException('Disk [broken] does not have a configured driver.');
    });

    expect(fn () => resourceOwnershipFor('reports')->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, $message);
    expect(DB::table('versioned_storage_resources')->where('disk', 'local')->exists())->toBeFalse();
})->with([
    'unsupported driver' => ['unsupported', 'Driver [unsupported] is not supported.'],
    'misleading custom driver failure' => ['failing', 'Disk [broken] does not have a configured driver.'],
]);

it('preserves unexpected resolution errors for unconfigured aliases', function () {
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->once()->with('broken')->andThrow(new InvalidArgumentException('Unexpected resolution failure'));
    registerOwnershipResource('reports', 'broken');
    $ownership = new ResourceOwnership(DB::connection(), new ResourceIdentity('reports', 'local', false), Storage::disk('local'), $manager, app('config'));

    expect(fn () => $ownership->assertRegistrationAllowed())
        ->toThrow(InvalidArgumentException::class, 'Unexpected resolution failure');
});

it('protects exact and nested legacy registrations inside the snapshot', function (string $path) {
    Storage::set('alias', Storage::disk('local'));
    registerOwnershipResource($path, 'alias');
    $previous = new RuntimeException('publication failed');

    expect(fn () => resourceOwnershipFor('reports', true)->assertSnapshotRemovable('old', $previous))
        ->toThrow(function (RuntimeException $exception) use ($path, $previous): void {
            expect($exception->getMessage())->toBe("Cannot remove version [old]: it contains registered resource [{$path}].")
                ->and($exception->getCode())->toBe(0)
                ->and($exception->getPrevious())->toBe($previous);
        });
})->with([
    'exact snapshot root' => ['reports/old'],
    'nested registered resource' => ['reports/old/independent'],
]);

it('allows removing a snapshot beside a similarly named registration', function () {
    registerOwnershipResource('reports/old-archive');
    Storage::disk('local')->put('reports/old-archive/current', 'independent');
    Storage::disk('local')->put('reports/old/one', 'retired');

    resourceOwnershipFor('reports', true)->assertSnapshotRemovable('old');
    SnapshotDeletion::remove(Storage::disk('local'), 'reports/old', true);

    expect(DB::table('versioned_storage_resources')->count())->toBe(1)
        ->and(Storage::disk('local')->exists('reports/old'))->toBeFalse()
        ->and(Storage::disk('local')->get('reports/old-archive/current'))->toBe('independent');
});

it('does not require a retired independent disk to check snapshot removal', function () {
    $retired = Storage::fake('retired');
    $retired->put('reports/old/independent/archived-version', 'archived contents');
    Storage::forgetDisk('retired');
    registerOwnershipResource('reports/old/independent', 'retired');
    DB::table('versioned_storage_resources')->where('disk', 'retired')->update(['current_version' => 'archived-version']);
    Storage::disk('local')->put('reports/old/one', 'retired contents');

    resourceOwnershipFor('reports', true)->assertSnapshotRemovable('old');
    SnapshotDeletion::remove(Storage::disk('local'), 'reports/old', true);

    expect(DB::table('versioned_storage_resources')->where('disk', 'retired')->value('current_version'))->toBe('archived-version')
        ->and($retired->get('reports/old/independent/archived-version'))->toBe('archived contents')
        ->and(Storage::disk('local')->exists('reports/old'))->toBeFalse();
});

it('allows snapshot removal when the nested registration uses an independent filesystem', function () {
    Storage::fake('independent');
    registerOwnershipResource('reports/old/independent', 'independent');

    resourceOwnershipFor('reports', true)->assertSnapshotRemovable('old');

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
});

it('does not treat a file snapshot as a directory ownership boundary', function () {
    registerOwnershipResource('reports/old/nested');

    resourceOwnershipFor('reports')->assertSnapshotRemovable('old');

    expect(DB::table('versioned_storage_resources')->count())->toBe(1);
});

it('checks uncommitted registrations on the primary connection without creating its own transaction', function () {
    DB::beginTransaction();

    try {
        registerOwnershipResource('reports');
        expect(fn () => resourceOwnershipFor('reports/nested')->assertRegistrationAllowed())
            ->toThrow(InvalidArgumentException::class, 'overlaps registered resource');
        expect(DB::transactionLevel())->toBe(1);
    } finally {
        DB::rollBack();
    }

    expect(DB::table('versioned_storage_resources')->count())->toBe(0);
});
