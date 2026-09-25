<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Platform\Modules\Migrations\Report\Entry;
use Utopia\Migration\Cache;
use Utopia\Migration\Resource;

final class Report
{
    /**
     * `resourceData` is a 131,070-character column; the encoded report stays below it.
     */
    public const int LIMIT = 128_000;

    public const int MESSAGE_LIMIT = 256;

    /**
     * The transfer cache counts rows and documents per status instead of keeping them.
     */
    private const array AGGREGATED = [Resource::TYPE_ROW, Resource::TYPE_DOCUMENT];

    private const array SEVERITY = [Resource::STATUS_ERROR, Resource::STATUS_WARNING];

    /**
     * @var array<string, array<array-key, Entry>>
     */
    private array $entries = [];

    private int $length = 0;

    private int $count = 0;

    private bool $condensed = false;

    /**
     * @var array<string, array<string, string>>
     */
    private array $issues = [];

    /**
     * @var array<string, string>
     */
    private array $statuses = [];

    public function __construct(
        private readonly int $limit = self::LIMIT,
    ) {
    }

    /**
     * @param array<Resource> $resources
     */
    public function track(array $resources, Cache $cache): void
    {
        foreach ($resources as $resource) {
            $type = $resource->getName();

            if (\in_array($type, self::AGGREGATED, true)) {
                $this->position($type);
                continue;
            }

            $this->record($type, $cache->resolveResourceCacheKey($resource), $resource);
        }

        $this->aggregate($cache);
    }

    /**
     * Records every resource the cache holds, including resources a source
     * added without a progress callback.
     */
    public function reconcile(Cache $cache): void
    {
        foreach ($cache->getAll() as $type => $resources) {
            $this->position($type);

            foreach ($resources as $key => $resource) {
                if ($resource instanceof Resource) {
                    $this->record($type, $key, $resource);
                }
            }
        }

        $this->aggregate($cache);
    }

    public function encode(): string
    {
        $encoded = [];

        if (!$this->condensed) {
            foreach ($this->entries as $entries) {
                foreach ($entries as $entry) {
                    $encoded[] = $entry->json;
                }
            }

            return '[' . \implode(',', $encoded) . ']';
        }

        $length = 2;
        foreach ($this->severity() as $status) {
            foreach ($this->issues[$status] ?? [] as $json) {
                $length += \strlen($json) + ($encoded === [] ? 0 : 1);
                if ($length > $this->limit) {
                    break 2;
                }

                $encoded[] = $json;
            }
        }

        return '[' . \implode(',', $encoded) . ']';
    }

    private function position(string $type): void
    {
        if (!$this->condensed) {
            $this->entries[$type] ??= [];
        }
    }

    private function record(string $type, int|string $key, Resource $resource): void
    {
        if ($this->condensed) {
            $this->file($type, $key, $resource->getId(), $resource->getStatus(), $resource->getMessage());

            return;
        }

        $this->put($type, $key, new Entry($type, $resource->getId(), $resource->getStatus(), $resource->getMessage()));
    }

    private function aggregate(Cache $cache): void
    {
        $cached = $cache->getAll();

        foreach (self::AGGREGATED as $type) {
            foreach ($cached[$type] ?? [] as $status => $count) {
                if ($this->condensed) {
                    return;
                }

                if (\is_string($count)) {
                    $this->put($type, $status, new Entry($type, (string) $status, $count, ''));
                }
            }
        }
    }

    private function put(string $type, int|string $key, Entry $entry): void
    {
        $previous = $this->entries[$type][$key] ?? null;
        $this->entries[$type][$key] = $entry;
        $this->length += \strlen($entry->json);

        if ($previous === null) {
            $this->count++;
        } else {
            $this->length -= \strlen($previous->json);
        }

        if (2 + $this->length + \max(0, $this->count - 1) > $this->limit) {
            $this->condense();
        }
    }

    private function condense(): void
    {
        $this->condensed = true;

        foreach ($this->entries as $type => $entries) {
            if (\in_array($type, self::AGGREGATED, true)) {
                continue;
            }

            foreach ($entries as $key => $entry) {
                $this->file($type, $key, $entry->id, $entry->status, $entry->message);
            }
        }

        $this->entries = [];
        $this->length = 0;
        $this->count = 0;
    }

    private function file(string $type, int|string $key, string $id, string $status, string $message): void
    {
        $slot = $type . ':' . $key;
        $previous = $this->statuses[$slot] ?? null;

        if ($previous !== null && $previous !== $status) {
            unset($this->issues[$previous][$slot], $this->statuses[$slot]);
        }

        if ($status === Resource::STATUS_SUCCESS) {
            return;
        }

        $this->issues[$status][$slot] = (new Entry($type, $id, $status, \mb_substr($message, 0, self::MESSAGE_LIMIT)))->json;
        $this->statuses[$slot] = $status;
    }

    /**
     * @return array<string>
     */
    private function severity(): array
    {
        return \array_unique([...self::SEVERITY, ...\array_keys($this->issues)]);
    }
}
