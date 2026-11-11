<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Database;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadDeadline;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;

/**
 * Persists filesystem-operation intent and acknowledgement in the parent process.
 * 1. Lock/check stage and extend its deadline. 2. Commit mutation intent before execution.
 * 3. Verify PDO identity and remove the intent only after successful execution.
 *
 * Handler-scoped and shared, but each successful begin supplies an operation-local acknowledgement
 * capturing its established PDO. No nullable connection tracker is shared between callbacks.
 * ResourceMetadata owns queries/locks; UploadOperation owns execution order and callback health.
 */
final class UploadJournal
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceMetadata $metadata,
    ) {}

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     */
    public function operation(string $version, array $execution, array $configuration, int $grace): UploadOperation
    {
        return new UploadOperation(
            $execution,
            $configuration,
            /** @return Closure(): void */
            function (string $id, string $name, UploadDeadline $deadline) use ($version, $grace): Closure {
                $pdo = $this->begin($version, $id, $name, $deadline, $grace);

                return function () use ($version, $id, $name, $pdo): void {
                    $this->acknowledge($version, $name !== '' ? $id : '', $pdo);
                };
            },
            app(FilesystemOperationExecutor::class),
            app(UploadClock::class),
        );
    }

    private function begin(string $version, string $id, string $name, UploadDeadline $deadline, int $grace): PDO
    {
        MetadataTransaction::assertOutsideTransaction($this->connection);
        MetadataTransaction::run($this->connection, function () use ($id, $name, $version, $deadline, $grace): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed before a filesystem operation.");
            }

            $cleanupAfter = $deadline->recoveryAfter($grace);
            $this->metadata->stage($version)->update([
                'cleanup_after' => max($stage->cleanup_after, $cleanupAfter->toDateTimeString()),
            ]);

            if ($name !== '') {
                $this->connection->table('versioned_storage_operations')->insert([
                    'id' => $id, 'version' => $version, 'operation' => $name, 'cleanup_after' => $cleanupAfter,
                ]);
            }
        });

        return $this->connection->getPdo();
    }

    private function acknowledge(string $version, string $id, PDO $pdo): void
    {
        if ($this->connection->getRawPdo() !== $pdo) {
            throw new RuntimeException('The upload lost its metadata connection before operation completion.');
        }
        MetadataTransaction::assertOutsideTransaction($this->connection);
        MetadataTransaction::run($this->connection, function () use ($id, $version): void {
            $stage = $this->metadata->lockStage($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed during a filesystem operation.");
            }

            if ($id !== '' && $this->connection->table('versioned_storage_operations')->where('id', $id)->delete() !== 1) {
                throw new RuntimeException('The filesystem operation lost its durable journal.');
            }
        });
    }
}
