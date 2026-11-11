<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use SkyRaptor\LaravelVersionedStorage\Actions\ReconcileDeletions;

afterEach(function (): void {
    Mockery::close();
});

it('rejects malformed or unexpected receipts before issuing any database query', function (mixed $receipt) {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');

    expect(fn () => (new ReconcileDeletions($connection))->reconcile(
        [$receipt], ['old' => 7], static fn () => throw new LogicException('must not report'),
    ))->toThrow(RuntimeException::class, 'Invalid result returned by a cleanup worker; metadata was retained.');
})->with([
    'boolean' => [true],
    'null' => [null],
    'scalar' => ['old'],
    'object' => [(object) ['version' => 'old', 'error' => null]],
    'empty receipt' => [[]],
    'absent version' => [['error' => null]],
    'null version' => [['version' => null, 'error' => null]],
    'integer version' => [['version' => 7, 'error' => null]],
    'array version' => [['version' => ['old'], 'error' => null]],
    'unrequested version' => [['version' => 'other', 'error' => null]],
    'absent error' => [['version' => 'old']],
    'boolean error' => [['version' => 'old', 'error' => false]],
    'integer error' => [['version' => 'old', 'error' => 0]],
    'array error' => [['version' => 'old', 'error' => []]],
    'object error' => [['version' => 'old', 'error' => new stdClass]],
]);

it('retains all metadata when a later receipt duplicates an acknowledged version', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');
    $receipt = ['version' => 'old', 'error' => null];

    expect(fn () => (new ReconcileDeletions($connection))->reconcile(
        [$receipt, $receipt], ['old' => 7], static fn () => null,
    ))->toThrow(RuntimeException::class, 'Invalid result');
});

it('does not partially delete successes when a later receipt is invalid', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');

    expect(fn () => (new ReconcileDeletions($connection))->reconcile(
        [['version' => 'old', 'error' => null], ['version' => 'unknown', 'error' => null]],
        ['old' => 7], static fn () => null,
    ))->toThrow(RuntimeException::class, 'Invalid result');
});

it('rejects incomplete receipt sets without deleting successful metadata', function (array $results) {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');

    expect(fn () => (new ReconcileDeletions($connection))->reconcile(
        $results, ['old' => 7, 'older' => 8], static fn () => null,
    ))->toThrow(RuntimeException::class, 'Missing cleanup worker results; metadata was retained.');
})->with([
    'no receipts' => [[]],
    'only one success' => [[['version' => 'old', 'error' => null]]],
]);

it('accepts an empty batch without consulting the database or reporting failures', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');

    expect((new ReconcileDeletions($connection))->reconcile(
        [], [], static fn () => throw new LogicException('must not report'),
    ))->toBeTrue();
});

it('reports worker failures with their version and retains their metadata', function (string $error) {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');
    $failures = [];

    $success = (new ReconcileDeletions($connection))->reconcile(
        [['version' => 'old', 'error' => $error]], ['old' => 7],
        static function (Throwable $failure) use (&$failures): void {
            $failures[] = $failure;
        },
    );

    expect($success)->toBeFalse()
        ->and($failures)->toHaveCount(1)
        ->and($failures[0])->toBeInstanceOf(RuntimeException::class)
        ->and($failures[0]->getMessage())->toBe("Unable to remove version [old]: {$error}");
})->with(['filesystem failure' => 'disk unavailable', 'empty error still means failure' => '']);

it('reports every failed worker in receipt order', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');
    $messages = [];

    expect((new ReconcileDeletions($connection))->reconcile([
        ['version' => 'older', 'error' => 'one'],
        ['version' => 'old', 'error' => 'two'],
    ], ['old' => 7, 'older' => 8], static function (Throwable $failure) use (&$messages): void {
        $messages[] = $failure->getMessage();
    }))->toBeFalse()
        ->and($messages)->toBe(['Unable to remove version [older]: one', 'Unable to remove version [old]: two']);
});

it('propagates a reporting failure without deleting any acknowledged successes', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldNotReceive('table');
    $failure = new LogicException('reporter failed');

    expect(fn () => (new ReconcileDeletions($connection))->reconcile([
        ['version' => 'old', 'error' => null],
        ['version' => 'older', 'error' => 'disk unavailable'],
    ], ['old' => 7, 'older' => 8], static fn () => throw $failure))->toThrow($failure);
});

it('bulk deletes only acknowledged IDs that are still retired and not current', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $versions = Mockery::mock(Builder::class);
    $resources = (new Builder($connection, new Grammar($connection), new Processor))->from('versioned_storage_resources');
    $connection->shouldReceive('table')->once()->with('versioned_storage_versions')->andReturn($versions);
    $connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $versions->shouldReceive('whereIn')->once()->with('id', [8, 7])->andReturnSelf();
    $versions->shouldReceive('where')->once()->with('pending_delete', true)->andReturnSelf();
    $versions->shouldReceive('whereNotIn')->once()->with('version', Mockery::on(
        static fn (Builder $query): bool => $query->toSql() === 'select "current_version" from "versioned_storage_resources" where "current_version" is not null',
    ))->andReturnSelf();
    $versions->shouldReceive('delete')->once()->andReturn(0);

    expect((new ReconcileDeletions($connection))->reconcile([
        ['version' => 'older', 'error' => null],
        ['version' => 'old', 'error' => null],
    ], ['old' => 7, 'older' => 8], static fn () => throw new LogicException('must not report')))->toBeTrue();
});

it('deletes successful receipts even when another worker failed', function () {
    $connection = Mockery::mock(Connection::class);
    $versions = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->once()->with('versioned_storage_versions')->andReturn($versions);
    $connection->shouldReceive('table')->once()->with('versioned_storage_resources')->andReturn($resources);
    $versions->shouldReceive('whereIn')->once()->with('id', [7])->andReturnSelf();
    $versions->shouldReceive('where')->once()->with('pending_delete', true)->andReturnSelf();
    $resources->shouldReceive('select')->once()->with('current_version')->andReturnSelf();
    $resources->shouldReceive('whereNotNull')->once()->with('current_version')->andReturnSelf();
    $versions->shouldReceive('whereNotIn')->once()->with('version', $resources)->andReturnSelf();
    $versions->shouldReceive('delete')->once()->andReturn(1);
    $failures = [];

    expect((new ReconcileDeletions($connection))->reconcile([
        ['version' => 'old', 'error' => null],
        ['version' => 'older', 'error' => 'disk unavailable'],
    ], ['old' => 7, 'older' => 8], static function (Throwable $failure) use (&$failures): void {
        $failures[] = $failure;
    }))->toBeFalse()->and($failures)->toHaveCount(1);
});

it('propagates a database deletion failure for a later retry', function () {
    $connection = Mockery::mock(Connection::class);
    $versions = Mockery::mock(Builder::class);
    $resources = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('versioned_storage_versions')->andReturn($versions);
    $connection->shouldReceive('table')->with('versioned_storage_resources')->andReturn($resources);
    $versions->shouldReceive('whereIn')->with('id', [7])->andReturnSelf();
    $versions->shouldReceive('where')->with('pending_delete', true)->andReturnSelf();
    $resources->shouldReceive('select')->with('current_version')->andReturnSelf();
    $resources->shouldReceive('whereNotNull')->with('current_version')->andReturnSelf();
    $versions->shouldReceive('whereNotIn')->with('version', $resources)->andReturnSelf();
    $failure = new RuntimeException('database unavailable');
    $versions->shouldReceive('delete')->once()->andThrow($failure);

    expect(fn () => (new ReconcileDeletions($connection))->reconcile(
        [['version' => 'old', 'error' => null]], ['old' => 7], static fn () => null,
    ))->toThrow($failure);
});
