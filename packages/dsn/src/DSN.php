<?php

namespace Utopia\DSN;

class DSN implements \Stringable
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
     * @var ?string
     */
    protected ?string $port;

    /**
     * @var ?string
     */
    protected ?string $path;

    /**
     * @var ?string
     */
    protected ?string $query;

    /**
     * @var ?array
     */
    protected ?array $params = null;

    /**
     * Construct
     *
     * Construct a new DSN object
     *
     * @param  string  $dsn
     */
    public function __construct(#[\SensitiveParameter] string $dsn)
    {
        $parts = \parse_url($dsn);
        // PHP before 8.2 ignores SensitiveParameter, and stack traces print a parameter's current value
        unset($dsn);

        if (!$parts) {
            throw new \InvalidArgumentException('Unable to parse DSN: malformed');
        }

        if (empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Unable to parse DSN: scheme is required');
        }

        if (empty($parts['host'])) {
            throw new \InvalidArgumentException('Unable to parse DSN: host is required');
        }

        $this->scheme = $parts['scheme'];
        $this->user = isset($parts['user']) ? \urldecode($parts['user']) : null;
        $this->password = isset($parts['pass']) ? \urldecode($parts['pass']) : null;
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? null;
        $this->path = isset($parts['path']) ? \rawurldecode(\ltrim((string) $parts['path'], '/')) : '';
        $this->query = $parts['query'] ?? null;
    }

    /**
     * Build a DSN from its parts, encoding each one.
     *
     * Splicing values into a DSN string lets a value reshape it: a `?` in a path
     * starts the query, an `@` in a user moves the host. Each part here is read
     * back exactly as given, or the DSN is refused.
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
        if ($user === null && $password !== null) {
            throw new \InvalidArgumentException('Unable to build DSN: a password needs a user');
        }

        $dsn = $scheme . '://';
        if ($user !== null) {
            $dsn .= \rawurlencode($user) . ($password === null ? '' : ':' . \rawurlencode($password)) . '@';
        }
        $dsn .= $host . ($port === null ? '' : ':' . $port);
        if ($path !== '') {
            $dsn .= '/' . \implode('/', \array_map(\rawurlencode(...), \explode('/', $path)));
        }
        if ($params !== []) {
            $dsn .= '?' . \http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $parsed = new self($dsn);
        unset($dsn);

        // getParam() reads the query with parse_str, which nests `a[b]` and rewrites `.` and spaces in keys
        \parse_str($parsed->query ?? '', $query);

        if (
            $parsed->scheme !== $scheme
            || $parsed->host !== $host
            || $parsed->user !== $user
            || $parsed->password !== $password
            || $parsed->port !== ($port === null ? null : (string) $port)
            || $parsed->path !== $path
            || $query !== $params
        ) {
            throw new \InvalidArgumentException('Unable to build DSN: a part does not survive encoding');
        }

        return $parsed;
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
     * @return ?string
     */
    public function getPort(): ?string
    {
        return $this->port;
    }

    /**
     * Return the path
     *
     * @return ?string
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Return the raw query string
     *
     * @return ?string
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * Return a query parameter by its key
     *
     * @return string
     */
    public function getParam(string $key, string $default = ''): string
    {
        if (isset($this->params[$key])) {
            return $this->params[$key];
        }

        if (! $this->query) {
            return $default;
        }

        parse_str($this->query, $this->params);

        return $this->params[$key] ?? $default;
    }

    /**
     * Return the DSN, with each part encoded as {@see from()} encodes it.
     */
    public function __toString(): string
    {
        $dsn = $this->scheme . '://';
        if ($this->user !== null) {
            $dsn .= \rawurlencode($this->user) . ($this->password === null ? '' : ':' . \rawurlencode($this->password)) . '@';
        }
        $dsn .= $this->host . ($this->port === null ? '' : ':' . $this->port);
        if ($this->path !== null && $this->path !== '') {
            $dsn .= '/' . \implode('/', \array_map(\rawurlencode(...), \explode('/', $this->path)));
        }
        if ($this->query !== null) {
            $dsn .= '?' . $this->query;
        }

        return $dsn;
    }
}
