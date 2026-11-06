<?php

declare(strict_types=1);

use SkyRaptor\LaravelVersionedStorage\Tests\Support\TestPaths;

it('locates repository resources independently of the test directory', function () {
    expect(TestPaths::root())->toBe(dirname(__DIR__, 3))
        ->and(TestPaths::root('composer.json'))->toBe(dirname(__DIR__, 3).'/composer.json')
        ->and(is_file(TestPaths::root('tests/Support/cleanup-worker.php')))->toBeTrue()
        ->and(glob(TestPaths::root('database/migrations/*.php')))->not->toBeEmpty();
});
