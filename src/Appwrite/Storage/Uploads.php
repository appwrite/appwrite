<?php

namespace Appwrite\Storage;

/**
 * Remembers which buckets of a project may hold an unfinished chunked upload, so maintenance
 * can skip the others. Redis may lose the record, so a full scan still runs once per retention period.
 */
final readonly class Uploads
{
    /**
     * @param \Closure(callable(\Redis): mixed): mixed $redis runs a callback with a Redis connection
     */
    public function __construct(private \Closure $redis)
    {
    }

    public function track(string $projectId, string $bucketId): void
    {
        $key = $this->pendingKey($projectId);

        ($this->redis)(function (\Redis $redis) use ($key, $bucketId): void {
            $redis->sAdd($key, $bucketId);
            // Outlives any upload that could still expire; a project that stops uploading lets it lapse.
            $redis->expire($key, APP_STORAGE_UPLOAD_RETENTION * 2);
        });
    }

    /**
     * @return array<string>
     */
    public function buckets(string $projectId): array
    {
        $key = $this->pendingKey($projectId);

        return ($this->redis)(fn (\Redis $redis): array => \array_values($redis->sMembers($key) ?: []));
    }

    public function forget(string $projectId, string $bucketId): void
    {
        $key = $this->pendingKey($projectId);

        ($this->redis)(fn (\Redis $redis) => $redis->sRem($key, $bucketId));
    }

    /**
     * Whether this run should scan every bucket. Only one run per retention period gets true,
     * and a lost key only brings the next scan forward.
     */
    public function claimScan(string $projectId): bool
    {
        $key = 'uploads:scanned:' . $projectId;
        // Spreads the scans of projects that started together over a day.
        $ttl = APP_STORAGE_UPLOAD_RETENTION + \random_int(0, 86400);

        return ($this->redis)(fn (\Redis $redis): bool => $redis->set($key, '1', ['nx', 'ex' => $ttl]) === true);
    }

    private function pendingKey(string $projectId): string
    {
        return 'uploads:pending:' . $projectId;
    }
}
