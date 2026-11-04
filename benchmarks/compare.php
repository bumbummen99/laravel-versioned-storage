<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$baseline = getenv('VERSIONED_STORAGE_BENCHMARK_BASE') ?: 'HEAD^';
$id = bin2hex(random_bytes(8));
$checkout = $root.'/build/benchmark-base-'.$id;
$tag = 'local_base_'.$id;
$arguments = array_slice($argv, 1);

$run = static function (array $command, array $environment = []) use ($root): void {
    $process = new Process($command, $root, $environment);
    $process->setTimeout(null);
    $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $process->isSuccessful()) {
        throw new ProcessFailedException($process);
    }
};

$revision = new Process(['git', 'rev-parse', '--verify', $baseline.'^{commit}'], $root);
$revision->mustRun();
$sha = trim($revision->getOutput());
$run(['git', 'worktree', 'add', '--detach', $checkout, $sha]);

try {
    fwrite(STDOUT, "Benchmark baseline: {$baseline} ({$sha})\n");
    $run([PHP_BINARY, $root.'/vendor/bin/phpbench', 'run', ...$arguments, '--store', '--tag='.$tag], [
        'VERSIONED_STORAGE_BENCHMARK_SOURCE' => $checkout,
        'XDEBUG_MODE' => 'off',
    ]);

    fwrite(STDOUT, "Benchmark comparison: current working tree versus {$sha}\n");
    $run([PHP_BINARY, $root.'/vendor/bin/phpbench', 'run', ...$arguments, '--ref='.$tag, '--report=aggregate'], [
        'VERSIONED_STORAGE_BENCHMARK_SOURCE' => $root,
        'XDEBUG_MODE' => 'off',
    ]);
} finally {
    $run(['git', 'worktree', 'remove', $checkout]);
}
