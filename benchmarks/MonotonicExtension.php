<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Benchmarks;

use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Executor\Benchmark\TemplateExecutor;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\RemoteMethodExecutor;
use PhpBench\Extension\RunnerExtension;
use PhpBench\Remote\Launcher;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MonotonicExtension implements ExtensionInterface
{
    public function load(Container $container): void
    {
        $container->register(self::class, static function (Container $container): CompositeExecutor {
            return new CompositeExecutor(
                new TemplateExecutor($container->get(Launcher::class), __DIR__.'/monotonic.template'),
                new ErrorHandlingExecutorDecorator($container->get(RemoteMethodExecutor::class)),
            );
        }, [RunnerExtension::TAG_EXECUTOR => ['name' => 'monotonic']]);
    }

    public function configure(OptionsResolver $resolver): void {}
}
