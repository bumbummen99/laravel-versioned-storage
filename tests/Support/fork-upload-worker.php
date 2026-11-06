<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\ForkUpload;

require __DIR__.'/autoload.php';

$directory = $argv[1];
$signal = isset($argv[2]) ? constant($argv[2]) : null;
$parent = getmypid();

(new ForkUpload)->run(function () use ($directory, $signal, $parent): void {
    file_put_contents($directory.'/child', (string) getmypid());

    if ($signal !== null) {
        if (! posix_kill($parent, $signal)) {
            throw new RuntimeException('Unable to signal the upload parent.');
        }
    }

    sleep(3);
    file_put_contents($directory.'/late', 'too late');
}, 1);
