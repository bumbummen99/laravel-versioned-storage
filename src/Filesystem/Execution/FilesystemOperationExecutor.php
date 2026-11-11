<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem\Execution;

use Closure;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Throwable;

/**
 * Executes one filesystem operation: 1. Check its fixed deadline. 2. Run inline or supervise
 * a fork which rebuilds the disk from captured configuration. 3. Return its result or throw.
 *
 * Stateless, resolved per operation. Does not own journals, publication, or upload callbacks.
 * Forked read streams are copied to a temporary buffer and rewound for the parent caller.
 */
class FilesystemOperationExecutor
{
    /**
     * @template TResult
     *
     * @param  Closure(FilesystemOperator): TResult  $callback
     * @param  array<string, mixed>  $configuration
     * @return TResult
     */
    public function execute(
        Closure $callback,
        FilesystemOperator $filesystem,
        array $configuration,
        UploadDeadline $deadline,
        bool $fork,
    ): mixed {
        $deadline->assertCanStart();

        if (! $fork) {
            return $callback($filesystem);
        }

        return app(ForkUpload::class)->run(static function () use ($configuration, $callback, $deadline): mixed {
            pcntl_alarm($deadline->remainingSeconds());

            return $callback(Storage::build($configuration)->getDriver());
        }, $deadline->timeout);
    }

    /**
     * @param  Closure(FilesystemOperator): resource  $callback
     * @param  array<string, mixed>  $configuration
     * @return resource
     */
    public function readStream(
        Closure $callback,
        FilesystemOperator $filesystem,
        array $configuration,
        UploadDeadline $deadline,
        bool $fork,
    ): mixed {
        if (! $fork) {
            return $this->execute($callback, $filesystem, $configuration, $deadline, false);
        }
        $buffer = tmpfile();

        if ($buffer === false) {
            throw new RuntimeException('Unable to allocate a temporary upload read stream.');
        }

        try {
            $this->execute(static function (FilesystemOperator $filesystem) use ($callback, $buffer): void {
                $stream = $callback($filesystem);

                try {
                    if (stream_copy_to_stream($stream, $buffer) === false || ! fflush($buffer)) {
                        throw new RuntimeException('Unable to transfer the filesystem read stream.');
                    }
                } finally {
                    fclose($stream);
                }
            }, $filesystem, $configuration, $deadline, true);

            if (! rewind($buffer)) {
                throw new RuntimeException('Unable to rewind the filesystem read stream.');
            }

            return $buffer;
        } catch (Throwable $exception) {
            fclose($buffer);

            throw $exception;
        }
    }
}
