<?php

namespace Appwrite\Logs;

use Appwrite\Logs;

class Redis implements Logs
{
    private const PREFIX_LOG = 'log:';
    private const PREFIX_INDEX = 'logs:';
    private const TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private \Redis $redis,
    ) {
    }

    public function append(Log $log): void
    {
        $id = \uniqid('', true);
        $key = self::PREFIX_LOG . $id;
        $indexKey = self::indexKey($log->resource, $log->resourceId);

        $this->redis->hMSet($key, [
            'resource' => $log->resource->value,
            'resourceId' => $log->resourceId,
            'timestamp' => $log->timestamp,
            'durationSeconds' => $log->durationSeconds,
            'requestMethod' => $log->requestMethod->value,
            'requestScheme' => $log->requestScheme,
            'requestHost' => $log->requestHost,
            'requestPath' => $log->requestPath,
            'requestQuery' => $log->requestQuery,
            'requestSizeBytes' => $log->requestSizeBytes,
            'responseStatusCode' => $log->responseStatusCode,
            'responseSizeBytes' => $log->responseSizeBytes,
        ]);

        $this->redis->expire($key, self::TTL_SECONDS);

        $this->redis->zAdd($indexKey, $log->timestamp, $id);
        $this->redis->expire($indexKey, self::TTL_SECONDS);
    }

    public function get(string $id): ?Log
    {
        $key = self::PREFIX_LOG . $id;

        $data = $this->redis->hGetAll($key);

        if (empty($data)) {
            return null;
        }

        return self::toLog($data);
    }

    /**
     * @return array<string, Log>
     */
    public function list(
        Resource $resource,
        string $resourceId,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $indexKey = self::indexKey($resource, $resourceId);

        $ids = $this->redis->zRevRange($indexKey, $offset, $offset + $limit - 1);

        if (empty($ids)) {
            return [];
        }

        $results = [];

        foreach ($ids as $id) {
            $data = $this->redis->hGetAll(self::PREFIX_LOG . $id);

            if (!empty($data)) {
                $results[$id] = self::toLog($data);
            }
        }

        return $results;
    }

    public function count(
        Resource $resource,
        string $resourceId,
    ): int {
        $indexKey = self::indexKey($resource, $resourceId);

        return (int) $this->redis->zCard($indexKey);
    }

    public function delete(string $id): void
    {
        $key = self::PREFIX_LOG . $id;

        $data = $this->redis->hGetAll($key);

        if (!empty($data)) {
            $indexKey = self::indexKey(
                Resource::from($data['resource']),
                $data['resourceId'],
            );

            $this->redis->zRem($indexKey, $id);
        }

        $this->redis->del($key);
    }

    private static function indexKey(Resource $resource, string $resourceId): string
    {
        return self::PREFIX_INDEX . $resource->value . ':' . $resourceId;
    }

    private static function toLog(array $data): Log
    {
        return new Log(
            resource: Resource::from($data['resource']),
            resourceId: $data['resourceId'],
            timestamp: (float) $data['timestamp'],
            durationSeconds: (float) $data['durationSeconds'],
            requestMethod: Method::from($data['requestMethod']),
            requestScheme: $data['requestScheme'],
            requestHost: $data['requestHost'],
            requestPath: $data['requestPath'],
            requestQuery: $data['requestQuery'],
            requestSizeBytes: (int) $data['requestSizeBytes'],
            responseStatusCode: (int) $data['responseStatusCode'],
            responseSizeBytes: (int) $data['responseSizeBytes'],
        );
    }
}
