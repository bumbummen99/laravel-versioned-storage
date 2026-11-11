<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use SkyRaptor\LaravelVersionedStorage\Actions\CleanupStorage;
use SkyRaptor\LaravelVersionedStorage\Actions\ReconcileDeletions;
use Throwable;

/**
 * Console adapter: 1. Validate chunk size and transaction state. 2. Invoke CleanupStorage.
 * 3. Report failures and translate overall success into the command exit code.
 * Laravel owns this command's lifetime; each invocation constructs fresh cleanup actions.
 */
class CleanupVersionsCommand extends Command
{
    protected $signature = 'laravel-versioned-storage:cleanup {--chunk=10 : Maximum concurrent snapshot deletions per batch}';

    protected $description = 'Recover interrupted uploads and retry pending version deletions.';

    public function handle(): int
    {
        $connection = DB::connection(config('laravel-versioned-storage.connection'));
        $size = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($size === false) {
            $this->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $pdo = $connection->getRawPdo();

        if ($connection->transactionLevel() > 0 || ($pdo instanceof PDO && $pdo->inTransaction())) {
            $this->error('Version cleanup cannot run inside an active metadata transaction.');

            return self::FAILURE;
        }

        $cleanup = new CleanupStorage($connection, new ReconcileDeletions($connection));

        return $cleanup->cleanup($size, function (Throwable $exception): void {
            $this->failure($exception);
        })
            ? self::SUCCESS : self::FAILURE;
    }

    private function failure(Throwable $exception): void
    {
        report($exception);
        $this->error($exception->getMessage());
    }
}
