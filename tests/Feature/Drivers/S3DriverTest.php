<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\Drivers\S3Transport;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->s3 = new S3Transport;
});

it('keeps S3 file reads and cleanup inside the selected bucket', function () {
    $this->s3->disk('first', bucket: 'first-bucket');
    $this->s3->disk('other', bucket: 'other-bucket');
    $old = VersionedStorage::put('reports', 'original', disk: 'first');
    $other = VersionedStorage::put('reports', 'unrelated', disk: 'other');
    $current = VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::get('reports', disk: 'first'))->toBe('replacement')
        ->and(VersionedStorage::get('reports', disk: 'other'))->toBe('unrelated')
        ->and(VersionedStorage::versions('reports', 'first'))->toBe([$current])
        ->and(VersionedStorage::versions('reports', 'other'))->toBe([$other])
        ->and($this->s3->objects['first-bucket'])->not->toHaveKey("tenant/reports/{$old}")
        ->and($this->s3->objects['first-bucket']["tenant/reports/{$current}"])->toBe('replacement')
        ->and($this->s3->objects['other-bucket']["tenant/reports/{$other}"])->toBe('unrelated');
});

it('keeps S3 file reads and cleanup inside the selected prefix', function () {
    $this->s3->disk('first', prefix: 'tenants/first');
    $this->s3->disk('other', prefix: 'tenants/other');
    $old = VersionedStorage::put('reports', 'original', disk: 'first');
    $other = VersionedStorage::put('reports', 'unrelated', disk: 'other');
    $current = VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::get('reports', disk: 'first'))->toBe('replacement')
        ->and(VersionedStorage::get('reports', disk: 'other'))->toBe('unrelated')
        ->and(VersionedStorage::versions('reports', 'first'))->toBe([$current])
        ->and(VersionedStorage::versions('reports', 'other'))->toBe([$other])
        ->and($this->s3->objects['reports-bucket'])->not->toHaveKey("tenants/first/reports/{$old}")
        ->and($this->s3->objects['reports-bucket']["tenants/first/reports/{$current}"])->toBe('replacement')
        ->and($this->s3->objects['reports-bucket']["tenants/other/reports/{$other}"])->toBe('unrelated');
});

it('keeps S3 file reads and cleanup inside the selected resource path', function () {
    $this->s3->disk('first');
    $this->s3->disk('other');
    $old = VersionedStorage::put('reports', 'original', disk: 'first');
    $other = VersionedStorage::put('archives', 'unrelated', disk: 'other');
    $current = VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::get('reports', disk: 'first'))->toBe('replacement')
        ->and(VersionedStorage::get('archives', disk: 'other'))->toBe('unrelated')
        ->and(VersionedStorage::versions('reports', 'first'))->toBe([$current])
        ->and(VersionedStorage::versions('archives', 'other'))->toBe([$other])
        ->and($this->s3->objects['reports-bucket'])->not->toHaveKey("tenant/reports/{$old}")
        ->and($this->s3->objects['reports-bucket']["tenant/reports/{$current}"])->toBe('replacement')
        ->and($this->s3->objects['reports-bucket']["tenant/archives/{$other}"])->toBe('unrelated');
});

it('keeps S3 directory reads and cleanup inside the selected bucket', function () {
    $this->s3->disk('first', bucket: 'first-bucket');
    $this->s3->disk('other', bucket: 'other-bucket');
    $old = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'original'), disk: 'first');
    $other = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'unrelated'), disk: 'other');
    $current = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'replacement'), cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::directory('reports', disk: 'first')->get('nested/summary.txt'))->toBe('replacement')
        ->and(VersionedStorage::directory('reports', disk: 'other')->get('nested/summary.txt'))->toBe('unrelated')
        ->and(VersionedStorage::directory('reports', disk: 'first')->versions())->toBe([$current])
        ->and(VersionedStorage::directory('reports', disk: 'other')->versions())->toBe([$other])
        ->and($this->s3->objects['first-bucket'])->not->toHaveKey("tenant/reports/{$old}/nested/summary.txt")
        ->and($this->s3->objects['first-bucket']["tenant/reports/{$current}/nested/summary.txt"])->toBe('replacement')
        ->and($this->s3->objects['other-bucket']["tenant/reports/{$other}/nested/summary.txt"])->toBe('unrelated');
});

it('keeps S3 directory reads and cleanup inside the selected prefix', function () {
    $this->s3->disk('first', prefix: 'tenants/first');
    $this->s3->disk('other', prefix: 'tenants/other');
    $old = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'original'), disk: 'first');
    $other = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'unrelated'), disk: 'other');
    $current = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'replacement'), cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::directory('reports', disk: 'first')->get('nested/summary.txt'))->toBe('replacement')
        ->and(VersionedStorage::directory('reports', disk: 'other')->get('nested/summary.txt'))->toBe('unrelated')
        ->and(VersionedStorage::directory('reports', disk: 'first')->versions())->toBe([$current])
        ->and(VersionedStorage::directory('reports', disk: 'other')->versions())->toBe([$other])
        ->and($this->s3->objects['reports-bucket'])->not->toHaveKey("tenants/first/reports/{$old}/nested/summary.txt")
        ->and($this->s3->objects['reports-bucket']["tenants/first/reports/{$current}/nested/summary.txt"])->toBe('replacement')
        ->and($this->s3->objects['reports-bucket']["tenants/other/reports/{$other}/nested/summary.txt"])->toBe('unrelated');
});

it('keeps S3 directory reads and cleanup inside the selected resource path', function () {
    $this->s3->disk('first');
    $this->s3->disk('other');
    $old = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'original'), disk: 'first');
    $other = VersionedStorage::directory('archives', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'unrelated'), disk: 'other');
    $current = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'replacement'), cleanup: true, disk: 'first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::directory('reports', disk: 'first')->get('nested/summary.txt'))->toBe('replacement')
        ->and(VersionedStorage::directory('archives', disk: 'other')->get('nested/summary.txt'))->toBe('unrelated')
        ->and(VersionedStorage::directory('reports', disk: 'first')->versions())->toBe([$current])
        ->and(VersionedStorage::directory('archives', disk: 'other')->versions())->toBe([$other])
        ->and($this->s3->objects['reports-bucket'])->not->toHaveKey("tenant/reports/{$old}/nested/summary.txt")
        ->and($this->s3->objects['reports-bucket']["tenant/reports/{$current}/nested/summary.txt"])->toBe('replacement')
        ->and($this->s3->objects['reports-bucket']["tenant/archives/{$other}/nested/summary.txt"])->toBe('unrelated');
});

it('restores the previous S3 file version after rollback and discards the stage', function () {
    $this->s3->disk('s3');
    $first = VersionedStorage::put('reports', 'original', cleanup: true, disk: 's3');
    $stage = VersionedStorage::stage('reports', 'staged', disk: 's3');
    DB::beginTransaction();
    $staged = $stage->publish(cleanup: true);
    DB::rollBack();

    $stage->discard();

    expect($this->s3->objects['reports-bucket'])->toBe(["tenant/reports/{$first}" => 'original'])
        ->and(VersionedStorage::versions('reports', 's3'))->toBe([$first])->not->toContain($staged)
        ->and(VersionedStorage::get('reports', disk: 's3'))->toBe('original');
});

it('restores the previous S3 directory version after rollback and discards the stage', function () {
    $this->s3->disk('s3');
    $first = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'original'), cleanup: true, disk: 's3');
    $stage = VersionedStorage::stageDirectory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'staged'), disk: 's3');
    DB::beginTransaction();
    $staged = $stage->publish(cleanup: true);
    DB::rollBack();

    $stage->discard();

    expect($this->s3->objects['reports-bucket'])->toBe([
        "tenant/reports/{$first}/" => '',
        "tenant/reports/{$first}/summary.txt" => 'original',
    ])
        ->and(VersionedStorage::directory('reports', disk: 's3')->versions())->toBe([$first])->not->toContain($staged)
        ->and(VersionedStorage::directory('reports', disk: 's3')->get('summary.txt'))->toBe('original');
});

it('propagates S3 upload errors without publishing a version', function () {
    $this->s3->disk('s3');
    $this->s3->failUploads = true;

    expect(fn () => VersionedStorage::put('reports', 'denied', disk: 's3'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('reports', 's3'))->toBe([])
        ->and($this->s3->objects)->toBe([]);
});
