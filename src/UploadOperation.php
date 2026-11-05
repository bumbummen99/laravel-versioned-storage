<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Throwable;

final class UploadOperation
{
    private ?Throwable $failure = null;

    private bool $closed = false;

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     * @param  Closure(string, string): void  $begin
     * @param  Closure(string): void  $complete
     */
    public function __construct(
        private readonly array $execution,
        private readonly array $configuration,
        private readonly Closure $begin,
        private readonly Closure $complete,
    ) {}

    public function assertHealthy(): void
    {
        if ($this->failure !== null) {
            throw new RuntimeException('The snapshot failed during a filesystem operation.', previous: $this->failure);
        }

        if ($this->closed) {
            throw new RuntimeException('The snapshot callback has already finished.');
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @template TResult
     *
     * @param  Closure(FilesystemOperator): TResult  $callback
     * @return TResult
     */
    public function run(string $operation, Closure $callback, FilesystemOperator $filesystem): mixed
    {
        $this->assertHealthy();
        $mutation = in_array($operation, [
            'write', 'writeStream', 'createDirectory', 'delete', 'deleteDirectory', 'copy', 'move', 'setVisibility',
        ], true);
        $id = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->execution['timeout']);
        $deadline = hrtime(true) + $this->execution['timeout'] * 1_000_000_000;

        try {
            // Read operations also lock/check the stage, but do not leave mutation journals.
            ($this->begin)($id, $mutation ? $operation : '');

            if (now()->greaterThanOrEqualTo($expiresAt) || hrtime(true) >= $deadline) {
                throw new RuntimeException('The filesystem operation timed out before it could start.');
            }

            if ($this->execution['fork']) {
                $configuration = $this->configuration;
                $result = app(ForkUpload::class)->run(static function () use ($configuration, $callback, $deadline): mixed {
                    $remaining = (int) ceil(($deadline - hrtime(true)) / 1_000_000_000);

                    if ($remaining < 1) {
                        throw new RuntimeException('The filesystem operation timed out before it could start.');
                    }
                    pcntl_alarm($remaining);

                    return $callback(Storage::build($configuration)->getDriver());
                }, $this->execution['timeout']);
            } else {
                $result = $callback($filesystem);
            }

            ($this->complete)($mutation ? $id : '');

            return $result;
        } catch (Throwable $exception) {
            $this->failure = $exception;

            throw $exception;
        }
    }

    /**
     * @param  Closure(FilesystemOperator): resource  $callback
     * @return resource
     */
    public function readStream(Closure $callback, FilesystemOperator $filesystem): mixed
    {
        if (! $this->execution['fork']) {
            return $this->run('readStream', $callback, $filesystem);
        }
        $buffer = tmpfile();

        if ($buffer === false) {
            $this->failure = new RuntimeException('Unable to allocate a temporary upload read stream.');

            throw $this->failure;
        }

        try {
            $this->run('readStream', static function (FilesystemOperator $filesystem) use ($callback, $buffer): void {
                $stream = $callback($filesystem);

                try {
                    if (stream_copy_to_stream($stream, $buffer) === false || ! fflush($buffer)) {
                        throw new RuntimeException('Unable to transfer the filesystem read stream.');
                    }
                } finally {
                    fclose($stream);
                }
            }, $filesystem);

            if (! rewind($buffer)) {
                throw new RuntimeException('Unable to rewind the filesystem read stream.');
            }

            return $buffer;
        } catch (Throwable $exception) {
            fclose($buffer);
            $this->failure = $exception;

            throw $exception;
        }
    }
}
