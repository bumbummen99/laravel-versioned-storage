<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Orchestra\Testbench\Console\Commander;
use Orchestra\Testbench\Foundation\Config;

define('TESTBENCH_WORKING_PATH', dirname(__DIR__, 3));

require __DIR__.'/../../../vendor/autoload.php';

$root = TESTBENCH_WORKING_PATH;
$config = Config::loadFromYaml(workingPath: $root);
$app = (new Commander($config, $root))->laravel();
$app->handleRequest(Request::capture());
