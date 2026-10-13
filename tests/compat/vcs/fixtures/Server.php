<?php

namespace Tests\Compat\Vcs\Fixtures;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * Routes requests to the emulated providers, keeps one set of worlds per
 * case namespace, records every API request a namespace received, serves
 * repositories over Git smart HTTP and catches webhook deliveries.
 *
 * Paths: `/~<ns>/<provider>/...` on the main ports; the recorder's provider
 * ports serve one provider each with no prefix (namespace `e2e`). Control:
 * `/_fixture/<ns>/requests` (GET: the recorded requests, then forgets
 * them), `/_fixture/<ns>/script` (POST `{routes: [...]}`: canned answers),
 * `/_fixture/<ns>/config` (POST `{<provider>: {...}}`), `/_fixture/<ns>/caught`
 * (the last webhook delivery) and `/_fixture/<ns>/reset`.
 */
final class Server
{
    /** Providers by name. */
    private const array PROVIDERS = ['github', 'gitlab', 'gitea', 'forgejo', 'gogs', 'bitbucket', 'origin'];

    /** @var array<string, World> by "<ns>/<provider>" */
    private array $worlds = [];

    /** @var array<string, array<array<string, mixed>>> requests by namespace */
    private array $requests = [];

    /** @var array<string, array<array<string, mixed>>> canned answers by namespace */
    private array $scripts = [];

    /** @var array<string, array<string, mixed>> the last delivery each namespace's catcher took */
    private array $caught = [];

    /**
     * @param array<int, string> $ports provider served on each recorder port ("catcher" for the catcher)
     */
    public function __construct(private readonly string $root, private readonly array $ports)
    {
    }

    public function world(string $ns, string $provider): World
    {
        $key = $ns . '/' . $provider;
        if (!isset($this->worlds[$key])) {
            $world = new World($ns, $provider, $this->root . '/' . $ns . '/' . $provider);
            $world->config = Defaults::config($provider);
            $this->worlds[$key] = $world;
        }

        return $this->worlds[$key];
    }

    public function serve(SwooleRequest $request, SwooleResponse $response): void
    {
        try {
            $answer = $this->dispatch($request);
        } catch (\Throwable $error) {
            $answer = Response::json(['message' => 'emulator error: ' . $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()], 599);
        }
        $response->status($answer->status);
        $response->header('Date', 'Thu, 01 Jan 2026 00:00:00 GMT', false);
        $response->header('Server', 'vcs-fixtures', false);
        foreach ($answer->headers as $name => $value) {
            $response->header($name, $value, false);
        }
        $response->end($answer->body);
    }

    private function dispatch(SwooleRequest $request): Response
    {
        $raw = (string) $request->getData();
        [$head] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        $lines = explode("\r\n", $head);
        $line = (string) array_shift($lines);
        [$method, $target] = explode(' ', $line, 3) + ['GET', '/', ''];
        $headers = [];
        foreach ($lines as $header) {
            [$name, $value] = explode(':', $header, 2) + ['', ''];
            $headers[] = [$name, ltrim($value)];
        }
        $body = (string) $request->getContent();
        if (strtolower((string) ($request->header['content-encoding'] ?? '')) === 'gzip') {
            $body = (string) gzdecode($body);
        }
        [$path, $query] = explode('?', $target, 2) + ['', ''];
        $port = (int) ($request->server['server_port'] ?? 0);
        $host = (string) ($request->header['host'] ?? 'localhost');
        $scheme = $port === 8443 ? 'https' : 'http';

        if (str_starts_with($path, '/_fixture/')) {
            return $this->control($method, substr($path, \strlen('/_fixture/')), $body);
        }

        if (isset($this->ports[$port])) {
            $ns = 'e2e';
            $provider = $this->ports[$port];
            $rest = $path;
            $base = "{$scheme}://{$host}";
        } elseif (preg_match('#^/~([^/]+)/([a-z-]+)(/.*)?$#', $path, $m) === 1) {
            $ns = $m[1];
            $provider = $m[2];
            $rest = $m[3] ?? '';
            $base = "{$scheme}://{$host}/~{$ns}/{$provider}";
        } else {
            return Response::json(['message' => 'Not Found'], 404);
        }

        if ($provider === 'catcher') {
            return $this->catcher($ns, $method, $rest, $headers, $body);
        }
        if (!\in_array($provider, self::PROVIDERS, true)) {
            return Response::json(['message' => 'Not Found'], 404);
        }

        $world = $this->world($ns, $provider);
        $incoming = new Request($method, $rest === '' ? '/' : $rest, $query, $headers, $body, $base);

        $git = (new Transport($world, $this))->match($incoming);
        if ($git !== null) {
            return $git;
        }

        $this->record($ns, $provider, $method, $rest . ($query !== '' ? '?' . $query : ''), $headers, $body, $world);

        $scripted = $this->scripted($ns, $provider, $method, $rest, $query, $base);
        if ($scripted !== null) {
            return $scripted;
        }

        $handler = match ($provider) {
            'github' => new GitHub($world, $this),
            'gitlab' => new GitLab($world, $this),
            'gitea', 'forgejo', 'gogs' => new Gitea($world, $this),
            'bitbucket' => new Bitbucket($world, $this),
            default => new Origin($world, $this),
        };

        $answer = $handler->handle($incoming);
        $answer->body = self::now($answer->body);

        return $answer;
    }

    /**
     * The canned answer a case scripted for this request, if any.
     */
    private function scripted(string $ns, string $provider, string $method, string $path, string $query, string $base): ?Response
    {
        foreach ($this->scripts[$ns] ?? [] as $i => $script) {
            if (($script['provider'] ?? $provider) !== $provider || ($script['method'] ?? 'GET') !== $method) {
                continue;
            }
            $wanted = (string) ($script['path'] ?? '');
            if ($wanted !== $path . ($query !== '' ? '?' . $query : '') && $wanted !== $path) {
                continue;
            }
            if (isset($script['times'])) {
                if ($script['times'] <= 0) {
                    continue;
                }
                $this->scripts[$ns][$i]['times']--;
            }
            $answer = $script['body'] ?? '';
            $headers = \is_array($script['headers'] ?? null) ? $script['headers'] : [];
            $text = \is_string($answer) ? $answer : (string) json_encode($answer, JSON_UNESCAPED_SLASHES);
            if (!\is_string($answer) && !isset($headers['Content-Type'])) {
                $headers = ['Content-Type' => 'application/json; charset=utf-8'] + $headers;
            }

            return new Response((int) ($script['status'] ?? 200), $headers, str_replace('${base}', $base, $text));
        }

        return null;
    }

    /**
     * @param array<array{0: string, 1: string}> $headers
     */
    private function record(string $ns, string $provider, string $method, string $target, array $headers, string $body, World $world): void
    {
        // Each runtime reaches the server under its own host name, which a
        // body may carry (a webhook URL): the length counts it as `<host>`
        $host = '';
        foreach ($headers as [$name, $value]) {
            if (strtolower($name) === 'host') {
                $host = $value;
            }
        }
        $mentions = $host === '' ? 0 : substr_count($body, $host);
        $recorded = [];
        foreach ($headers as [$name, $value]) {
            if (strtolower($name) === 'host') {
                $value = '<host>';
            }
            if (strtolower($name) === 'content-length' && $mentions > 0) {
                $value = (string) ((int) $value + $mentions * (\strlen('<host>') - \strlen($host)));
            }
            if (strtolower($name) === 'authorization') {
                $value = Jwt::describe($value, $world);
            }
            $recorded[] = [$name, $value];
        }
        $this->requests[$ns][] = [
            'provider' => $provider,
            'method' => $method,
            'target' => self::normalize($target),
            'headers' => $recorded,
            'body' => self::normalize($body),
        ];
    }

    /**
     * Values that legitimately differ between runs: timestamps of now and
     * random check run keys.
     */
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/check-run-[0-9a-f]{16}/', 'check-run-<random>', self::now($text));
    }

    /**
     * Timestamps of now (a check run's completion time the adapter set) as
     * `<now>`: in what the server records, and in what it answers, which
     * echoes them.
     */
    public static function now(string $text): string
    {
        return (string) preg_replace_callback(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z/',
            static fn (array $m): string => abs((int) strtotime($m[0]) - time()) < 3600 ? '<now>' : $m[0],
            $text,
        );
    }

    private function control(string $method, string $path, string $body): Response
    {
        [$ns, $action] = explode('/', $path, 2) + ['', ''];
        $data = json_decode($body, true);
        $data = \is_array($data) ? $data : [];

        switch ($action) {
            case 'requests':
                $requests = $this->requests[$ns] ?? [];
                $this->requests[$ns] = [];

                return Response::json($requests);
            case 'script':
                foreach ($data['routes'] ?? [] as $route) {
                    $this->scripts[$ns][] = $route;
                }

                return Response::json(['ok' => true]);
            case 'config':
                foreach ($data as $provider => $config) {
                    $world = $this->world($ns, (string) $provider);
                    $config = \is_array($config) ? $config : [];
                    // Repositories a case starts from, for providers whose API cannot create one
                    foreach ($config['repositories'] ?? [] as $seed) {
                        $this->seed($world, $seed);
                    }
                    unset($config['repositories']);
                    $world->config = array_merge($world->config, $config);
                }

                return Response::json(['ok' => true]);
            case 'caught':
                return Response::json($this->caught[$ns] ?? new \stdClass());
            case 'reset':
                foreach (array_keys($this->worlds) as $key) {
                    if (str_starts_with($key, $ns . '/')) {
                        unset($this->worlds[$key]);
                    }
                }
                unset($this->requests[$ns], $this->scripts[$ns], $this->caught[$ns]);
                exec('rm -rf ' . escapeshellarg($this->root . '/' . $ns));

                return Response::json(['ok' => $method !== '']);
        }

        return Response::json(['message' => 'unknown control action'], 404);
    }

    /**
     * Creates a repository with {owner, name, private?, branch?, files?: {path: content}}
     * committed on its default branch.
     *
     * @param array<string, mixed> $seed
     */
    private function seed(World $world, array $seed): void
    {
        $owner = (string) ($seed['owner'] ?? $world->config['owner'] ?? 'test-owner');
        $name = (string) ($seed['name'] ?? 'repository');
        $branch = (string) ($seed['branch'] ?? 'main');
        if ($world->repository($owner, $name) !== null) {
            return;
        }
        $repository = $world->create($owner, $name, (bool) ($seed['private'] ?? false), $branch);
        $files = \is_array($seed['files'] ?? null) ? $seed['files'] : [];
        if ($files !== []) {
            Git::commit($repository->path, $branch, array_map('strval', $files), (string) ($seed['message'] ?? 'Initial commit'), ['name' => 'Fixture', 'email' => 'fixture@example.com']);
        }
    }

    /**
     * @param array<array{0: string, 1: string}> $headers
     */
    private function catcher(string $ns, string $method, string $path, array $headers, string $body): Response
    {
        if ($path === '/__last_request__') {
            return isset($this->caught[$ns]) ? Response::json($this->caught[$ns]) : Response::text('');
        }
        if ($path === '/__clear__') {
            unset($this->caught[$ns]);

            return Response::text('');
        }
        $map = [];
        foreach ($headers as [$name, $value]) {
            $map[$name] = $value;
        }
        $this->caught[$ns] = ['method' => $method, 'url' => $path, 'headers' => $map, 'data' => $body];

        return Response::text('ok');
    }

    /**
     * Delivers a webhook to every hook of the repository subscribed to the
     * event. Only the emulator's own catcher is reachable, so deliveries
     * land there: `{secret}` in a header is the hook's secret, `{signature}`
     * the hex HMAC-SHA256 of the payload with it.
     *
     * @param array<string, string> $headers
     */
    public function deliver(World $world, Repository $repository, string $event, array $headers, string $payload): void
    {
        foreach ($repository->hooks as $hook) {
            if (!\in_array($event, $hook['events'] ?? [], true)) {
                continue;
            }
            $secret = (string) ($hook['secret'] ?? '');
            $map = ['Content-Type' => 'application/json'];
            foreach ($headers as $name => $value) {
                $map[$name] = match ($value) {
                    '{secret}' => $secret,
                    '{signature}' => hash_hmac('sha256', $payload, $secret),
                    default => $value,
                };
            }
            $this->caught[$world->ns] = ['method' => 'POST', 'url' => (string) ($hook['url'] ?? ''), 'headers' => $map, 'data' => $payload];
        }
    }
}
