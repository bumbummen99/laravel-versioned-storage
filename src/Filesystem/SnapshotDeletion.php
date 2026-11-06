<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Filesystem;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SnapshotDeletion
{
    public static function delete(FilesystemAdapter $filesystem, string $path, bool $directory, ?Throwable $previous = null): void
    {
        $deleted = $directory ? $filesystem->deleteDirectory($path) : $filesystem->delete($path);

        if (! $deleted || $filesystem->exists($path)) {
            throw new RuntimeException("Unable to remove version snapshot [{$path}].", 0, $previous);
        }
    }

    /**
     * @return Closure(): array{version: string, error: ?string}
     */
    public static function task(string $disk, string $path, string $version, bool $directory, string $configuration): Closure
    {
        return static function () use ($disk, $path, $version, $directory, $configuration): array {
            try {
                $filesystem = Storage::disk($disk);

                if (hash('sha256', json_encode($filesystem->getConfig(), JSON_THROW_ON_ERROR)) !== $configuration) {
                    throw new RuntimeException("Disk [{$disk}] has different configuration in the cleanup worker.");
                }

                self::delete($filesystem, $path, $directory);

                return ['version' => $version, 'error' => null];
            } catch (Throwable $exception) {
                return ['version' => $version, 'error' => $exception->getMessage()];
            }
        };
    }
}
