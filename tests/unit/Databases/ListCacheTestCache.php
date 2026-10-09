<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

final class ListCacheTestCache extends Cache
{
    /**
     * @var array<string, array<string, mixed>> key => hash field => value
     */
    public array $entries = [];

    public function __construct()
    {
        parent::__construct(new None());
    }

    #[\Override]
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->entries[$key][$hash] ?? false;
    }

    #[\Override]
    public function save(string $key, mixed $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $this->entries[$key][$hash] = self::stored($data);

        return true;
    }

    #[\Override]
    public function saveMany(string $key, array $data, int $ttl = 0): array
    {
        foreach ($data as $field => $value) {
            $this->entries[$key][$field] = self::stored($value);
        }

        return $data;
    }

    #[\Override]
    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash === '') {
            unset($this->entries[$key]);

            return true;
        }

        unset($this->entries[$key][$hash]);

        return true;
    }

    /**
     * @return array<int|string, mixed>|string
     */
    private static function stored(mixed $data): array|string
    {
        if (\is_string($data) || \is_array($data)) {
            return $data;
        }

        throw new \TypeError('The cache adapters store a string or an array, ' . \get_debug_type($data) . ' given');
    }
}
