<?php

declare(strict_types=1);

/*
 * The `migration-api` fixture server: a stand-in for an Appwrite project's
 * API, so the Appwrite source and destination run against canned answers
 * instead of a real project. Each case programs the answers of its own
 * project (the `X-Appwrite-Project` header), and reads back every request
 * the library sent, as both runtimes must send them. Started by
 * tests/compat/migration/services.sh in a throwaway container:
 *
 *   php api.php <port>
 *
 * Routes:
 *   POST /__fixture/routes   {project, routes: [{method, path, status, body, headers, times}]}
 *                            replaces the project's answers; a `*` path segment matches any segment, a final `**` the rest,
 *                            `times` (default unlimited) limits how often a route answers
 *   GET  /__fixture/requests?project=P
 *                            the project's requests since the last read: [{method, path, query,
 *                            headers, body}], multipart bodies as their fields and files
 *   /p/<project>/<rest>      a call for project <project> with path /<rest> (Google APIs for Firebase)
 *   GET  /__timeout/<b>      TargetTimeoutTest's remotes: `streaming` sends 20 bytes, one every 0.2 s;
 *                            `stalled` sends a 20-byte response's head and first byte, then nothing;
 *                            `silent` never answers
 *   anything else            the first matching route of the project, or a 404 Appwrite error
 *
 * A second port (`php api.php <port> <silent port>`) accepts TCP connections
 * and never sends a byte, so a TLS handshake there never completes.
 */

namespace Tests\Compat\Migration\Fixtures;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

final class Api
{
    /** Request headers worth comparing (the rest are transport details). */
    private const HEADERS = [
        'accept', 'authorization', 'content-range', 'content-type', 'user-agent', 'x-appwrite-id', 'x-appwrite-jwt',
        'x-appwrite-key', 'x-appwrite-project', 'x-appwrite-response-format', 'x-sdk-language', 'x-sdk-name',
        'x-sdk-platform', 'x-sdk-version',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $routes = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $requests = [];

    public function handle(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';

        if ($path === '/__fixture/routes') {
            $input = \json_decode((string) $request->getContent(), true);
            $this->routes[(string) ($input['project'] ?? '')] = $input['routes'] ?? [];
            $this->requests[(string) ($input['project'] ?? '')] = [];
            $this->json($response, 200, ['ok' => true]);

            return;
        }
        if (\str_starts_with($path, '/__timeout/')) {
            $this->timeout(\substr($path, \strlen('/__timeout/')), $response);

            return;
        }
        if ($path === '/__fixture/requests') {
            $project = (string) ($request->get['project'] ?? '');
            $this->json($response, 200, $this->requests[$project] ?? []);
            $this->requests[$project] = [];

            return;
        }

        $headers = [];
        foreach ($request->header ?? [] as $name => $value) {
            $name = \strtolower((string) $name);
            if (\in_array($name, self::HEADERS, true)) {
                $headers[$name] = $name === 'content-type' ? \explode(';', (string) $value)[0] : (string) $value;
            }
        }
        \ksort($headers);
        $project = $headers['x-appwrite-project'] ?? '';
        // Calls to other services (Google's, for the Firebase source) name
        // their project in the path instead: /p/<project>/<host>/<path>.
        $pathProject = \str_starts_with($path, '/p/');
        if ($pathProject) {
            [, , $project, $rest] = \array_pad(\explode('/', $path, 4), 4, '');
            $path = '/' . $rest;
        }

        $type = $headers['content-type'] ?? '';
        if ($type === 'multipart/form-data') {
            $files = [];
            foreach ($request->files ?? [] as $name => $file) {
                $files[$name] = [
                    'name' => $file['name'] ?? '',
                    'type' => $file['type'] ?? '',
                    'content' => \base64_encode((string) @\file_get_contents((string) ($file['tmp_name'] ?? ''))),
                ];
            }
            $body = ['fields' => $request->post ?? [], 'files' => $files];
        } else {
            $body = (string) $request->getContent();
        }

        $this->requests[$project][] = [
            'method' => $method,
            'path' => $path,
            'query' => (string) ($request->server['query_string'] ?? ''),
            'headers' => $headers,
            'body' => $body,
        ];

        foreach ($this->routes[$project] ?? [] as $i => $route) {
            if (!self::matches((string) ($route['path'] ?? ''), $path) || \strtoupper((string) ($route['method'] ?? 'GET')) !== $method) {
                continue;
            }
            if (isset($route['times'])) {
                if ($route['times'] <= 0) {
                    continue;
                }
                $this->routes[$project][$i]['times'] = $route['times'] - 1;
            }
            foreach ($route['headers'] ?? [] as $name => $value) {
                $response->header((string) $name, (string) $value);
            }
            // `{base}` in a body is this server as the caller reached it (with
            // the project's path prefix), for URLs the library calls back.
            $base = 'http://' . ($request->header['host'] ?? 'localhost') . ($pathProject ? '/p/' . $project : '');
            $body = $route['body'] ?? [];
            if (\is_string($body)) {
                $response->status((int) ($route['status'] ?? 200));
                $response->end(\str_replace('{base}', $base, $body));
            } else {
                $response->status((int) ($route['status'] ?? 200));
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->end(\str_replace('{base}', \addcslashes($base, '/'), (string) \json_encode($body)));
            }

            return;
        }

        $this->json($response, 404, [
            'message' => 'The requested route was not found.',
            'code' => 404,
            'type' => 'general_route_not_found',
            'version' => '1.9.5',
        ]);
    }

    /**
     * Whether `path` matches `pattern`: a `*` segment matches any one
     * segment, a final `**` segment the rest of the path.
     */
    private static function matches(string $pattern, string $path): bool
    {
        $want = \explode('/', $pattern);
        $have = \explode('/', $path);
        foreach ($want as $i => $segment) {
            if ($segment === '**') {
                return true;
            }
            if (!isset($have[$i]) || ($segment !== '*' && $segment !== $have[$i])) {
                return false;
            }
        }

        return \count($want) === \count($have);
    }

    private function timeout(string $behaviour, Response $response): void
    {
        if ($behaviour === 'silent') {
            \Swoole\Coroutine::sleep(120);

            return;
        }
        $response->header('Content-Type', 'text/plain');
        if ($behaviour === 'stalled') {
            $response->write('x');
            \Swoole\Coroutine::sleep(120);

            return;
        }
        for ($i = 0; $i < 20; $i++) {
            \Swoole\Coroutine::sleep(0.2);
            $response->write('x');
        }
        $response->end();
    }

    private function json(Response $response, int $status, mixed $body): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->end((string) \json_encode($body));
    }
}

$api = new Api();
$server = new Server('0.0.0.0', (int) ($argv[1] ?? 8080));
$server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING]);
$silent = $server->listen('0.0.0.0', (int) ($argv[2] ?? 8081), SWOOLE_SOCK_TCP);
$silent->set(['open_http_protocol' => false]);
$silent->on('receive', static function (): void {
});
$server->on('request', static function (Request $request, Response $response) use ($api): void {
    $api->handle($request, $response);
});
$server->start();
