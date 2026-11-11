<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Database;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Runs a metadata transaction: 1. Record entry depth. 2. Execute with requested retries.
 * 3. On failure, restore transaction/PDO state without rolling back unrelated caller work.
 *
 * Stateless. The calling lifecycle action determines its boundary and lock order.
 * Recovery failure reports the original exception and replaces it with the recovery exception.
 */
final class MetadataTransaction
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  positive-int  $attempts
     * @return TReturn
     */
    public static function run(Connection $connection, Closure $callback, int $attempts = 1): mixed
    {
        $initialLevel = $connection->transactionLevel();

        try {
            return $connection->transaction($callback, $attempts);
        } catch (Throwable $exception) {
            try {
                // Beginning listeners can throw after the transaction or savepoint opens.
                if ($connection->transactionLevel() > $initialLevel) {
                    $connection->rollBack($initialLevel);
                }

                // Laravel can decrement its counter without rolling back a failed commit.
                if ($connection->transactionLevel() === 0 && self::hasPdoTransaction($connection)) {
                    $connection->getPdo()->rollBack();
                }
            } catch (Throwable $recoveryException) {
                report($exception);

                throw $recoveryException;
            }

            throw $exception;
        }
    }

    public static function assertOutsideTransaction(Connection $connection): void
    {
        if ($connection->transactionLevel() > 0 || self::hasPdoTransaction($connection)) {
            throw new RuntimeException('Uploads cannot run inside a metadata transaction. Stage first, then publish inside the transaction.');
        }
    }

    public static function hasPdoTransaction(Connection $connection): bool
    {
        $pdo = $connection->getRawPdo();

        return $pdo instanceof PDO && $pdo->inTransaction();
    }
}
