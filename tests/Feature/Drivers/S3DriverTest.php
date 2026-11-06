<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

beforeEach(function () {
    $this->objects = [];
    $this->failUploads = false;
    $this->s3Config = [
        'driver' => 's3',
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'testing', 'secret' => 'testing'],
        'handler' => function (CommandInterface $command): PromiseInterface {
            $operation = $command->getName();
            $bucket = $command['Bucket'];
            $key = $command['Key'] ?? '';

            if ($operation === 'PutObject') {
                if ($this->failUploads) {
                    return Create::rejectionFor(new S3Exception('Upload denied', $command, ['code' => 'AccessDenied']));
                }

                $this->objects[$bucket][$key] = (string) Utils::streamFor($command['Body']);

                return Create::promiseFor(new Result);
            }

            if ($operation === 'HeadObject' || $operation === 'GetObject') {
                if (! isset($this->objects[$bucket][$key])) {
                    return Create::rejectionFor(new S3Exception('Missing object', $command, [
                        'code' => 'NoSuchKey',
                        'response' => new Response(404),
                    ]));
                }

                return Create::promiseFor(new Result([
                    'Body' => Utils::streamFor($this->objects[$bucket][$key]),
                    'ContentLength' => strlen($this->objects[$bucket][$key]),
                ]));
            }

            if ($operation === 'ListObjectsV2' || $operation === 'ListObjects') {
                $contents = [];

                foreach ($this->objects[$bucket] ?? [] as $objectKey => $contentsValue) {
                    if (str_starts_with($objectKey, $command['Prefix'] ?? '')) {
                        $contents[] = ['Key' => $objectKey, 'Size' => strlen($contentsValue)];
                    }
                }

                return Create::promiseFor(new Result($contents === []
                    ? ['IsTruncated' => false]
                    : ['Contents' => $contents, 'IsTruncated' => false]));
            }

            if ($operation === 'DeleteObject' || $operation === 'DeleteObjects') {
                foreach ($operation === 'DeleteObject' ? [['Key' => $key]] : $command['Delete']['Objects'] as $object) {
                    unset($this->objects[$bucket][$object['Key']]);
                }

                return Create::promiseFor(new Result);
            }

            throw new LogicException("Unexpected S3 operation [{$operation}].");
        },
    ];

    $this->s3Disk = function (string $name, string $bucket, string $prefix = ''): void {
        Storage::set($name, Storage::build(array_merge($this->s3Config, [
            'bucket' => $bucket,
            'prefix' => $prefix,
            'throw' => true,
        ])));
    };
});

it('isolates S3 snapshot reads and cleanup by bucket prefix or resource path', function (bool $directory, string $isolation) {
    $sameBucket = $isolation !== 'bucket';
    $secondPrefix = $isolation === 'prefix' ? 'tenants/second' : 'tenants/first';
    $secondPath = $isolation === 'path' ? 'archives' : 'reports';
    ($this->s3Disk)('s3-first', 'first-bucket', 'tenants/first');
    ($this->s3Disk)('s3-second', $sameBucket ? 'first-bucket' : 'second-bucket', $secondPrefix);
    $write = fn (string $disk, string $contents, bool $cleanup = false): string => $directory
        ? VersionedStorage::directory($disk === 's3-second' ? $secondPath : 'reports', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('nested/summary.txt', $contents);
        }, $cleanup, $disk)
        : VersionedStorage::put($disk === 's3-second' ? $secondPath : 'reports', $contents, $cleanup, $disk);
    $read = fn (string $disk): string => $directory
        ? VersionedStorage::directory($disk === 's3-second' ? $secondPath : 'reports', disk: $disk)->get('nested/summary.txt')
        : VersionedStorage::get($disk === 's3-second' ? $secondPath : 'reports', disk: $disk);
    $first = $write('s3-first', 'first');
    $other = $write('s3-second', 'other');
    $replacement = $write('s3-first', 'replacement', true);
    $suffix = $directory ? '/nested/summary.txt' : '';
    $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();

    expect($read('s3-first'))->toBe('replacement')
        ->and($read('s3-second'))->toBe('other')
        ->and($directory ? VersionedStorage::directory('reports', disk: 's3-first')->versions() : VersionedStorage::versions('reports', 's3-first'))
        ->toBe([$replacement])
        ->and($directory ? VersionedStorage::directory($secondPath, disk: 's3-second')->versions() : VersionedStorage::versions($secondPath, 's3-second'))
        ->toBe([$other])
        ->and($this->objects['first-bucket'])->not->toHaveKey("tenants/first/reports/{$first}{$suffix}")
        ->and($this->objects['first-bucket']["tenants/first/reports/{$replacement}{$suffix}"])->toBe('replacement')
        ->and($this->objects[$sameBucket ? 'first-bucket' : 'second-bucket']["{$secondPrefix}/{$secondPath}/{$other}{$suffix}"])
        ->toBe('other');
})->with([false, true])->with(['bucket', 'prefix', 'path']);

it('restores the previous S3 version on rollback and explicitly discards the stage', function (bool $directory) {
    ($this->s3Disk)('s3', 'reports-bucket', 'tenant');
    $write = fn (string $contents): string => $directory
        ? VersionedStorage::directory('reports', function (FilesystemAdapter $files) use ($contents): void {
            $files->put('summary.txt', $contents);
        }, true, 's3')
        : VersionedStorage::put('reports', $contents, true, 's3');
    $first = $write('original');
    $stage = $directory
        ? VersionedStorage::stageDirectory('reports', function (FilesystemAdapter $files): void {
            $files->put('summary.txt', 'staged');
        }, disk: 's3')
        : VersionedStorage::stage('reports', 'staged', disk: 's3');
    DB::beginTransaction();
    $staged = $stage->publish(cleanup: true);
    DB::rollBack();

    $stage->discard();
    $expected = $directory
        ? ["tenant/reports/{$first}/" => '', "tenant/reports/{$first}/summary.txt" => 'original']
        : ["tenant/reports/{$first}" => 'original'];

    expect($this->objects['reports-bucket'])->toBe($expected)
        ->and($directory ? VersionedStorage::directory('reports', disk: 's3')->versions() : VersionedStorage::versions('reports', 's3'))
        ->toBe([$first])->not->toContain($staged)
        ->and($directory
            ? VersionedStorage::directory('reports', disk: 's3')->get('summary.txt')
            : VersionedStorage::get('reports', disk: 's3'))->toBe('original');
})->with([false, true]);

it('propagates S3 upload errors without publishing a version', function () {
    ($this->s3Disk)('s3', 'reports-bucket');
    $this->failUploads = true;

    expect(fn () => VersionedStorage::put('reports', 'denied', disk: 's3'))
        ->toThrow(UnableToWriteFile::class)
        ->and(VersionedStorage::versions('reports', 's3'))->toBe([])
        ->and($this->objects)->toBe([]);
});
