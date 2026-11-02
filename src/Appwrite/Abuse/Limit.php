<?php

namespace Appwrite\Abuse;

use Appwrite\Utopia\Request;
use Utopia\Database\Document;
use Utopia\Http\Route;

/**
 * One rate limit a route declares in its `abuse` label: a key template, how many hits the key may take in a
 * window, and what counts as a hit. Each limit carries its own numbers, so a network key never inherits the
 * tight limit meant for an account key, or the reverse. Nothing resets a limit before its window ends, so no
 * success can clear a count an attacker is running up.
 *
 * ```php
 * ->label('abuse', [
 *     Limit::failures('projectId:{projectId},url:{url},email:{param-email}', 10),
 *     Limit::failures('projectId:{projectId},url:{url},ip:{ip}', 100),
 * ])
 * ```
 */
final readonly class Limit
{
    private function __construct(
        public string $key,
        public int $limit,
        public int $seconds,
        public bool $failures,
    ) {
        if ($key === '' || $limit < 1 || $seconds < 1) {
            throw new \InvalidArgumentException("Abuse limit '{$key}' needs a key, a limit and a window of at least 1.");
        }
    }

    /**
     * Every request counts.
     */
    public static function attempts(string $key, int $limit, int $seconds = 3600): self
    {
        return new self($key, $limit, $seconds, false);
    }

    /**
     * Only requests that fail count: each reserves its hit on arrival, atomically, and a request that does not
     * fail gives it back. A busy shared IP is not punished for its successes, and a burst of concurrent requests
     * cannot overshoot.
     */
    public static function failures(string $key, int $limit, int $seconds = 3600): self
    {
        return new self($key, $limit, $seconds, true);
    }

    /**
     * The values a key may reference, for one request.
     *
     * @param array<string, mixed> $pathParams
     * @return array<string, string>
     */
    public static function params(Route $route, Request $request, Document $project, Document $user, array $pathParams): array
    {
        $params = [
            '{projectId}' => (string) $project->getId(),
            '{userId}' => (string) $user->getId(),
            '{userAgent}' => (string) $request->getUserAgent(''),
            '{ip}' => (string) $request->getIP(),
            '{url}' => $route->getPath(),
            '{method}' => (string) $request->getMethod(),
            '{chunkId}' => (string) $request->getContentRangeChunk(APP_LIMIT_UPLOAD_CHUNK_SIZE),
        ];

        // Path params are not part of the request input, and the query must not override them.
        foreach (\array_merge($request->getParams(), $pathParams) as $key => $value) {
            if (! empty($value)) {
                $value = (\is_array($value) || \is_object($value)) ? (string) \json_encode($value) : (string) $value;
                // Sign in matches emails case-insensitively, so the limit must too
                $params['{param-' . $key . '}'] = $key === 'email' ? \strtolower($value) : $value;
            }
        }

        return $params;
    }
}
