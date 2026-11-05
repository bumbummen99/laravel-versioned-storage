<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

interface UploadExecutionPolicy
{
    /** @return array{timeout: int, fork: bool} */
    public function execution(): array;
}
