<?php

namespace Tests\Compat\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Destinations;
use Utopia\Client\Destinations\Anywhere;
use Utopia\Client\Destinations\IPRange;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Client\Pool;
use Utopia\Client\Redirect;
use Utopia\Client\Response\Builder;
use Utopia\Client\Tls;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;

/**
 * Maps tests/compat/client/spec.json operations onto utopia-php/client (cURL adapter). Glue only.
 *
 * URIs in arguments carry `${http}` and `${https}`, the fixture server's
 * authority for this runtime (services `client-http`, `client-https`);
 * results carry them back in place of that authority, so both runtimes'
 * results compare.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";

        return [
            'resolve' => ['Resolve a relative redirect location against a request URI', fn (Session $s) => fn () => (string) Redirect::resolve(Uri::parse('https://api.example.com/v1/users/42?expand=true'), '../teams/7?page=2#top')],
            'public_internet' => ['Check a connected address against PublicInternet', function (Session $s) {
                $destinations = new PublicInternet(new IPRange('10.0.0.0/8'));

                return fn () => $destinations->allows('93.184.216.34');
            }],
            'backoff' => ['Decide the delay after a 503 response', function (Session $s) {
                $backoff = new Backoff(randomizer: fn () => 0.5);
                $request = new Request('GET', Uri::parse('https://api.example.com/v1/users'));
                $response = new Response(503);

                return fn () => $backoff->delay($request, 1, $response, null);
            }],
            'round_trip' => ['Send a GET to the fixture server on a reused connection and read its JSON response', function (Session $s) {
                $client = (new Client(new Curl()))->withConnectionReuse();
                $request = new Request('GET', Uri::parse('http://' . $s->service('client-http') . '/echo'));

                return fn () => (string) $client->sendRequest($request)->getBody();
            }],
            'raw_response' => ['Send a GET answered with a fixed response on a fresh connection', function (Session $s) use ($raw) {
                $client = new Client(new Curl());
                $request = new Request('GET', Uri::parse('http://' . $s->service('client-http') . '/raw?r=' . urlencode(base64_encode($raw))));

                return fn () => $client->sendRequest($request)->getStatusCode();
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'uri.parse' => fn (array $a, Session $s) => self::uri(Uri::parse($a['uri'])),

            'redirect.resolve' => fn (array $a, Session $s) => (string) Redirect::resolve(Uri::parse($a['base']), $a['location']),
            'redirect.remove_dot_segments' => fn (array $a, Session $s) => Redirect::removeDotSegments($a['path']),
            'redirect.origin' => fn (array $a, Session $s) => [
                'same' => Redirect::isSameOrigin(Uri::parse($a['from']), Uri::parse($a['to'])),
                'strip' => Redirect::shouldStripSensitiveHeaders(Uri::parse($a['from']), Uri::parse($a['to'])),
            ],
            'redirect.without_sensitive_headers' => fn (array $a, Session $s) => self::headers(
                Redirect::withoutSensitiveHeaders(self::request(['method' => 'GET', 'uri' => 'http://example.com/', 'headers' => $a['headers']], $s))->getHeaders()
            ),
            'redirect.is_redirect' => fn (array $a, Session $s) => Redirect::isRedirect(self::response($a)),

            'iprange.contains' => function (array $a, Session $s) {
                $range = new IPRange($a['range']);

                return array_map(fn ($address) => $range->contains($address), $a['addresses'] ?? []);
            },
            'destinations.allows' => function (array $a, Session $s) {
                $destinations = self::destinations($a['destinations'], $s);

                return ['allows' => array_map(fn ($address) => $destinations->allows($address), $a['addresses']), 'proxy' => $destinations->permitsProxy()];
            },

            'backoff.delay' => function (array $a, Session $s) {
                $request = self::request(['method' => $a['method'], 'uri' => 'http://example.com/'], $s);
                $error = isset($a['error']) ? self::exception($a['error'], $request) : null;

                return self::backoff($a['strategy'] ?? [])->delay($request, $a['attempt'], $error === null ? self::response($a['response']) : null, $error);
            },
            'exception.kind' => function (array $a, Session $s) {
                $error = self::exception($a['class'], self::request(['method' => 'GET', 'uri' => 'http://example.com/'], $s));

                return ['client' => $error instanceof ClientExceptionInterface, 'network' => $error instanceof NetworkExceptionInterface, 'request' => $error instanceof RequestExceptionInterface];
            },

            'response.build' => fn (array $a, Session $s) => self::result((new Builder(new Response\Factory(), new Stream\Factory()))->build(
                $a['status'],
                $a['reason'] ?? '',
                self::grouped($a['headers'] ?? []),
                $a['body'] ?? '',
                $a['protocol'] ?? '1.1',
            ), $s),

            'client.new' => fn (array $a, Session $s) => $s->handle(self::client($a, $s)),
            'client.send' => fn (array $a, Session $s) => self::traced($a, function () use ($a, $s) {
                [$client, $sleeps] = isset($a['client']) ? $s->get($a['client']) : self::client([], $s);
                $result = self::result($client->sendRequest(self::request($a['request'], $s)), $s);

                return $sleeps->list === [] ? $result : $result + ['sleeps' => $sleeps->list];
            }),
            'client.stream' => fn (array $a, Session $s) => self::traced($a, function () use ($a, $s) {
                [$client, $sleeps] = isset($a['client']) ? $s->get($a['client']) : self::client([], $s);
                $streamed = '';
                /** @var list<mixed> $notified */
                $notified = [];
                $ordered = true;
                $sink = function (string $chunk) use (&$streamed, &$notified, &$ordered) {
                    $ordered = $ordered && $notified !== [];
                    $streamed .= $chunk;
                };
                if ($a['on_response'] ?? false) {
                    $response = $client->stream(self::request($a['request'], $s), $sink, function (ResponseInterface $response) use (&$notified, $s) {
                        $notified[] = self::result($response, $s);
                    });
                } else {
                    $response = $client->stream(self::request($a['request'], $s), function (string $chunk) use (&$streamed) {
                        $streamed .= $chunk;
                    });
                }
                $result = self::result($response, $s) + ['streamed' => self::localize($streamed, $s)];
                if ($a['on_response'] ?? false) {
                    $result += ['notified' => $notified, 'ordered' => $ordered];
                }

                return $sleeps->list === [] ? $result : $result + ['sleeps' => $sleeps->list];
            }),
            'client.supports_response_streaming' => fn (array $a, Session $s) => $s->get($a['client'])[0]->supportsResponseStreaming(),
            'client.record' => function (array $a, Session $s) {
                [, $sleeps, $calls] = $s->get($a['client']);

                return ['sleeps' => $sleeps->list, 'calls' => $calls?->count];
            },
            'pool.send' => function (array $a, Session $s) {
                $created = 0;
                $pool = new Pool(new Connections(new Stack(), 'test', $a['size'], function () use (&$created) {
                    $created++;

                    return new Curl();
                }, 0.0));
                $results = [];
                foreach ($a['requests'] as $request) {
                    if ($a['stream'] ?? false) {
                        $streamed = '';
                        $response = $pool->stream(self::request($request, $s), function (string $chunk) use (&$streamed) {
                            $streamed .= $chunk;
                        });
                        $results[] = self::result($response, $s) + ['streamed' => self::localize($streamed, $s)];
                    } else {
                        $results[] = self::result($pool->sendRequest(self::request($request, $s)), $s);
                    }
                }

                return ['responses' => $results, 'created' => $created];
            },
        ];
    }

    /**
     * Runs $send inside an active span when `span` is given (a traceparent to
     * continue, or '' for a new trace); its traceparent is random, so results
     * carry `${traceparent}` in its place.
     */
    private static function traced(array $a, callable $send): mixed
    {
        if (!\array_key_exists('span', $a)) {
            return $send();
        }
        Span::setStorage(new Memory());
        $span = Span::init('http.request', $a['span'] === '' ? null : $a['span']);
        try {
            return self::replace($send(), $span->getTraceparent());
        } finally {
            $span->finish();
            Span::setStorage(null);
        }
    }

    private static function replace(mixed $value, string $traceparent): mixed
    {
        if (\is_string($value)) {
            return str_replace($traceparent, '${traceparent}', $value);
        }
        if (\is_array($value)) {
            return array_map(fn ($v) => self::replace($v, $traceparent), $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function uri(Uri $uri): array
    {
        return [
            'scheme' => $uri->getScheme(),
            'userInfo' => $uri->getUserInfo(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
            'query' => $uri->getQuery(),
            'fragment' => $uri->getFragment(),
            'authority' => $uri->getAuthority(),
            'string' => (string) $uri,
        ];
    }

    private static function client(array $a, Session $s): array
    {
        $sleeps = new \stdClass();
        $sleeps->list = [];
        $calls = null;
        $adapter = new Curl();
        if ($a['legacy'] ?? false) {
            $calls = new \stdClass();
            $calls->count = 0;
            $adapter = new Legacy($adapter, $calls);
        }
        if (isset($a['retry'])) {
            $adapter = new Retry($adapter, self::backoff($a['retry']), function (float $seconds) use ($sleeps) {
                $sleeps->list[] = $seconds;
            });
        }
        $client = isset($a['destinations']) ? new Client($adapter, self::destinations($a['destinations'], $s)) : new Client($adapter);
        foreach ($a['with'] ?? [] as [$setting, $value]) {
            $client = match ($setting) {
                'destinations' => $client->withDestinations(self::destinations($value, $s)),
                'timeout' => $client->withTimeout($value),
                'connect_timeout' => $client->withConnectTimeout($value),
                'ssl_verification' => $client->withSslVerification($value),
                'custom_ca' => $client->withCustomCA($value),
                'certificate' => $client->withCertificate(...$value),
                'min_tls' => $client->withMinTlsVersion(\constant(Tls::class . '::' . $value)),
                'connection_reuse' => $client->withConnectionReuse($value),
                'follow_redirects' => $client->withFollowRedirects(...$value),
                'headers' => $client->withHeaders($value),
                'base_uri' => $client->withBaseUri(self::globalize($value, $s)),
                'basic_auth' => $client->withBasicAuth(...$value),
                'bearer_auth' => $client->withBearerAuth($value),
                'trace' => $client->withTracePropagation($value),
                default => throw new Fault("unknown setting {$setting}"),
            };
        }

        return [$client, $sleeps, $calls];
    }

    private static function destinations(array $d, Session $s): Destinations
    {
        return match ($d['type']) {
            'anywhere' => new Anywhere(),
            'public' => new PublicInternet(...array_map(fn ($range) => new IPRange(self::globalize($range, $s)), $d['also'] ?? [])),
            default => throw new Fault('unknown destinations ' . json_encode($d)),
        };
    }

    private static function backoff(array $b): Backoff
    {
        $random = $b['random'] ?? 0.5;

        return new Backoff($b['max_attempts'] ?? 3, $b['base_delay'] ?? 0.1, $b['max_delay'] ?? 10.0, $b['multiplier'] ?? 2.0, fn () => $random);
    }

    private static function exception(string $class, RequestInterface $request): \Throwable
    {
        $class = 'Utopia\\Client\\Exception\\' . $class;
        if (!class_exists($class)) {
            throw new Fault("unknown exception {$class}");
        }

        return new $class($request, 'failure');
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function request(array $r, Session $s): RequestInterface
    {
        $uri = isset($r['raw']) ? 'http://${http}/raw?r=' . rawurlencode(base64_encode($r['raw'])) : $r['uri'];
        $request = new Request($r['method'], Uri::parse(self::globalize($uri, $s)));
        foreach ($r['headers'] ?? [] as [$name, $value]) {
            $request = $request->withAddedHeader($name, $value);
        }
        if (isset($r['file'])) {
            $request = $request->withBody((new Stream\Factory())->createStreamFromFile($r['file']));
        } elseif (isset($r['body'])) {
            $body = \is_array($r['body']) ? str_repeat($r['body']['repeat'], $r['body']['times']) : $r['body'];
            $request = $request->withBody((new Stream\Factory())->createStream($body));
        }

        return $request;
    }

    private static function response(array $r): ResponseInterface
    {
        $response = new Response($r['status']);
        foreach ($r['headers'] ?? [] as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     *
     * @return array<string, list<string>>
     */
    private static function grouped(array $pairs): array
    {
        $headers = [];
        foreach ($pairs as [$name, $value]) {
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * @param array<array-key, list<string>> $headers
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $out[] = [(string) $name, $values];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(ResponseInterface $response, Session $s): array
    {
        return [
            'protocol' => $response->getProtocolVersion(),
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'headers' => array_map(fn ($pair) => [$pair[0], array_map(fn ($v) => self::localize($v, $s), $pair[1])], self::headers($response->getHeaders())),
            'body' => self::localize((string) $response->getBody(), $s),
        ];
    }

    private static function globalize(string $value, Session $s): string
    {
        return str_replace(
            ['${http}', '${https}', '${other-tls}', '${other}', '${range}'],
            [$s->service('client-http'), $s->service('client-https'), $s->service('client-other-tls'), $s->service('client-other'), $s->service('client-range')],
            $value,
        );
    }

    private static function localize(string $value, Session $s): string
    {
        return str_replace([$s->service('client-http'), $s->service('client-https'), $s->service('client-other')], ['${http}', '${https}', '${other}'], $value);
    }
}

/**
 * An adapter without response streaming (not a ResponseStreamingClientInterface)
 * that counts the requests it sends: a client written before the interface.
 */
final class Legacy extends \Utopia\Client\Decorator
{
    public function __construct(\Utopia\Client\Adapter $adapter, private readonly \stdClass $calls)
    {
        parent::__construct($adapter);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls->count++;

        return parent::sendRequest($request);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->calls->count++;

        return parent::stream($request, $sink);
    }
}
