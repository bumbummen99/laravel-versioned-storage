<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('rejects staging a directory inside a raw PDO transaction before invoking its callback or registering ownership', function () {
    $connection = DB::connection();
    $connection->getPdo()->beginTransaction();

    try {
        expect(fn () => VersionedStorage::stageDirectory('staged', fn () => throw new LogicException('Callback must not run.')))
            ->toThrow(RuntimeException::class, 'Stage first')
            ->and($connection->transactionLevel())->toBe(0)
            ->and($connection->getPdo()->inTransaction())->toBeTrue()
            ->and($connection->table('versioned_storage_stages')->count())->toBe(0)
            ->and($connection->table('versioned_storage_resources')->count())->toBe(0);
        Storage::disk('local')->assertMissing('staged');
    } finally {
        $connection->getPdo()->rollBack();
    }
});
