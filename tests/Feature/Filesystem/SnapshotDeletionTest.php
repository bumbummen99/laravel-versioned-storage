<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Database\ResourceMetadata;
use SkyRaptor\LaravelVersionedStorage\Filesystem\ResourceOwnership;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $resource = new ResourceIdentity('reports', 'local', false);
    $this->disk = Storage::disk('local');
    $this->deletion = new SnapshotDeletion(
        DB::connection(),
        $resource,
        new ResourceMetadata(DB::connection(), $resource),
        new ResourceOwnership(DB::connection(), $resource, $this->disk, app('filesystem'), config()),
        $this->disk,
    );
});

it('prepares a scalar-only worker and verifies successful file removal', function () {
    $this->disk->put('reports/old', 'retired');
    $this->disk->put('reports/current', 'current');

    $worker = $this->deletion->prepare('old');
    expect($this->disk->get('reports/old'))->toBe('retired');
    foreach ((new ReflectionFunction($worker))->getStaticVariables() as $value) {
        expect(is_scalar($value))->toBeTrue();
    }
    expect($worker())->toBe(['version' => 'old', 'error' => null])
        ->and($this->disk->exists('reports/old'))->toBeFalse()
        ->and($this->disk->get('reports/current'))->toBe('current');
});

it('returns a retryable worker failure when disk configuration changed', function () {
    $this->disk->put('reports/old', 'retired');
    $worker = $this->deletion->prepare('old');
    $replacement = Storage::fake('replacement');
    $replacement->put('reports/old', 'unrelated');
    Storage::set('local', $replacement);

    expect($worker())->toBe([
        'version' => 'old',
        'error' => 'Disk [local] has different configuration in the cleanup worker.',
    ])->and($replacement->get('reports/old'))->toBe('unrelated')
        ->and($this->disk->get('reports/old'))->toBe('retired');
});

it('returns the deletion error and version when the reconstructed disk refuses deletion', function () {
    $this->disk->put('reports/old', 'retired');
    $worker = $this->deletion->prepare('old');
    $refusing = Mockery::mock($this->disk)->makePartial();
    $refusing->shouldReceive('delete')->once()->with('reports/old')->andReturn(false);
    Storage::set('local', $refusing);

    expect($worker())->toBe([
        'version' => 'old',
        'error' => 'Unable to remove version snapshot [reports/old].',
    ])->and($this->disk->get('reports/old'))->toBe('retired');
});

it('returns resolution errors instead of claiming worker success', function () {
    $worker = $this->deletion->prepare('old');
    Storage::forgetDisk('local');
    config()->set('filesystems.disks.local', null);

    expect($worker())->toBe([
        'version' => 'old',
        'error' => 'Disk [local] does not have a configured driver.',
    ]);
});

it('uses the captured disk for direct deletion after its name is rebound', function () {
    $this->disk->put('reports/old', 'retired');
    $replacement = Storage::fake('replacement');
    $replacement->put('reports/old', 'unrelated');
    Storage::set('local', $replacement);

    $this->deletion->delete('old');

    expect($this->disk->exists('reports/old'))->toBeFalse()
        ->and($replacement->get('reports/old'))->toBe('unrelated');
});

it('checks directory ownership before creating a destructive worker', function () {
    $resource = new ResourceIdentity('reports', 'local', true);
    DB::table('versioned_storage_resources')->insert([
        'key' => 'legacy-child',
        'disk' => 'local',
        'path' => 'reports/old/independent',
        'is_directory' => false,
    ]);
    $this->disk->put('reports/old/independent/current', 'protected');
    $deletion = new SnapshotDeletion(
        DB::connection(),
        $resource,
        new ResourceMetadata(DB::connection(), $resource),
        new ResourceOwnership(DB::connection(), $resource, $this->disk, app('filesystem'), config()),
        $this->disk,
    );

    expect(fn () => $deletion->prepare('old'))->toThrow(RuntimeException::class, 'contains registered resource')
        ->and($this->disk->get('reports/old/independent/current'))->toBe('protected');
});
