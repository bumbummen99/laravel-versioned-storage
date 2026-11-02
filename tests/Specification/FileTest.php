<?php

use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

/**
 * Creates a new versioned file and verifies that the file
 * exists & has the correct contents at the same time.
 */
$create = function ($file, $content, $cleanup = false) {
    // Write the file
    $version = VersionedStorage::put($file, $content, $cleanup);

    // Check the file
    expect(
        VersionedStorage::exists($file)
    )->toBeTrue();

    // Verify the contents
    expect(
        VersionedStorage::get($file)
    )->toBe($content);

    return $version;
};

/**
 * Verifies that the provided file (version) does exist
 * and has the expected contents.
 */
$verify = function ($file, $content, $version = null) {
    // Check the file
    expect(
        VersionedStorage::exists($file, $version)
    )->toBeTrue();

    // Verify the contents
    expect(
        VersionedStorage::get($file, $version)
    )->toBe($content);
};

it('Can handle a single file', function () use ($create) {
    $file = '/foo/bar/baz';

    $create($file, 'Hello, World!');
});

it('Can handle multiple single file versions', function () use ($create, $verify) {
    $file = '/foo/bar/baz';
    $versions = [];

    // Create multiple versions of the same file
    foreach (range(1, 3) as $i) {
        $versions[] = $create($file, "File {$i}");
    }

    // Check & read again to verify all previous versions still exist
    foreach ($versions as $index => $version) {
        $verify($file, 'File '.($index + 1), $version);
    }
});

it('Can cleanup previous single file versions', function () use ($create, $verify) {
    $file = '/foo/bar/baz';
    $versions = [];

    // Create multiple versions of the same file
    foreach (range(1, 3) as $i) {
        // Write the file & cleanup previous versions
        $versions[] = $create($file, "File {$i}", true);
    }

    // Check & read again to verify all previous versions have been removed
    foreach ($versions as $index => $version) {
        if ($index < 2) {
            // Previous version
            expect(
                VersionedStorage::exists($file, $version)
            )->toBeFalse();
        } else {
            // Current version
            $verify($file, 'File '.($index + 1), $version);
        }
    }
});

it('Can list available single file versions', function () use ($create) {
    $file = '/foo/bar/baz';
    $versions = [];

    // Create multiple versions of the same file
    foreach (range(1, 3) as $i) {
        $versions[] = $create($file, "File {$i}");
    }

    // Verify all previously created versions are listed
    foreach (
        VersionedStorage::versions($file) as $i => $version
    ) {
        expect(
            $version
        )->toBe($versions[$i]);
    }
});
