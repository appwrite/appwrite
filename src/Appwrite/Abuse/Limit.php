<?php

namespace Appwrite\Abuse;

use Appwrite\Utopia\Request;
use Utopia\Database\Document;
use Utopia\Http\Route;

/**
 * One rate limit a route declares in its `abuse` label: what the count is kept per, how many hits it may take in a
 * window, and what counts as a hit. Each limit carries its own numbers, so a network key never inherits the
 * tight limit meant for an account key, or the reverse. Nothing resets a limit before its window ends, so no
 * success can clear a count an attacker is running up.
 *
 * ```php
 * ->label('abuse', [
 *     Limit::failures(['projectId', 'url', 'param.email'], 10),
 *     Limit::failures(['projectId', 'url', 'ip'], 100),
 * ])
 * ```
 */
final readonly class Limit
{
    /**
     * What a count can be kept per, besides a request param (`param.<name>`).
     */
    public const array PARTS = ['projectId', 'userId', 'userAgent', 'ip', 'url', 'method', 'chunkId'];

    /**
     * @param list<string> $by
     */
    private function __construct(
        public array $by,
        public int $limit,
        public int $seconds,
        public bool $failures,
    ) {
        if ($by === [] || $limit < 1 || $seconds < 1) {
            throw new \InvalidArgumentException('An abuse limit needs at least one part, a limit and a window of at least 1.');
        }

        // An unknown part would key every request the same, sharing one count across all callers.
        foreach ($by as $part) {
            if (! \in_array($part, self::PARTS, true) && (! \str_starts_with($part, 'param.') || $part === 'param.')) {
                throw new \InvalidArgumentException("Abuse limit part '{$part}' is not one of " . \implode(', ', self::PARTS) . ' or param.<name>.');
            }
        }

        // One abuse store serves every project on the instance, and a user id is project local and chosen by the
        // caller while an email or a phone number is the user's own, so the same value exists in other projects.
        // Without the project, a caller on any project spends the count of that account or address everywhere
        // else. A count also kept per ip only ever counts one caller's own requests, so it needs no project.
        $perCaller = \array_values(\array_filter($by, fn (string $part): bool => $part === 'userId' || \str_starts_with($part, 'param.')));
        if ($perCaller !== [] && ! \in_array('projectId', $by, true) && ! \in_array('ip', $by, true)) {
            throw new \InvalidArgumentException("An abuse limit kept per '" . \implode("', '", $perCaller) . "' must also be kept per 'projectId' or per 'ip'.");
        }
    }

    /**
     * Every request counts.
     *
     * @param list<string> $by
     */
    public static function attempts(array $by, int $limit, int $seconds = 3600): self
    {
        return new self($by, $limit, $seconds, false);
    }

    /**
     * Only requests that fail count: each reserves its hit on arrival, atomically, and a request that does not
     * fail gives it back. A busy shared IP is not punished for its successes, and a burst of concurrent requests
     * cannot overshoot.
     *
     * @param list<string> $by
     */
    public static function failures(array $by, int $limit, int $seconds = 3600): self
    {
        return new self($by, $limit, $seconds, true);
    }

    /**
     * This limit's count key for one request, from the values() of that request. A part the request does not
     * carry is empty.
     *
     * @param array<string, string> $values
     */
    public function key(array $values): string
    {
        return \implode(',', \array_map(fn (string $part): string => $part . ':' . ($values[$part] ?? ''), $this->by));
    }

    /**
     * Every part a limit can be kept per, for one request.
     *
     * @param array<string, mixed> $pathParams
     * @return array<string, string>
     */
    public static function values(Route $route, Request $request, Document $project, Document $user, array $pathParams): array
    {
        $values = [
            'projectId' => (string) $project->getId(),
            'userId' => (string) $user->getId(),
            'userAgent' => (string) $request->getUserAgent(''),
            'ip' => (string) $request->getIP(),
            'url' => $route->getPath(),
            'method' => (string) $request->getMethod(),
            'chunkId' => (string) $request->getContentRangeChunk(APP_LIMIT_UPLOAD_CHUNK_SIZE),
        ];

        // Path params are not part of the request input, and the query must not override them.
        foreach (\array_merge($request->getParams(), $pathParams) as $key => $value) {
            if (! empty($value)) {
                $value = (\is_array($value) || \is_object($value)) ? (string) \json_encode($value) : (string) $value;
                // Sign in matches emails case-insensitively, so the limit must too
                $values['param.' . $key] = $key === 'email' ? \strtolower($value) : $value;
            }
        }

        return $values;
    }
}
