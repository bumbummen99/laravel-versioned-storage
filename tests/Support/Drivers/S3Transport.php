<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support\Drivers;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use LogicException;

final class S3Transport
{
    /** @var array<string, array<string, string>> */
    public array $objects = [];

    public bool $failUploads = false;

    public function disk(string $name, string $bucket = 'reports-bucket', string $prefix = 'tenant'): void
    {
        Storage::set($name, Storage::build([
            'driver' => 's3',
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'testing', 'secret' => 'testing'],
            'handler' => $this->handle(...),
            'bucket' => $bucket,
            'prefix' => $prefix,
            'throw' => true,
        ]));
    }

    private function handle(CommandInterface $command): PromiseInterface
    {
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

            foreach ($this->objects[$bucket] ?? [] as $objectKey => $value) {
                if (str_starts_with($objectKey, $command['Prefix'] ?? '')) {
                    $contents[] = ['Key' => $objectKey, 'Size' => strlen($value)];
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
    }
}
