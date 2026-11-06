<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Uploads;

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
     * @param  Closure(string, string, UploadDeadline): void  $beginOperation
     * @param  Closure(string): void  $acknowledgeOperation
     */
    public function __construct(
        private readonly array $execution,
        private readonly array $configuration,
        private readonly Closure $beginOperation,
        private readonly Closure $acknowledgeOperation,
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
     * @param  (Closure(TResult): void)|null  $cleanupOnAcknowledgmentFailure
     * @return TResult
     */
    private function perform(string $operation, Closure $execute, ?Closure $cleanupOnAcknowledgmentFailure = null): mixed
    {
        $this->assertHealthy();
        $mutation = in_array($operation, [
            'write', 'writeStream', 'createDirectory', 'delete', 'deleteDirectory', 'copy', 'move', 'setVisibility',
        ], true);
        $id = (string) Str::uuid();
        $deadline = new UploadDeadline($this->execution['timeout'], $this->execution['fork'], app(UploadClock::class));

        try {
            // Read operations also lock/check the stage, but do not leave mutation journals.
            ($this->beginOperation)($id, $mutation ? $operation : '', $deadline);
            $result = $execute(app(FilesystemOperationExecutor::class), $deadline);

            try {
                ($this->acknowledgeOperation)($mutation ? $id : '');
            } catch (Throwable $exception) {
                if ($cleanupOnAcknowledgmentFailure !== null) {
                    $cleanupOnAcknowledgmentFailure($result);
                }

                throw $exception;
            }

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
        return $this->perform(
            'readStream',
            fn (FilesystemOperationExecutor $executor, UploadDeadline $deadline): mixed => $executor->readStream(
                $callback, $filesystem, $this->configuration, $deadline, $this->execution['fork'],
            ),
            /** @param resource $stream */
            static function (mixed $stream): void {
                fclose($stream);
            },
        );
    }
}
