<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem\Execution;

use Closure;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Throwable;

/**
 * One callback's operation lifecycle and health, created by UploadStages through UploadJournal.
 * Each call: 1. Check health. 2. Establish a fresh deadline. 3. Commit journal intent.
 * 4. Execute the filesystem operation. 5. Acknowledge it. 6. Return the result.
 *
 * Sequential calls share callback health, not deadlines. Failure is sticky even if caught by
 * application code. Closing permanently rejects further calls; outstanding journals survive.
 * Starting each operation returns its bound acknowledgement, invoked only after successful IO.
 * Only the executor's filesystem work runs in children, never journal callbacks.
 */
final class UploadOperation
{
    private ?Throwable $failure = null;

    private bool $closed = false;

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     * @param  Closure(string, string, UploadDeadline): (Closure(): void)  $beginOperation
     */
    public function __construct(
        private readonly array $execution,
        private readonly array $configuration,
        private readonly Closure $beginOperation,
        private readonly FilesystemOperationExecutor $executor,
        private readonly UploadClock $clock,
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
        $deadline = new UploadDeadline($this->execution['timeout'], $this->execution['fork'], $this->clock);

        try {
            // Read operations also lock/check the stage, but do not leave mutation journals.
            $acknowledge = ($this->beginOperation)($id, $mutation ? $operation : '', $deadline);
            $result = $execute($this->executor, $deadline);

            try {
                $acknowledge();
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
