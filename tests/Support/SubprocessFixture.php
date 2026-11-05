<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class SubprocessFixture
{
    public readonly string $directory;

    public readonly string $database;

    private readonly mixed $previousConnection;

    private readonly mixed $previousConfiguration;

    /** @var list<Process> */
    private array $processes = [];

    public function __construct(private readonly string $connection, int $busyTimeout = 5000)
    {
        if (array_key_exists($connection, DB::getConnections())) {
            throw new RuntimeException("Subprocess fixture connection [{$connection}] is already resolved.");
        }
        $this->previousConnection = config('laravel-versioned-storage.connection');
        $this->previousConfiguration = config("database.connections.{$connection}");
        $this->directory = sys_get_temp_dir().'/versioned-fixture-'.bin2hex(random_bytes(8));
        $this->database = $this->directory.'/database.sqlite';

        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Unable to create subprocess fixture directory.');
        }

        try {
            if (! touch($this->database)) {
                throw new RuntimeException('Unable to create subprocess fixture database.');
            }
            config()->set("database.connections.{$connection}", [
                'driver' => 'sqlite', 'database' => $this->database,
                'foreign_key_constraints' => true, 'busy_timeout' => $busyTimeout,
            ]);
            config()->set('laravel-versioned-storage.connection', $connection);
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
                (require $path)->up();
            }
        } catch (Throwable $exception) {
            $this->close();

            throw $exception;
        }
    }

    /** @param list<string> $command */
    public function start(array $command, float $timeout = 10): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $this->processes[] = $process;
        $process->start();

        return $process;
    }

    public function close(): void
    {
        try {
            foreach ($this->processes as $process) {
                $process->stop();
            }
        } finally {
            DB::purge($this->connection);
            config()->set("database.connections.{$this->connection}", $this->previousConfiguration);
            config()->set('laravel-versioned-storage.connection', $this->previousConnection);
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }
}
