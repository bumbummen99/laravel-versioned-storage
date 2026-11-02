<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

uses(RefreshDatabase::class)
    ->beforeEach(function () {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
    })
    ->in('Specification', 'Feature');
