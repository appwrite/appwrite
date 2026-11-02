<?php

namespace Appwrite\Abuse;

use Appwrite\Utopia\Request;
use Utopia\Database\Document;
use Utopia\Http\Route;

/**
 * One rate limit a route declares in its `abuse` label: a key template, how many hits the key may take in a
 * window, and what counts as a hit. Each limit carries its own numbers, so a network key never inherits the
 * tight limit meant for an account key, or the reverse.
 *
 * ```php
 * ->label('abuse', [
 *     Limit::attempts('projectId:{projectId},url:{url},email:{param-email}', 10, resetOn: [201]),
 *     Limit::failures('projectId:{projectId},url:{url},ip:{ip}', 100),
 * ])
 * ```
 */
final readonly class Limit
{
    /**
     * @param list<int> $resetOn
     */
    private function __construct(
        public string $key,
        public int $limit,
        public int $seconds,
        public bool $failures,
        public array $resetOn,
    ) {
        if ($key === '' || $limit < 1 || $seconds < 1) {
            throw new \InvalidArgumentException("Abuse limit '{$key}' needs a key, a limit and a window of at least 1.");
        }

        // A success proves the secret of the account the request named, never anything about the caller's
        // network. Forgiving a key that names no account lets any success clear it: an attacker logs in to
        // an account of their own between guesses and never meets the limit.
        if ($resetOn !== [] && ! \str_contains($key, '{param-')) {
            throw new \InvalidArgumentException("Abuse limit '{$key}' cannot be reset by a success: it names no account the request proves. Count failures instead.");
        }
    }

    /**
     * Every request counts, on arrival. A response whose status is in $resetOn clears the count, so a user
     * who mistypes and then succeeds is forgiven; that is only allowed on a key naming the account proved.
     *
     * @param list<int> $resetOn
     */
    public static function attempts(string $key, int $limit, int $seconds = 3600, array $resetOn = []): self
    {
        return new self($key, $limit, $seconds, false, $resetOn);
    }

    /**
     * Only requests that end in a client error (4xx other than 429) count, and nothing resets the count.
     * A busy shared IP is not punished for its successes, and a success cannot buy more guesses. Each request
     * reserves its hit on arrival and gives it back unless it fails, so concurrent requests cannot overshoot.
     */
    public static function failures(string $key, int $limit, int $seconds = 3600): self
    {
        return new self($key, $limit, $seconds, true, []);
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
