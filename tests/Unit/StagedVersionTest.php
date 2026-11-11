<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\StagedVersion;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

afterEach(function (): void {
    Mockery::close();
});

it('returns its UUID after delegating default publication without cleanup', function () {
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('publishStage')->once()->with('stage-uuid', false);
    $stage = new StagedVersion($handler, 'stage-uuid');

    expect($stage->publish())->toBe('stage-uuid');
});

it('forwards explicit cleanup publication to its captured handler', function () {
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('publishStage')->once()->with('stage-uuid', true);
    $stage = new StagedVersion($handler, 'stage-uuid');

    expect($stage->publish(cleanup: true))->toBe('stage-uuid');
});

it('delegates each publication retry instead of caching publication state', function () {
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('publishStage')->once()->with('stage-uuid', false)->ordered();
    $handler->shouldReceive('publishStage')->once()->with('stage-uuid', true)->ordered();
    $stage = new StagedVersion($handler, 'stage-uuid');

    expect($stage->publish())->toBe('stage-uuid')
        ->and($stage->publish(true))->toBe('stage-uuid');
});

it('delegates every discard retry to durable handler state', function () {
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('discardStage')->twice()->with('stage-uuid');
    $stage = new StagedVersion($handler, 'stage-uuid');

    $stage->discard();
    $stage->discard();
});

it('does not suppress publication failures or change their identity', function () {
    $failure = new RuntimeException('retired stage');
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('publishStage')->once()->with('stage-uuid', false)->andThrow($failure);
    $stage = new StagedVersion($handler, 'stage-uuid');

    expect(fn () => $stage->publish())->toThrow(fn (RuntimeException $exception) => expect($exception)->toBe($failure));
});

it('does not suppress discard failures or change their identity', function () {
    $failure = new RuntimeException('cleanup unavailable');
    $handler = Mockery::mock(VersionedStorageHandler::class);
    $handler->shouldReceive('discardStage')->once()->with('stage-uuid')->andThrow($failure);
    $stage = new StagedVersion($handler, 'stage-uuid');

    expect(fn () => $stage->discard())->toThrow(fn (RuntimeException $exception) => expect($exception)->toBe($failure));
});

it('keeps the staged UUID immutable', function () {
    $stage = new StagedVersion(Mockery::mock(VersionedStorageHandler::class), 'stage-uuid');

    expect($stage->version)->toBe('stage-uuid');
    expect(function () use ($stage): void {
        $stage->version = 'replacement';
    })->toThrow(Error::class);
});
