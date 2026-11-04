<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Throwable;

class VersionedStorageTransactions
{
    /**
     * @var array<int, list<array{handler: VersionedStorageHandler, version: string, level: int}>>
     */
    private array $pending = [];

    /**
     * @var array<int, list<array{handler: VersionedStorageHandler, version: string}>>
     */
    private array $failedDiscards = [];

    public function beginning(TransactionBeginning $event): void
    {
        // Register before application callbacks can prevent the rollback event.
        if (in_array('afterRollBack', get_class_methods($event->connection), true)) {
            $event->connection->afterRollBack(
                fn () => $this->rolledBack(new TransactionRolledBack($event->connection)),
            );
        }
    }

    public function defer(Connection $connection, VersionedStorageHandler $handler, string $version): void
    {
        $this->pending[spl_object_id($connection)][] = [
            'handler' => $handler,
            'version' => $version,
            'level' => $connection->transactionLevel(),
        ];
    }

    public function committed(TransactionCommitted $event): void
    {
        $id = spl_object_id($event->connection);
        $level = $event->connection->transactionLevel();
        $pending = $this->pending[$id] ?? [];
        unset($this->pending[$id]);

        if ($level > 0) {
            foreach ($pending as $item) {
                $item['level'] = min($item['level'], $level);
                $this->pending[$id][] = $item;
            }

            return;
        }

        $this->retainFailedDiscards($event->connection);
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        $id = spl_object_id($event->connection);
        $level = $event->connection->transactionLevel();
        $pending = $this->pending[$id] ?? [];
        unset($this->pending[$id]);
        $error = null;

        foreach ($pending as $item) {
            if ($item['level'] <= $level) {
                $this->pending[$id][] = $item;

                continue;
            }

            try {
                $item['handler']->discard($item['version']);
            } catch (Throwable $exception) {
                $this->failedDiscards[$id][] = [
                    'handler' => $item['handler'],
                    'version' => $item['version'],
                ];
                report($exception);
                $error ??= $exception;
            }
        }

        $this->retainFailedDiscards($event->connection);

        if ($error !== null) {
            throw $error;
        }
    }

    public function retainFailedDiscard(Connection $connection, VersionedStorageHandler $handler, string $version): void
    {
        $this->failedDiscards[spl_object_id($connection)][] = [
            'handler' => $handler,
            'version' => $version,
        ];
        $this->retainFailedDiscards($connection);
    }

    private function retainFailedDiscards(Connection $connection): void
    {
        $id = spl_object_id($connection);
        $failed = $this->failedDiscards[$id] ?? [];
        unset($this->failedDiscards[$id]);
        $error = null;

        foreach ($failed as $item) {
            try {
                $item['handler']->retainDiscard($item['version']);

                // An outer rollback can still erase the newly written retry record.
                if ($connection->transactionLevel() > 0) {
                    $this->failedDiscards[$id][] = $item;
                }
            } catch (Throwable $exception) {
                $this->failedDiscards[$id][] = $item;
                report($exception);
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }
}
