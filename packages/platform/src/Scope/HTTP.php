<?php

namespace Utopia\Platform\Scope;

trait HTTP
{
    /**
     * @var array<string>
     */
    protected array $httpMethods = [];

    protected ?string $httpPath = null;

    /**
     * @var array<string>
     */
    protected array $httpAliases = [];

    /**
     * Set Http path
     */
    public function setHttpPath(string $path): self
    {
        $this->httpPath = $path;

        return $this;
    }

    /**
     * Set Http Method
     */
    public function setHttpMethod(string $method): self
    {
        $this->httpMethods = [$method];

        return $this;
    }

    /**
     * Set Http Methods
     *
     * @param  array<string>  $methods
     */
    public function setHttpMethods(array $methods): self
    {
        $this->httpMethods = array_values(array_unique($methods));

        return $this;
    }

    /**
     * Get httpPath
     */
    public function getHttpPath(): string
    {
        return $this->httpPath;
    }

    /**
     * Get the value of httpAliases
     *
     * @return array<string>
     */
    public function getHttpAliases(): array
    {
        return $this->httpAliases;
    }

    /**
     * Get the primary HTTP method.
     */
    #[\Deprecated(message: 'Use getHttpMethods() instead.')]
    public function getHttpMethod(): string
    {
        return $this->httpMethods[0] ?? '';
    }

    /**
     * Get the value of httpMethods.
     *
     * @return array<string>
     */
    public function getHttpMethods(): array
    {
        return $this->httpMethods;
    }

    /**
     * Append an httpAlias path. Can be called multiple times to register several aliases.
     */
    public function httpAlias(string $path): self
    {
        $this->httpAliases[] = $path;

        return $this;
    }
}
