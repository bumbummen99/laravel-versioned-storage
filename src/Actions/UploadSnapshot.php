<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Actions;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotFilesystem;
use SkyRaptor\LaravelVersionedStorage\ResourceIdentity;
use Throwable;

/**
 * Writing workflow: validate execution, register ownership, reserve a destination, commit intent,
 * run the parent callback, then complete and optionally publish the snapshot.
 * Private methods express local steps; registration, destination safety, and stage persistence
 * are cohesive collaborators rather than one class per step.
 *
 * Handler-scoped with invocation state held locally. Each upload has a fresh callback context.
 * runCallback() always closes that context before completion or failure reconciliation.
 * Failure handling starts only after intent/context creation; uncertain writes retain journals.
 */
final class UploadSnapshot
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceIdentity $resource,
        private readonly SnapshotFilesystem $snapshots,
        private readonly RegisterResource $registration,
        private readonly ReserveDestination $reservation,
        private readonly UploadStages $stages,
    ) {}

    /** @param Closure(FilesystemAdapter, string): mixed $callback */
    public function stage(Closure $callback, bool $publish = false, bool $cleanup = false): string
    {
        $settings = $this->validateExecution();
        $this->registration->register();
        $destination = $this->reservation->reserve($settings['execution']);
        $version = $destination['version'];
        $operation = $this->stages->begin($version, $settings['execution'], $destination['configuration'], $settings['grace']);

        try {
            $this->runCallback($callback, $version, $operation);
            $this->stages->complete($version, $publish, $cleanup);
        } catch (Throwable $exception) {
            $this->stages->fail($version, $exception);
        }

        return $version;
    }

    /** @return array{execution: array{timeout: int, fork: bool}, grace: int} */
    private function validateExecution(): array
    {
        MetadataTransaction::assertOutsideTransaction($this->connection);
        $execution = app(UploadExecutionPolicy::class)->execution();
        $grace = filter_var(config('laravel-versioned-storage.cleanup_grace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($grace === false) {
            throw new RuntimeException('Cleanup grace must be a non-negative number of seconds.');
        }

        if ($execution['fork']) {
            $this->assertForkable();
        }

        return ['execution' => $execution, 'grace' => $grace];
    }

    private function assertForkable(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('CLI and development uploads require PCNTL and POSIX for supervised forks.');
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() > 0 || MetadataTransaction::hasPdoTransaction($connection)) {
                throw new RuntimeException('Forked uploads cannot inherit active database transactions. Stage first.');
            }
        }
    }

    /**
     * Close the callback context on every exit, after health and transaction checks on success.
     *
     * @param  Closure(FilesystemAdapter, string): mixed  $callback
     */
    private function runCallback(Closure $callback, string $version, UploadOperation $operation): void
    {
        try {
            $filesystem = $this->snapshots->scoped($version, $operation);

            if ($this->resource->directory) {
                $filesystem->makeDirectory('');
            }
            $callback($filesystem, $version);
            $operation->assertHealthy();
            MetadataTransaction::assertOutsideTransaction($this->connection);
        } finally {
            $operation->close();
        }
    }
}
