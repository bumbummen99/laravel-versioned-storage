<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Throwable;

class VersionedStorageTransactions
{
    /**
     * @var array<int, list<array{handler: VersionedStorageHandler, version: ?string, level: int}>>
     */
    private array $pending = [];

    public function defer(Connection $connection, VersionedStorageHandler $handler, ?string $version = null): void
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

        $error = null;

        foreach ($pending as $item) {
            try {
                $item['handler']->cleanup();
            } catch (Throwable $exception) {
                report($exception);
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
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

            if ($item['version'] !== null) {
                try {
                    $item['handler']->discard($item['version']);
                } catch (Throwable $exception) {
                    report($exception);
                    $error ??= $exception;
                }
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }
}
