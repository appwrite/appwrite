<?php

namespace Utopia\Http;

use Utopia\Servers\Hook;

class Route extends Hook
{
    /**
     * HTTP Methods
     *
     * @var array<string>
     */
    protected array $methods = [];

    /**
     * Whether to use hook
     */
    protected bool $hook = true;

    /**
     * Path
     */
    protected string $path = '';

    /**
     * Path params.
     *
     * @var array<string, array<string, int>>
     */
    protected array $pathParams = [];

    /**
     * Alias paths this route is also registered under.
     *
     * @var array<string>
     */
    protected array $aliasPaths = [];

    /**
     * Internal counter.
     */
    protected static int $counter = 0;

    /**
     * Route order ID.
     */
    protected int $order;

    /**
     * @param string|array<int, string> $methods
     */
    public function __construct(string|array $methods, string $path)
    {
        parent::__construct();
        $this->path($path);
        $this->methods = \is_array($methods) ? array_values(array_unique($methods)) : [$methods];
        $this->order = ++self::$counter;
    }

    /**
     * Get Route Order ID
     */
    public function getOrder(): int
    {
        return $this->order;
    }

    /**
     * Add path
     */
    public function path(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Add alias
     */
    public function alias(string $path): self
    {
        Router::addRouteAlias($path, $this);

        if (!\in_array($path, $this->aliasPaths, true)) {
            $this->aliasPaths[] = $path;
        }

        return $this;
    }

    /**
     * When set false, hooks for this route will be skipped.
     */
    public function hook(bool $hook = true): self
    {
        $this->hook = $hook;

        return $this;
    }

    /**
     * Get primary HTTP method.
     */
    #[\Deprecated(message: 'Use getMethods() instead.')]
    public function getMethod(): string
    {
        return $this->methods[0] ?? '';
    }

    /**
     * Get path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get hook status
     */
    public function getHook(): bool
    {
        return $this->hook;
    }

    /**
     * Get HTTP methods this route is registered under.
     *
     * @return array<string>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Set path param.
     */
    public function setPathParam(string $key, int $index, string $path = ''): void
    {
        $this->pathParams[$path][$key] = $index;
    }

    /**
     * Extract this route's path params from a request URL.
     *
     * @return array<string, string>
     */
    public function resolveParams(string $url, string $matchedTemplate = ''): array
    {
        $pathValues = [];
        $parts = explode('/', ltrim($url, '/'));

        if (empty($matchedTemplate)) {
            $pathParams = $this->pathParams[$matchedTemplate] ?? array_values($this->pathParams)[0] ?? [];
        } else {
            $pathParams = $this->pathParams[$matchedTemplate] ?? [];
        }

        foreach ($pathParams as $key => $index) {
            if (\array_key_exists($index, $parts)) {
                $pathValues[$key] = $parts[$index];
            }
        }

        return $pathValues;
    }
}
