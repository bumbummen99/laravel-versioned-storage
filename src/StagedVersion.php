<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

class StagedVersion
{
    public function __construct(
        private readonly VersionedStorageHandler $handler,
        public readonly string $version,
    ) {}

    public function publish(bool $cleanup = false): string
    {
        $this->handler->publishStage($this->version, $cleanup);

        return $this->version;
    }

    public function discard(): void
    {
        $this->handler->discardStage($this->version);
    }
}
