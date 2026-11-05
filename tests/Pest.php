<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\FpmUploadContext;
use SkyRaptor\LaravelVersionedStorage\Tests\TestCase;
use SkyRaptor\LaravelVersionedStorage\UploadContext;

uses(TestCase::class)
    ->beforeEach(function () {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('concurrency.default', 'sync');
        // Most adapter/transaction tests run in-process; real fork coverage uses subprocess fixtures.
        app()->instance(UploadContext::class, new FpmUploadContext);
    })
    ->in('Specification', 'Feature');
