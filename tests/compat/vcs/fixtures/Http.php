<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * A request as a provider handler sees it: the path below the provider's
 * base, the query, headers and body.
 */
final class Request
{
    /** @var array<string, string> lowercase names */
    public array $headers = [];

    /** @var array<string, mixed> */
    public array $query = [];

    /**
     * @param array<array{0: string, 1: string}> $rawHeaders names as sent, in order
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $queryString,
        public readonly array $rawHeaders,
        public readonly string $body,
        public readonly string $base,
    ) {
        foreach ($rawHeaders as [$name, $value]) {
            $this->headers[strtolower($name)] = $value;
        }
        parse_str($queryString, $query);
        $this->query = $query;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The JSON body as an array (empty when it is not JSON).
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The form body.
     *
     * @return array<mixed>
     */
    public function form(): array
    {
        parse_str($this->body, $form);

        return $form;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * The token the request authenticates with (bearer, `token`, private
     * token or basic password), or ''.
     */
    public function token(): string
    {
        $authorization = $this->header('authorization');
        foreach (['Bearer ', 'token ', 'Token '] as $scheme) {
            if (str_starts_with($authorization, $scheme)) {
                return substr($authorization, \strlen($scheme));
            }
        }
        if (str_starts_with($authorization, 'Basic ')) {
            $pair = (string) base64_decode(substr($authorization, 6), true);

            return str_contains($pair, ':') ? substr($pair, strpos($pair, ':') + 1) : $pair;
        }

        return $this->header('private-token');
    }
}

/**
 * A response: status, headers and body.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * A JSON response.
     *
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES);

        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }

    /** An empty object as JSON (`{}`). */
    public static function object(int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], '{}');
    }

    public static function empty(int $status = 204): self
    {
        return new self($status);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain; charset=utf-8'): self
    {
        return new self($status, ['Content-Type' => $type], $text);
    }
}

/**
 * A provider API emulated over a world: routes and their handlers.
 */
abstract class Provider
{
    /** @var array<array{0: string, 1: string, 2: callable(array<string>): Response}> */
    private array $routes = [];

    protected Request $request;

    public function __construct(protected World $world, protected Server $server)
    {
        $this->routes();
    }

    /** Registers the routes. */
    abstract protected function routes(): void;

    /** The answer to an unknown route or resource. */
    abstract protected function notFound(): Response;

    /**
     * Whether the request carries a credential the provider accepts.
     */
    protected function authenticated(): bool
    {
        return $this->request->token() !== '';
    }

    abstract protected function unauthorized(): Response;

    /**
     * @param callable(array<string>): Response $handler
     */
    protected function on(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method) {
                continue;
            }
            $regex = '#^' . preg_replace('#\{(\w+)\*\}#', '(?<$1>.+)', (string) preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $pattern)) . '$#';
            if (preg_match($regex, $request->path, $matches) === 1) {
                $params = array_map(rawurldecode(...), array_filter($matches, \is_string(...), ARRAY_FILTER_USE_KEY));

                return $handler($params);
            }
        }

        return $this->notFound();
    }

    /**
     * The commit author of writes made through the API.
     *
     * @return array{name: string, email: string}
     */
    protected function author(): array
    {
        $login = (string) ($this->world->config['user'] ?? 'utopia');

        return ['name' => $login, 'email' => $login . '@example.com'];
    }
}
