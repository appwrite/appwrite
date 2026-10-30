<?php

declare(strict_types=1);

namespace Utopia\Cache\Feature;

/** Atomic snapshots whose identities are never reused when a key is evicted. */
interface Versioned
{
    public function supportsVersioning(): bool;

    public function getVersion(string $key): string;

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array;

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): bool;
}
