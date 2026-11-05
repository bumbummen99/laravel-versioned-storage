<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\FpmServer;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function (): void {
    $finder = new ExecutableFinder;
    $this->fpmBinary = $finder->find('php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, extraDirs: ['/usr/sbin'])
        ?? $finder->find('php-fpm', extraDirs: ['/usr/sbin']);
    $this->nginxBinary = $finder->find('nginx', extraDirs: ['/usr/sbin']);

    if ($this->fpmBinary === null || $this->nginxBinary === null) {
        $this->markTestSkipped('Requires PHP-FPM and Nginx.');
    }
});

it('retries a confirmed Nginx port collision with a new address', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->not->toBeFalse();
    $address = stream_socket_get_name($socket, false);
    $server = new class($address) extends FpmServer
    {
        public int $attempts = 0;

        public function __construct(private readonly string $occupiedAddress)
        {
            parent::__construct();
        }

        protected function availableAddress(): string
        {
            return ++$this->attempts === 1 ? $this->occupiedAddress : parent::availableAddress();
        }
    };
    Http::fake(function () use ($server) {
        if ($server->attempts === 1) {
            throw new ConnectionException('No fixture listener yet');
        }

        return Http::response('healthy');
    });

    try {
        $server->start($this->fpmBinary, $this->nginxBinary, 0, 0, 10, []);
        expect($server->attempts)->toBe(2);
    } finally {
        try {
            $server->stop();
        } finally {
            fclose($socket);
        }
    }
});

it('reports HTTP startup failures without retrying unrelated errors', function () {
    $server = new FpmServer;
    Http::fake(['*' => Http::response('startup failed', 500)]);

    try {
        expect(fn () => $server->start($this->fpmBinary, $this->nginxBinary, 0, 0, 10, []))
            ->toThrow(RuntimeException::class, 'Unable to start the FPM test server:');
        Http::assertSentCount(1);
    } finally {
        $server->stop();
    }
});

it('stops retrying persistent port collisions after three attempts', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->not->toBeFalse();
    $server = new class(stream_socket_get_name($socket, false)) extends FpmServer
    {
        public int $attempts = 0;

        public function __construct(private readonly string $occupiedAddress)
        {
            parent::__construct();
        }

        protected function availableAddress(): string
        {
            $this->attempts++;

            return $this->occupiedAddress;
        }
    };
    Http::fake(fn () => throw new ConnectionException('No fixture listener yet'));

    try {
        expect(fn () => $server->start($this->fpmBinary, $this->nginxBinary, 0, 0, 10, []))
            ->toThrow(RuntimeException::class, 'Address already in use');
        expect($server->attempts)->toBe(3);
    } finally {
        try {
            $server->stop();
        } finally {
            fclose($socket);
        }
    }
});
