<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Support\StreamWrapper;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Independent PHP workers must execute the same mutated source as their parent test.
$original = getenv(Mutate::ENV_MUTATION_TESTING);

if ($original !== false) {
    $replacement = getenv(Mutate::ENV_MUTATION_FILE);

    if ($replacement === false) {
        throw new RuntimeException('The mutation source replacement was not provided to the worker.');
    }

    StreamWrapper::start($original, $replacement);
}
