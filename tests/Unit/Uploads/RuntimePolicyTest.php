<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\SynchronousUploadExecution;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies\UploadContext;

it('selects supervision for each supported runtime without an application', function (string $runtime, bool $fork) {
    $configuration = new Repository(['laravel-versioned-storage' => ['upload_timeout' => 23]]);
    $context = new class($configuration, $runtime) extends UploadContext
    {
        public function __construct(Repository $configuration, private readonly string $runtime)
        {
            parent::__construct($configuration);
        }

        protected function sapi(): string
        {
            return $this->runtime;
        }
    };

    expect($context->execution())->toBe(['timeout' => 23, 'fork' => $fork]);
})->with([
    'PHP-FPM' => ['fpm-fcgi', false],
    'console' => ['cli', true],
    'development server' => ['cli-server', true],
]);

it('rejects unsupported runtimes without an application', function (string $runtime) {
    $context = new class(new Repository(['laravel-versioned-storage' => ['upload_timeout' => 10]]), $runtime) extends UploadContext
    {
        public function __construct(Repository $configuration, private readonly string $runtime)
        {
            parent::__construct($configuration);
        }

        protected function sapi(): string
        {
            return $this->runtime;
        }
    };

    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'Uploads require PHP-FPM');
})->with(['Apache' => 'apache2handler', 'CGI' => 'cgi-fcgi', 'unknown' => 'unknown']);

dataset('invalid isolated upload timeouts', [
    'missing' => null,
    'zero' => 0,
    'negative' => -1,
    'fractional' => 1.5,
    'text' => 'invalid',
    'empty string' => '',
    'false' => false,
    'array' => [[]],
]);

it('rejects invalid runtime timeouts before choosing an execution mode', function (mixed $timeout) {
    $context = new UploadContext(new Repository(['laravel-versioned-storage' => ['upload_timeout' => $timeout]]));

    expect(fn () => $context->execution())->toThrow(RuntimeException::class, 'positive number of seconds');
})->with('invalid isolated upload timeouts');

it('rejects invalid synchronous timeouts without an application', function (mixed $timeout) {
    $policy = new SynchronousUploadExecution(new Repository(['laravel-versioned-storage' => ['upload_timeout' => $timeout]]));

    expect(fn () => $policy->execution())->toThrow(RuntimeException::class, 'positive number of seconds');
})->with('invalid isolated upload timeouts');

it('reads runtime configuration again for the next admission', function () {
    $configuration = new Repository(['laravel-versioned-storage' => ['upload_timeout' => 10]]);
    $context = new UploadContext($configuration);

    expect($context->execution())->toBe(['timeout' => 10, 'fork' => true]);
    $configuration->set('laravel-versioned-storage.upload_timeout', 27);
    expect($context->execution())->toBe(['timeout' => 27, 'fork' => true]);
});

it('reads synchronous configuration again for the next admission', function () {
    $configuration = new Repository(['laravel-versioned-storage' => ['upload_timeout' => 10]]);
    $policy = new SynchronousUploadExecution($configuration);

    expect($policy->execution())->toBe(['timeout' => 10, 'fork' => false]);
    $configuration->set('laravel-versioned-storage.upload_timeout', 27);
    expect($policy->execution())->toBe(['timeout' => 27, 'fork' => false]);
});
