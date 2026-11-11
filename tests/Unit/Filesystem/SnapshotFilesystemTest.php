<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;

beforeEach(function (): void {
    $this->root = dirname(__DIR__, 2).'/.snapshot-'.bin2hex(random_bytes(8));
    $adapter = new LocalFilesystemAdapter($this->root);
    $this->disk = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root, 'url' => 'https://files.test']);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
    Mockery::close();
});

it('places directory contents inside the requested version', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));
    $snapshots->scoped('first')->put('nested/one', 'contents');

    expect($this->disk->get('reports/first/nested/one'))->toBe('contents')
        ->and($this->disk->exists('reports/nested/one'))->toBeFalse();
});

it('leaves the UUID filename to the file caller', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('report.txt', 'local', false));
    $snapshots->scoped('first')->put('first', 'contents');

    expect($this->disk->get('report.txt/first'))->toBe('contents')
        ->and($this->disk->exists('report.txt/first/first'))->toBeFalse();
});

it('keeps directory versions physically isolated', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));
    $snapshots->scoped('first')->put('one', 'original');
    $snapshots->scoped('second')->put('one', 'replacement');

    expect($snapshots->scoped('first')->get('one'))->toBe('original')
        ->and($snapshots->scoped('second')->get('one'))->toBe('replacement');
});

it('creates fresh views without carrying URL callbacks between callers', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));
    $first = $snapshots->scoped('first');
    $first->buildTemporaryUrlsUsing(fn () => 'https://signed.test');
    $second = $snapshots->scoped('first');

    expect($first)->not->toBe($second)
        ->and($first->providesTemporaryUrls())->toBeTrue()
        ->and($second->providesTemporaryUrls())->toBeFalse();
});

it('uses the captured adapter independently of the logical disk name', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'unconfigured', true));
    $snapshots->scoped('first')->put('one', 'captured');

    expect($this->disk->get('reports/first/one'))->toBe('captured');
});

it('normalizes resource paths before constructing views', function (string $path) {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity($path, 'local', true));
    $snapshots->scoped('first')->put('one', 'normalized');

    expect($this->disk->get('reports/monthly/first/one'))->toBe('normalized');
})->with([
    'leading slash' => ['/reports/monthly'],
    'repeated separators' => ['reports//monthly'],
    'dot segments' => ['reports/./monthly'],
    'resolved parent' => ['reports/old/../monthly'],
    'backslashes' => ['reports\\monthly'],
]);

it('preserves public URL generation on the captured disk', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));

    expect($snapshots->scoped('first')->url('one'))->toBe('https://files.test/reports/first/one');
});

it('forces write failures to propagate from snapshot views', function () {
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));

    expect($snapshots->scoped('first')->getConfig()['throw'])->toBeTrue()
        ->and($this->disk->getConfig())->not->toHaveKey('throw');
});

it('allows UUIDs in resource roots and arbitrary nested snapshot contents', function () {
    $path = 'accounts/3d596509-8191-4f2c-84d4-e3a8b1acfc39/reports';
    $file = 'nested/3d596509-8191-4f2c-84d4-e3a8b1acfc39/report.txt';
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity($path, 'local', true));

    $snapshots->scoped('version')->put($file, 'contents');

    expect($snapshots->scoped('version')->get($file))->toBe('contents')
        ->and($this->disk->get("{$path}/version/{$file}"))->toBe('contents');
});

it('binds callback views to the supplied upload operation lifetime', function () {
    $operation = new UploadOperation(
        ['timeout' => 10, 'fork' => false],
        [],
        fn () => throw new LogicException('A closed operation must not journal writes'),
        executor: new FilesystemOperationExecutor(
            Mockery::mock(ForkUpload::class),
            Mockery::mock(FilesystemManager::class),
        ),
        clock: new UploadClock,
    );
    $snapshots = new SnapshotFilesystem($this->disk, new ResourceIdentity('reports', 'local', true));
    $files = $snapshots->scoped('version', $operation);
    $operation->close();

    expect(fn () => $files->put('one', 'late'))->toThrow(RuntimeException::class, 'callback has already finished')
        ->and($this->disk->exists('reports/version/one'))->toBeFalse();
});
