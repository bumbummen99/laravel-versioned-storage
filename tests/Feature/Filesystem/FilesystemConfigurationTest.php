<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Adapters\VersionedFilesystem;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('preserves write restrictions enforced by the selected filesystem operator', function (bool $directory) {
    $disk = Storage::disk('local');
    $operator = Mockery::mock($disk->getDriver())->makePartial();
    $operator->shouldReceive('write')->withAnyArgs()
        ->andThrow(UnableToWriteFile::atLocation('reports', 'Writes are disabled by the filesystem operator.'));
    Storage::set('restricted', new FilesystemAdapter($operator, $disk->getAdapter(), [
        ...$disk->getConfig(), 'throw' => true,
    ]));

    expect(fn () => Storage::disk('restricted')->put('direct.txt', 'contents'))
        ->toThrow(UnableToWriteFile::class, 'Writes are disabled');

    expect(fn () => $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'contents');
        }, disk: 'restricted')
        : VersionedStorage::put('reports', 'contents', disk: 'restricted'))
        ->toThrow(UnableToWriteFile::class, 'Writes are disabled');

    expect($directory
        ? VersionedStorage::directory('reports', disk: 'restricted')->versions()
        : VersionedStorage::versions('reports', 'restricted'))->toBe([]);
    $disk->assertDirectoryEmpty('reports');
})->with([false, true]);

it('preserves stream write restrictions enforced by the selected filesystem operator', function () {
    $disk = Storage::disk('local');
    $operator = Mockery::mock($disk->getDriver())->makePartial();
    $operator->shouldReceive('writeStream')->withAnyArgs()
        ->andThrow(UnableToWriteFile::atLocation('reports', 'Stream writes are disabled.'));
    Storage::set('restricted', new FilesystemAdapter($operator, $disk->getAdapter(), [
        ...$disk->getConfig(), 'throw' => true,
    ]));
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'contents');
    rewind($stream);

    try {
        expect(fn () => VersionedStorage::put('reports', $stream, disk: 'restricted'))
            ->toThrow(UnableToWriteFile::class, 'Stream writes are disabled');
        expect(VersionedStorage::versions('reports', 'restricted'))->toBe([]);
        $disk->assertDirectoryEmpty('reports');
    } finally {
        fclose($stream);
    }
});

it('preserves Laravel visibility configuration when copying or moving snapshot files', function (string $operation, array $settings, bool $retainVisibility) {
    $manager = new class(app()) extends FilesystemManager
    {
        public function createFlysystem(FlysystemAdapter $adapter, array $config): FilesystemOperator
        {
            return parent::createFlysystem($adapter, $config);
        }
    };
    $config = array_merge(['visibility' => 'private'], $settings);
    $adapter = Mockery::mock(FlysystemAdapter::class);
    $originalOptions = null;
    $scopedOptions = null;
    $adapter->shouldReceive($operation)->once()->with(
        'source.txt',
        'destination.txt',
        Mockery::on(function (Config $options) use (&$originalOptions): bool {
            $originalOptions = $options->toArray();

            return true;
        }),
    );
    $adapter->shouldReceive($operation)->once()->with(
        'reports/version/source.txt',
        'reports/version/destination.txt',
        Mockery::on(function (Config $options) use (&$scopedOptions): bool {
            $scopedOptions = $options->toArray();

            return true;
        }),
    );
    $disk = new FilesystemAdapter($manager->createFlysystem($adapter, $config), $adapter, $config);
    $scoped = new VersionedFilesystem($disk, 'reports/version');

    $disk->{$operation}('source.txt', 'destination.txt');
    $scoped->{$operation}('source.txt', 'destination.txt');

    expect($originalOptions['retain_visibility'] ?? true)->toBe($retainVisibility);
    expect([
        'retain_visibility' => $scopedOptions['retain_visibility'] ?? true,
        'visibility' => $scopedOptions['visibility'] ?? null,
    ])->toBe([
        'retain_visibility' => $retainVisibility,
        'visibility' => $originalOptions['visibility'] ?? null,
    ]);
})->with(['copy', 'move'])->with([
    'R2 default' => [['endpoint' => 'https://example-account.r2.cloudflarestorage.com'], false],
    'R2 overrides explicit retention' => [[
        'endpoint' => 'https://example-account.r2.cloudflarestorage.com',
        'retain_visibility' => true,
    ], false],
    'other endpoint default' => [['endpoint' => 'https://s3.example.test'], true],
    'explicit retention disabled' => [['retain_visibility' => false], false],
    'no endpoint configured' => [[], true],
]);
