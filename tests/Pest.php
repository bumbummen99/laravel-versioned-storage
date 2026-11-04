<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('concurrency.default', 'sync');
    })
    ->in('Specification', 'Feature');
