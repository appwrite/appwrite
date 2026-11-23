<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

use Utopia\Validator\URL as UrlValidator;

final readonly class RouteTarget
{
    /**
     * @param list<string> $groups
     * @param list<string> $scopes
     * @param array<string, array<string, mixed>> $params
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $groups,
        public array $scopes,
        public array $params,
        public bool $docs,
        public bool $mock,
        public bool $inApiGroup,
    ) {
    }

    public function id(): string
    {
        return $this->method . ' ' . $this->path;
    }

    /**
     * @param list<string> $guestScopes
     */
    public function allowsGuest(array $guestScopes): bool
    {
        return \array_intersect($this->scopes, $guestScopes) !== [];
    }

    /**
     * @param list<string> $held
     */
    public function allowsScopes(array $held): bool
    {
        return \array_intersect($this->scopes, $held) !== [];
    }

    public function hasPathParam(string $name): bool
    {
        foreach (\explode('/', $this->path) as $part) {
            if ($part === ':' . $name) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyIdParam(): bool
    {
        foreach (\explode('/', $this->path) as $part) {
            if ($part !== '' && \str_starts_with($part, ':') && \str_ends_with($part, 'Id')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function urlParamNames(): array
    {
        $names = [];

        foreach ($this->params as $name => $param) {
            if ($this->paramLooksLikeUrl((string) $name, $param)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $param
     */
    private function paramLooksLikeUrl(string $name, array $param): bool
    {
        $needle = \strtolower($name);
        foreach (['url', 'endpoint', 'href', 'uri', 'wellknown'] as $token) {
            if (\str_contains($needle, $token)) {
                return true;
            }
        }

        $validator = $param['validator'] ?? null;
        if ($validator instanceof UrlValidator) {
            return true;
        }
        if (\is_object($validator) && ! $validator instanceof \Closure) {
            $class = $validator::class;
            if (\str_contains($class, 'URL') || \str_contains($class, 'Url')) {
                return true;
            }
        }

        $description = \strtolower((string) ($param['description'] ?? ''));

        return \str_contains($description, 'url') || \str_contains($description, 'endpoint');
    }
}
