<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage;

/**
 * Consumer handle to a staged UUID, delegating publication or discard to its captured handler.
 * Keeps that handler's connection/adapter identity alive; does not cache durable stage state.
 * Discard and publication retries are governed by metadata, not this object's lifetime.
 */
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
