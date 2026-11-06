<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use SkyRaptor\LaravelVersionedStorage\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\ProcessWait;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use Symfony\Component\Process\Process;

describe('forked uploads', function () {
    beforeEach(function () {
        if (PHP_OS_FAMILY === 'Windows' || ! extension_loaded('pcntl') || ! extension_loaded('posix') || ! extension_loaded('sockets')) {
            $this->markTestSkipped('Forked uploads require Unix, PCNTL, POSIX and sockets.');
        }

        $this->forkUploadDirectory = storage_path('framework/testing/fork-upload-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->forkUploadDirectory, recursive: true);
    });

    afterEach(function () {
        if (isset($this->forkUploadDirectory)) {
            File::deleteDirectory($this->forkUploadDirectory);
        }
    });

    it('executes a void callback in one child and waits for its filesystem effects', function () {
        $path = $this->forkUploadDirectory.'/completed';
        $parent = getmypid();

        (new ForkUpload)->run(function () use ($path): void {
            file_put_contents($path, (string) getmypid());
        }, 5);

        expect(file_exists($path))->toBeTrue()
            ->and((int) file_get_contents($path))->not->toBe($parent);
    });

    it('propagates the callback exception without reporting a timeout', function () {
        expect(fn () => (new ForkUpload)->run(function (): void {
            throw new LogicException('upload failed', 123);
        }, 5))->toThrow(function (LogicException $exception): void {
            expect($exception->getMessage())->toBe('upload failed')
                ->and($exception->getCode())->toBe(123);
        });
    });

    it('transfers large callback exceptions without truncating their payload', function () {
        $message = str_repeat('upload failure details ', 16384);

        expect(fn () => (new ForkUpload)->run(function () use ($message): void {
            throw new LogicException($message, 123);
        }, 5))->toThrow(function (LogicException $exception) use ($message): void {
            expect($exception->getMessage())->toBe($message)
                ->and($exception->getCode())->toBe(123);
        });
    });

    it('rejects a nonpositive time limit', function (int $seconds) {
        expect(fn () => (new ForkUpload)->run(function (): void {}, $seconds))
            ->toThrow(InvalidArgumentException::class);
    })->with([0, -1]);

    it('reports an unsupported runtime explicitly instead of running the task inline', function () {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'disable_functions=pcntl_fork',
            TestPaths::root('tests/Support/fork-upload-worker.php'),
            $this->forkUploadDirectory,
        ]);
        $process->setTimeout(3);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('RuntimeException', 'Forked uploads require')
            ->and(file_exists($this->forkUploadDirectory.'/child'))->toBeFalse();
    });

    it('kills a child blocked in native IO before it can write again', function () {
        $path = $this->forkUploadDirectory.'/late';
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($sockets)->toBeArray();
        $started = hrtime(true);
        $asynchronous = pcntl_async_signals();

        try {
            expect(fn () => (new ForkUpload)->run(function () use ($sockets, $path): void {
                fread($sockets[0], 1);
                file_put_contents($path, 'too late');
            }, 1))->toThrow(RuntimeException::class, 'timed out');

            expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThan(0.8)->toBeLessThan(3);
            expect(pcntl_async_signals())->toBe($asynchronous);
            fwrite($sockets[1], 'x');
            usleep(100_000);
            expect(file_exists($path))->toBeFalse();
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
    });

    it('enforces its alarm even if the parent ignores and blocks SIGALRM', function () {
        $handler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_sigprocmask(SIG_BLOCK, [SIGALRM], $mask);
        $started = hrtime(true);

        try {
            expect(fn () => (new ForkUpload)->run(function (): void {
                sleep(3);
            }, 1))->toThrow(RuntimeException::class, 'timed out');

            expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(2.5)
                ->and(pcntl_signal_get_handler(SIGALRM))->toBe(SIG_IGN);
            pcntl_sigprocmask(SIG_BLOCK, [SIGALRM], $currentMask);
            expect($currentMask)->toContain(SIGALRM);
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $mask);
            pcntl_signal(SIGALRM, $handler);
        }
    });

    it('rejects an abnormal child exit instead of treating absent output as success', function (int $code) {
        expect(fn () => (new ForkUpload)->run(function () use ($code): void {
            exit($code);
        }, 5))->toThrow(RuntimeException::class, 'exited abnormally');
    })->with([0, 1]);

    it('restores parent signal handlers and asynchronous signal dispatch', function (bool $asynchronous) {
        $previousAsync = pcntl_async_signals();
        $previousHandlers = [];
        $handler = function (): void {};

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $handler);
        }

        pcntl_async_signals($asynchronous);

        try {
            (new ForkUpload)->run(function (): void {}, 5);
            expect(pcntl_async_signals())->toBe($asynchronous);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                expect(pcntl_signal_get_handler($signal))->toBe($handler);
            }

            expect(fn () => (new ForkUpload)->run(function (): void {
                throw new LogicException('failed');
            }, 5))->toThrow(LogicException::class);
            expect(pcntl_async_signals())->toBe($asynchronous);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                expect(pcntl_signal_get_handler($signal))->toBe($handler);
            }
        } finally {
            foreach ($previousHandlers as $signal => $previousHandler) {
                pcntl_signal($signal, $previousHandler);
            }
            pcntl_async_signals($previousAsync);
        }
    })->with([false, true]);

    it('prevents later child writes when the parent is terminated', function (string $signal) {
        $directory = $this->forkUploadDirectory;
        $process = new Process([PHP_BINARY, TestPaths::root('tests/Support/fork-upload-worker.php'), $directory, $signal]);
        $process->setTimeout(6);
        $child = null;

        try {
            $process->start();
            // The parent can be killed before the marker is read.
            ProcessWait::until(fn (): bool => file_exists($directory.'/child'), [], 3, fn (): string => $process->getOutput().$process->getErrorOutput());

            expect(file_exists($directory.'/child'))->toBeTrue($process->getErrorOutput());
            $child = (int) file_get_contents($directory.'/child');
            ProcessWait::observe(function () use ($process, $directory): bool {
                $process->checkTimeout();

                return ! file_exists($directory.'/late');
            }, [], 3.5, fn (): string => $process->getOutput().$process->getErrorOutput());

            expect($process->isRunning())->toBeFalse()
                ->and($process->hasBeenSignaled())->toBeTrue($process->getErrorOutput())
                ->and($process->getTermSignal())->toBe(SIGKILL)
                ->and(file_exists($directory.'/late'))->toBeFalse();
        } finally {
            if ($child !== null && posix_kill($child, 0)) {
                posix_kill($child, SIGKILL);
            }

            if ($process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
        }
    })->with([
        'parent SIGTERM cleanup' => ['SIGTERM'],
        'parent SIGKILL child alarm' => ['SIGKILL'],
    ]);
});
