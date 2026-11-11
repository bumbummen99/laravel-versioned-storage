<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Contracts\UploadExecutionPolicy;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('SkyRaptor\LaravelVersionedStorage')
    ->toUseStrictTypes();

arch('filesystem execution policies implement their subsystem contract')
    ->expect('SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\Policies')
    ->toImplement(UploadExecutionPolicy::class);
