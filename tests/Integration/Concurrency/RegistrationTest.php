<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\SubprocessFixture;
use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

it('serializes conflicting first registrations from separate processes', function (string $existing, string $rejected) {
    $fixture = new SubprocessFixture('ownership');

    try {
        $connection = DB::connection('ownership');
        $disk = Storage::disk('local');
        $process = null;
        $stage = VersionedStorage::stageDirectory($existing, function (FilesystemAdapter $files) use (
            &$process, $fixture, $disk, $rejected,
        ): void {
            $files->put('one', 'original');
            $process = $fixture->start([
                PHP_BINARY, TestPaths::root('tests/Support/cleanup-worker.php'),
                $fixture->database, $disk->path(''), 'register', $rejected,
            ], 10);

            expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'registering')))
                ->toBeTrue($process->getErrorOutput().$process->getOutput());
            expect($process->wait())->toBe(0, $process->getErrorOutput().$process->getOutput())
                ->and($process->getOutput())->toContain('rejected:')->not->toContain("accepted\n");
        }, disk: 'local');

        expect($process->wait())->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())->toContain('rejected:')->not->toContain("accepted\n")
            ->and($stage->publish())->toBe($stage->version)
            ->and(VersionedStorage::directory($existing)->get('one'))->toBe('original')
            ->and($disk->allFiles("{$existing}/{$stage->version}"))->toBe(["{$existing}/{$stage->version}/one"])
            ->and($connection->table('versioned_storage_resources')->count())->toBe(1);
    } finally {
        $fixture->close();
    }
})->with([
    'parent registered first' => ['reports', 'reports/monthly'],
    'child registered first' => ['reports/monthly', 'reports'],
]);
