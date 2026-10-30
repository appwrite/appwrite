<?php

namespace Utopia\Cache\Feature;

/** Temporary wire compatibility for a rolling migration from cache 5.x. */
interface Legacy
{
    public function getGeneration(string $key): string;

    /** @param array<int|string, mixed>|string $data */
    public function saveWithGeneration(string $key, array|string $data, string $hash, string $generation): bool;

    public function purgeLegacy(string $key, string $hash = ''): bool;
}
