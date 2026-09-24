<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Pushed Authorization Request request_uri value (RFC 9126).
 *
 * The request_uri is serialized as a deployment-specific prefix plus a stored
 * request id. Parse or build it once at the boundary, then pass the typed value
 * through application code.
 */
class PAR
{
    /**
     * @param non-empty-string $prefix
     * @param non-empty-string $id
     */
    private function __construct(
        private readonly string $prefix,
        private readonly string $id,
    ) {}

    /**
     * Build a PAR value from the deployment-specific prefix and stored request id.
     *
     * @throws InvalidRequestUriException
     */
    public static function fromId(string $prefix, string $id): self
    {
        if ($prefix === '' || $id === '') {
            throw new InvalidRequestUriException('request_uri prefix and id must be non-empty strings.');
        }

        return new self($prefix, $id);
    }

    /**
     * Parse a PAR value from a request_uri after validating its prefix.
     *
     * @throws InvalidRequestUriException
     */
    public static function fromRequestUri(string $prefix, string $requestUri): self
    {
        if ($prefix === '' || !str_starts_with($requestUri, $prefix)) {
            throw new InvalidRequestUriException('Invalid request_uri.');
        }

        $id = substr($requestUri, \strlen($prefix));

        if ($id === '') {
            throw new InvalidRequestUriException('Invalid request_uri.');
        }

        return new self($prefix, $id);
    }

    /**
     * Return the stored request id.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Serialize the value to the RFC 9126 request_uri parameter format.
     */
    public function requestUri(): string
    {
        return "{$this->prefix}{$this->id}";
    }
}
