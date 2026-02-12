<?php

namespace Appwrite;

use Appwrite\Logs\Log;
use Appwrite\Logs\Resource;

interface Logs
{
    public function append(Log $log): void;

    public function get(string $id): ?Log;

    /**
     * @return Log[]
     */
    public function list(
        Resource $resource,
        string $resourceId,
        int $limit = 100,
        int $offset = 0,
    ): array;

    public function count(
        Resource $resource,
        string $resourceId,
    ): int;

    public function delete(string $id): void;
}
