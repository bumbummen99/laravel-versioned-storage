<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Uploads;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use stdClass;

final class UploadJournal
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $resourceKey,
    ) {}

    /**
     * @param  array{timeout: int, fork: bool}  $execution
     * @param  array<string, mixed>  $configuration
     */
    public function operation(string $version, array $execution, array $configuration, int $grace): UploadOperation
    {
        $pdo = null;

        return new UploadOperation(
            $execution,
            $configuration,
            function (string $id, string $name, UploadDeadline $deadline) use ($version, $grace, &$pdo): void {
                $pdo = $this->begin($version, $id, $name, $deadline, $grace);
            },
            function (string $id) use ($version, &$pdo): void {
                $this->acknowledge($version, $id, $pdo);
            },
        );
    }

    private function begin(string $version, string $id, string $name, UploadDeadline $deadline, int $grace): PDO
    {
        MetadataTransaction::assertOutsideTransaction($this->connection);
        MetadataTransaction::run($this->connection, function () use ($id, $name, $version, $deadline, $grace): void {
            $stage = $this->lock($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed before a filesystem operation.");
            }

            $cleanupAfter = $deadline->recoveryAfter($grace);
            $this->query($version)->update([
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

    private function acknowledge(string $version, string $id, ?PDO $pdo): void
    {
        if ($this->connection->getRawPdo() !== $pdo) {
            throw new RuntimeException('The upload lost its metadata connection before operation completion.');
        }
        MetadataTransaction::assertOutsideTransaction($this->connection);
        MetadataTransaction::run($this->connection, function () use ($id, $version): void {
            $stage = $this->lock($version);

            if ($stage === null || $stage->state !== 'uploading' || $stage->failed) {
                throw new RuntimeException("Stage [{$version}] failed or was reclaimed during a filesystem operation.");
            }

            if ($id !== '' && $this->connection->table('versioned_storage_operations')->where('id', $id)->delete() !== 1) {
                throw new RuntimeException('The filesystem operation lost its durable journal.');
            }
        });
    }

    public function query(string $version): Builder
    {
        return $this->connection->table('versioned_storage_stages')->useWritePdo()->where('version', $version)
            ->whereIn('resource_id', $this->connection->table('versioned_storage_resources')->select('id')->where('key', $this->resourceKey));
    }

    public function lock(string $version, bool $skipLocked = false): ?stdClass
    {
        $query = $this->query($version);

        if ($this->connection->getDriverName() === 'sqlite') {
            $query->update(['version' => $version]);
        }

        return $query->lock($skipLocked && in_array($this->connection->getDriverName(), ['mysql', 'pgsql'], true)
            ? 'for update skip locked' : true)->first();
    }
}
