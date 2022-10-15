<?php

namespace Appwrite\DSN;

class DSN
{
    /**
     * @var string
     */
    protected string $scheme;

    /**
     * @var ?string
     */
    protected ?string $user;

    /**
     * @var ?string
     */
    protected ?string $password;

    /**
     * @var string
     */
    protected string $host;

    /**
     * @var ?int
     */
    protected ?int $port;

    /**
     * @var ?string
     */
    protected ?string $database;

    /**
     * @var ?string
     */
    protected ?string $query;

    /**
     * Construct
     *
     * Construct a new DSN object
     *
     * @param string $dsn
     */
    public function __construct(string $dsn)
    {
        $parts = parse_url($dsn);

        if (!$parts) {
            throw new \InvalidArgumentException("Unable to parse DSN: $dsn");
        }

        $this->scheme = $parts['scheme'] ?? null;
        $this->user = $parts['user'] ?? null;
        $this->password = $parts['pass'] ?? null;
        $this->host = $parts['host'] ?? null;
        $this->port = (int)$parts['port'] ?? null;
        $this->database = $parts['path'] ?? null;
        $this->query = $parts['query'] ?? null;
    }

    /**
     * Return the scheme.
     *
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Return the user.
     *
     * @return ?string
     */
    public function getUser(): ?string
    {
        return $this->user;
    }

    /**
     * Return the password.
     *
     * @return ?string
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Return the host
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Return the port
     *
     * @return ?int
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Return the database
     *
     * @return ?string
     */
    public function getDatabase(): ?string
    {
        return ltrim($this->database, '/');
    }

    /**
     * Return the query string
     *
     * @return ?string
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }
}
