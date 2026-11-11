<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use SkyRaptor\LaravelVersionedStorage\Filesystem\SnapshotDeletion;

afterEach(function (): void {
    Mockery::close();
});

it('preserves the original failure when physical snapshot deletion is refused or incomplete', function (bool $deleted, bool $remains) {
    $previous = new RuntimeException('publication failed');
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('delete')->once()->with('report/version')->andReturn($deleted);

    if ($deleted) {
        $filesystem->shouldReceive('exists')->once()->with('report/version')->andReturn($remains);
    }

    expect(fn () => SnapshotDeletion::remove($filesystem, 'report/version', false, $previous))
        ->toThrow(function (RuntimeException $exception) use ($previous): void {
            expect($exception->getMessage())->toBe('Unable to remove version snapshot [report/version].')
                ->and($exception->getCode())->toBe(0)
                ->and($exception->getPrevious())->toBe($previous);
        });
})->with([
    'refused deletion' => [false, true],
    'silently retained snapshot' => [true, true],
]);
