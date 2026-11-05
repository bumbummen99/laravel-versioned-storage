<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Closure;
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
     * @param  Closure(string, string, UploadDeadline): void  $begin
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
        return $this->perform($operation, fn (FilesystemOperationExecutor $executor, UploadDeadline $deadline): mixed => $executor->execute(
            $callback, $filesystem, $this->configuration, $deadline, $this->execution['fork'],
        ));
    }

    /**
     * @template TResult
     *
     * @param  Closure(FilesystemOperationExecutor, UploadDeadline): TResult  $execute
     * @return TResult
     */
    private function perform(string $operation, Closure $execute): mixed
    {
        $this->assertHealthy();
        $mutation = in_array($operation, [
            'write', 'writeStream', 'createDirectory', 'delete', 'deleteDirectory', 'copy', 'move', 'setVisibility',
        ], true);
        $id = (string) Str::uuid();
        $deadline = new UploadDeadline($this->execution['timeout'], $this->execution['fork'], app(UploadClock::class));

        try {
            // Read operations also lock/check the stage, but do not leave mutation journals.
            ($this->begin)($id, $mutation ? $operation : '', $deadline);
            $result = $execute(app(FilesystemOperationExecutor::class), $deadline);

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
        $stream = null;

        try {
            return $this->perform('readStream', function (FilesystemOperationExecutor $executor, UploadDeadline $deadline) use ($callback, $filesystem, &$stream) {
                return $stream = $executor->readStream(
                    $callback, $filesystem, $this->configuration, $deadline, $this->execution['fork'],
                );
            });
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
        }
    }
}
