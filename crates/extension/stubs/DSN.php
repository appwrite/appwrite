<?php

// Utopia\DSN\DSN is registered by the utopia PHP extension (crates/extension). This file only
// declares it for static analysis and editors; it is never loaded.

namespace Utopia\DSN;

/**
 * A data source name, such as `mysql://user:password@host:3306/database?charset=utf8mb4`.
 */
class DSN implements \Stringable
{
    protected string $scheme;

    protected ?string $user;

    protected ?string $password;

    protected string $host;

    protected ?string $port;

    protected ?string $path;

    protected ?string $query;

    /** @var ?array<string, string> */
    protected ?array $params = null;

    /**
     * @throws \InvalidArgumentException When the DSN cannot be parsed
     */
    public function __construct(#[\SensitiveParameter] string $dsn)
    {
    }

    /**
     * Build a DSN from its parts, encoding each one, so a value cannot reshape it.
     *
     * @param  array<string, string>  $params
     *
     * @throws \InvalidArgumentException When a part cannot be represented as given
     */
    public static function from(
        string $scheme,
        string $host,
        #[\SensitiveParameter] ?string $user = null,
        #[\SensitiveParameter] ?string $password = null,
        ?int $port = null,
        string $path = '',
        array $params = [],
    ): self {
    }

    public function getScheme(): string
    {
    }

    public function getUser(): ?string
    {
    }

    public function getPassword(): ?string
    {
    }

    public function getHost(): string
    {
    }

    public function getPort(): ?string
    {
    }

    public function getPath(): ?string
    {
    }

    /**
     * The raw query string.
     */
    public function getQuery(): ?string
    {
    }

    /**
     * A query parameter by its key.
     */
    public function getParam(string $key, string $default = ''): string
    {
    }

    /**
     * The DSN, with each part encoded as from() encodes it.
     */
    public function __toString(): string
    {
    }
}
