<?php

use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

$files = [
    'one',
    'two',
    'three',
];

/**
 * Creates a new versioned file and verifies that the file
 * exists & has the correct contents at the same time.
 */
$create = function ($directory, $cleanup = false) use ($files) {
    // Write the files
    $version = VersionedStorage::directory($directory, function ($filesystem) use ($files) {
        foreach ($files as $i => $file) {
            $filesystem->put($file, "File '{$file}' version {$i}");
        }
    }, $cleanup);

    // Verify the files
    foreach ($files as $i => $file) {
        // Check the file
        expect(
            VersionedStorage::directory($directory)->exists($file)
        )->toBeTrue();

        // Verify the contents
        expect(
            VersionedStorage::directory($directory)->get($file)
        )->toBe("File '{$file}' version {$i}");
    }

    return $version;
};

$verify = function ($directory, $version = null) use ($files) {
    // Verify the files
    foreach ($files as $i => $file) {
        // Check the file
        expect(
            VersionedStorage::directory($directory)->exists($file, $version)
        )->toBeTrue();

        // Verify the contents
        expect(
            VersionedStorage::directory($directory)->get($file, $version)
        )->toBe("File '{$file}' version {$i}");
    }
};

it('Can handle multiple files', function () use ($create) {
    $create('foo/bar');
});

it('Can handle multiple multi file versions', function () use ($create, $verify) {
    $directory = 'foo/bar';
    $versions = [];

    // Create multiple versions of the same files
    foreach (range(1, 3) as $i => $file) {
        $versions[] = $create($directory);
    }

    // Check & read again to verify all previous versions still exist
    foreach ($versions as $version) {
        $verify($directory, $version);
    }
});

it('Can cleanup previous multi file versions', function () use ($create, $files, $verify) {
    $directory = 'foo/bar';
    $versions = [];

    // Create multiple versions of the same files
    foreach (range(1, 3) as $i => $file) {
        $versions[] = $create($directory, true);
    }

    // Check & read again to verify all previous versions still exist
    foreach ($versions as $index => $version) {
        if ($index < 2) {
            // Previous version
            foreach ($files as $file) {
                expect(
                    VersionedStorage::directory($directory)->exists($file, $version)
                )->toBeFalse();
            }
        } else {
            // Current version
            $verify($directory, $version);
        }
    }
});

it('Can list available multi file versions', function () use ($create) {
    $directory = 'foo/bar';
    $versions = [];

    // Create multiple versions of the same files
    foreach (range(1, 3) as $i => $file) {
        $versions[] = $create($directory);
    }

    // Verify all previously created versions are listed
    foreach (
        VersionedStorage::directory($directory)->versions() as $i => $version
    ) {
        expect(
            $version
        )->toBe($versions[$i]);
    }
});
