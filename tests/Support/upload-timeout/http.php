<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Orchestra\Testbench\Console\Commander;
use Orchestra\Testbench\Foundation\Config;

require __DIR__.'/../../../vendor/autoload.php';

$root = dirname(__DIR__, 3);
$config = Config::loadFromYaml(workingPath: $root);
$app = (new Commander($config, $root))->laravel();
$app->handleRequest(Request::capture());
