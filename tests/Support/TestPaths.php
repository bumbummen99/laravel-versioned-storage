<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

final class TestPaths
{
    public static function root(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);

        return $path === '' ? $root : $root.'/'.$path;
    }
}
