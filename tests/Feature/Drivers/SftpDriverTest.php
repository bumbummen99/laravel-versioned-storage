<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\Drivers\SftpTransport;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->sftp = new SftpTransport;
    $this->sftp->connectDisks();
});

it('isolates SFTP file snapshots by remote root including reads and cleanup', function () {
    $first = VersionedStorage::put('reports', 'first', disk: 'sftp-first');
    $other = VersionedStorage::put('reports', 'other', disk: 'sftp-second');
    $replacement = VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'sftp-first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement')
        ->and(VersionedStorage::get('reports', disk: 'sftp-second'))->toBe('other')
        ->and($this->sftp->files)->toBe([
            "/remote/second/tenant/reports/{$other}" => 'other',
            "/remote/first/tenant/reports/{$replacement}" => 'replacement',
        ])
        ->and($this->sftp->deletions)->toContain("/remote/first/tenant/reports/{$first}");
});

it('isolates SFTP directory snapshots by remote root including reads and cleanup', function () {
    $first = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'first'), disk: 'sftp-first');
    $other = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'other'), disk: 'sftp-second');
    $replacement = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('nested/summary.txt', 'replacement'), cleanup: true, disk: 'sftp-first');

    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect(VersionedStorage::directory('reports', disk: 'sftp-first')->get('nested/summary.txt'))->toBe('replacement')
        ->and(VersionedStorage::directory('reports', disk: 'sftp-second')->get('nested/summary.txt'))->toBe('other')
        ->and($this->sftp->files)->toBe([
            "/remote/second/tenant/reports/{$other}/nested/summary.txt" => 'other',
            "/remote/first/tenant/reports/{$replacement}/nested/summary.txt" => 'replacement',
        ])
        ->and($this->sftp->deletions)->toContain("/remote/first/tenant/reports/{$first}/");
});

it('retains SFTP retry records if post-delete verification loses its connection', function () {
    $first = VersionedStorage::put('reports', 'original', disk: 'sftp-first');
    $this->sftp->failAfterDeletion = true;

    VersionedStorage::put('reports', 'replacement', true, 'sftp-first');
    expect(fn () => VersionedStorage::cleanup('reports', 'sftp-first'))
        ->toThrow(UnableToCheckFileExistence::class)
        ->and($this->sftp->files)->not->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->sftp->failAfterDeletion = false;
    $this->sftp->failVerification = false;
    VersionedStorage::cleanup('reports', 'sftp-first');

    expect(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse()
        ->and(VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement');
});

it('discards a rolled back SFTP file stage from the selected root', function () {
    $stage = VersionedStorage::stage('reports', 'staged', disk: 'sftp-first');
    DB::beginTransaction();
    $version = $stage->publish();
    expect($this->sftp->files)->toHaveCount(1);
    DB::rollBack();
    expect($this->sftp->files)->toHaveCount(1);
    $stage->discard();
    expect($this->sftp->files)->toBe([])
        ->and($this->sftp->deletions)->toContain("/remote/first/tenant/reports/{$version}")
        ->and(VersionedStorage::versions('reports', 'sftp-first'))->toBe([]);
});

it('discards a rolled back SFTP directory stage from the selected root', function () {
    $stage = VersionedStorage::stageDirectory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'staged'), disk: 'sftp-first');
    DB::beginTransaction();
    $version = $stage->publish();
    expect($this->sftp->files)->toHaveCount(1);
    DB::rollBack();
    expect($this->sftp->files)->toHaveCount(1);

    $stage->discard();

    expect($this->sftp->files)->toBe([])
        ->and($this->sftp->deletions)->toContain("/remote/first/tenant/reports/{$version}/")
        ->and(VersionedStorage::directory('reports', disk: 'sftp-first')->versions())->toBe([]);
});

it('propagates SFTP upload errors without publishing a version', function () {
    $this->sftp->failUploads = true;

    expect(fn () => VersionedStorage::put('reports', 'denied', disk: 'sftp-first'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('reports', 'sftp-first'))->toBe([])
        ->and($this->sftp->files)->toBe([]);
});

it('retains retry metadata when SFTP file cleanup is refused', function () {
    $first = VersionedStorage::put('reports', 'original', disk: 'sftp-first');
    $this->sftp->failDeletions = true;

    VersionedStorage::put('reports', 'replacement', cleanup: true, disk: 'sftp-first');
    expect(fn () => VersionedStorage::cleanup('reports', 'sftp-first'))
        ->toThrow(RuntimeException::class);

    $oldPath = "/remote/first/tenant/reports/{$first}";
    expect($this->sftp->files[$oldPath])->toBe('original')
        ->and(VersionedStorage::get('reports', disk: 'sftp-first'))->toBe('replacement')
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->sftp->failDeletions = false;
    VersionedStorage::cleanup('reports', 'sftp-first');

    expect($this->sftp->files)->not->toHaveKey($oldPath)
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse();
});

it('retains retry metadata when SFTP directory cleanup is refused', function () {
    $first = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'original'), disk: 'sftp-first');
    $this->sftp->failDeletions = true;

    VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'replacement'), cleanup: true, disk: 'sftp-first');
    expect(fn () => VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup())
        ->toThrow(RuntimeException::class);

    $oldPath = "/remote/first/tenant/reports/{$first}/summary.txt";
    expect($this->sftp->files[$oldPath])->toBe('original')
        ->and(VersionedStorage::directory('reports', disk: 'sftp-first')->get('summary.txt'))->toBe('replacement')
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->sftp->failDeletions = false;
    VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();

    expect($this->sftp->files)->not->toHaveKey($oldPath)
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse();
});

it('verifies removal of empty SFTP snapshot directories', function () {
    $first = VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->makeDirectory('empty');
    }, disk: 'sftp-first');
    $this->sftp->failDeletions = true;

    VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
        $files->put('summary.txt', 'replacement');
    }, cleanup: true, disk: 'sftp-first');
    expect(fn () => VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup())
        ->toThrow(RuntimeException::class, 'Unable to remove version')
        ->and($this->sftp->files)->toHaveCount(1)
        ->and($this->sftp->directories)->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->value('pending_delete'))->toBe(1);

    $this->sftp->failDeletions = false;
    VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();

    expect($this->sftp->directories)->not->toHaveKey("/remote/first/tenant/reports/{$first}")
        ->and(DB::table('versioned_storage_versions')->where('version', $first)->exists())->toBeFalse()
        ->and(VersionedStorage::directory('reports', disk: 'sftp-first')->get('summary.txt'))->toBe('replacement');
});

it('finishes SFTP file cleanup when the pending snapshot is already physically absent', function () {
    $version = VersionedStorage::put('reports', 'original', disk: 'sftp-first');
    DB::table('versioned_storage_versions')->where('version', $version)->update(['pending_delete' => true]);
    DB::table('versioned_storage_resources')->where('disk', 'sftp-first')->update(['current_version' => null]);
    $this->sftp->files = [];
    $this->sftp->directories = [];
    $this->sftp->failDeletions = true;

    VersionedStorage::cleanup('reports', 'sftp-first');

    expect(DB::table('versioned_storage_versions')->where('version', $version)->exists())->toBeFalse();
});

it('finishes SFTP directory cleanup when the pending snapshot is already physically absent', function () {
    $version = VersionedStorage::directory('reports', fn (FilesystemAdapter $files) => $files->put('summary.txt', 'original'), disk: 'sftp-first');
    DB::table('versioned_storage_versions')->where('version', $version)->update(['pending_delete' => true]);
    DB::table('versioned_storage_resources')->where('disk', 'sftp-first')->update(['current_version' => null]);
    $this->sftp->files = [];
    $this->sftp->directories = [];
    $this->sftp->failDeletions = true;

    VersionedStorage::directory('reports', disk: 'sftp-first')->cleanup();

    expect(DB::table('versioned_storage_versions')->where('version', $version)->exists())->toBeFalse();
});
