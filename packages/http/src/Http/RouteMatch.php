<?php

declare(strict_types=1);

namespace Utopia\Http;

/**
 * The result of matching a request against the registered routes.
 */
final readonly class RouteMatch
{
    public function __construct(
        public Route $route,
        /**
         * Path params parsed from the request URL.
         *
         * For example `['id' => 'abc-123']` when `/users/:id` matches
         * `/users/abc-123`. Empty for static routes and wildcards.
         *
         * @var array<string, string>
         */
        public array $params,
    ) {}
}
