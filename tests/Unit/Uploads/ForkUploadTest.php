<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;

it('rejects a nonpositive limit before checking runtime support or starting a child', function (int $seconds) {
    expect(fn () => (new ForkUpload)->run(
        fn () => throw new LogicException('Invalid limits must not start work.'), $seconds,
    ))->toThrow(InvalidArgumentException::class, 'at least one second');
})->with(['zero seconds' => 0, 'negative seconds' => -1]);
