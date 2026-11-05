<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\ForkUpload;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = $argv[1];

(new ForkUpload)->run(function () use ($directory): void {
    file_put_contents($directory.'/child', (string) getmypid());
    sleep(3);
    file_put_contents($directory.'/late', 'too late');
}, 1);
