# Utopia DNS

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/dns`](https://github.com/utopia-php/monorepo/tree/main/packages/dns) — please open issues and pull requests there.

[![Tests](https://github.com/utopia-php/dns/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/dns/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/utopia-php/dns.svg)](https://packagist.org/packages/utopia-php/dns)
![Packagist Downloads](https://img.shields.io/packagist/dt/utopia-php/dns.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia DNS is a modern PHP 8.3 toolkit for building DNS servers and clients. It provides a fully-typed DNS message encoder/decoder, pluggable resolvers, and telemetry hooks so you can stand up custom authoritative or proxy DNS services with minimal effort.

Although part of the [Utopia Framework](https://github.com/utopia-php/framework) family, the library is framework-agnostic and can be used in any PHP project.

## Installation

```bash
composer require utopia-php/dns
```

The library requires PHP 8.5+ with the `ext-sockets` extension. The Swoole adapter additionally needs the `ext-swoole` extension.

## Quick start

Create an authoritative DNS server by wiring an adapter (which transports to listen on) and a resolver (how records are answered). The example below uses the native PHP socket adapter with UDP and TCP transports and the in-memory zone resolver.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Utopia\DNS\Adapter\Native;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Server;
use Utopia\DNS\Zone;

$adapter = new Native([
    new Native\Udp('0.0.0.0', 5300),
    new Native\Tcp('0.0.0.0', 5300),
]);

$zone = new Zone(
    name: 'example.test',
    records: [
        new Record(name: 'example.test', type: Record::TYPE_A, rdata: '192.0.2.1', ttl: 60),
        new Record(name: 'www.example.test', type: Record::TYPE_CNAME, rdata: 'example.test', ttl: 60),
        new Record(
            name: 'example.test',
            type: Record::TYPE_SOA,
            rdata: 'ns1.example.test hostmaster.example.test 1 7200 1800 1209600 3600',
            ttl: 60
        ),
    ]
);

$server = new Server($adapter, new Memory($zone));
$server->setDebug(true);

$server->start();
```

The server listens on UDP and TCP port `5300` (RFC 5966) and answers queries for `example.test` from the in-memory zone. Implement the [`Utopia\DNS\Resolver`](src/DNS/Resolver.php) interface to serve records from databases, APIs, or other stores.

## Resolvers
- `Memory`: authoritative resolver backed by a `Zone` object
- `Proxy`: forwards queries to another DNS server
- `Cloudflare`: proxy resolver preconfigured for `1.1.1.1` / `1.0.0.1`
- `Google`: proxy resolver preconfigured for `8.8.8.8` / `8.8.4.4`

Resolvers can be combined with any adapter. Implementing the `Resolver` interface allows you to plug in custom logic while reusing the message encoding/decoding and telemetry tooling.

Resolvers receive a `Query`, which carries the decoded `Message` plus its source: the client IP, port, and transport protocol. Cross-cutting concerns such as tracing or rate limiting compose as resolver decorators:

```php
final readonly class RateLimited implements Resolver
{
    public function __construct(private Resolver $inner) {}

    public function resolve(Query $query): Message
    {
        if ($this->isFlooding($query->ip)) {
            return Message::response($query->message->header, Message::RCODE_REFUSED, authoritative: false);
        }

        return $this->inner->resolve($query);
    }
}
```

Note that UDP source addresses can be forged; stream transports (`Protocol::Tcp`, `Protocol::Https`) carry verified peer addresses.

## Adapters and transports

An adapter composes one or more transports into a single process. Transports are responsible only for receiving and returning raw packets — they call back into the server with the payload, source IP, and port so your resolver logic stays isolated.

- `Native`: blocking select loop based on `ext-sockets`, with `Native\Udp` and `Native\Tcp` transports
- `Swoole`: non-blocking server built on the Swoole runtime, with `Swoole\Udp`, `Swoole\Tcp`, and `Swoole\Http` transports

Transports of one adapter share the process and (for Swoole) the worker pool. UDP and TCP transports can bind the same port number; each HTTP transport needs its own TCP port:

```php
use Utopia\DNS\Adapter\Swoole;

$adapter = new Swoole(
    transports: [
        new Swoole\Udp('0.0.0.0', 53),
        new Swoole\Tcp('0.0.0.0', 53),
        new Swoole\Http('0.0.0.0', 443, certPath: '/etc/ssl/dns.crt', keyPath: '/etc/ssl/dns.key'),
    ],
    workers: 4,
);
```

`Swoole\Http` implements DNS over HTTPS (RFC 8484): it accepts wire-format queries via `GET` (`?dns=` base64url) and `POST` (`application/dns-message`). Leave `certPath` unset to serve plain HTTP behind a TLS-terminating proxy.

### Client addresses behind load balancers

Load balancers usually replace the client address with their own. The TCP transports accept a PROXY protocol v1/v2 header (as sent by DigitalOcean, AWS, or HAProxy load balancers) when constructed with `proxyProtocol: true`, and report the original client address to your resolver:

```php
new Swoole\Tcp('0.0.0.0', 53, proxyProtocol: true);
new Native\Tcp('0.0.0.0', 53, proxyProtocol: true);
```

Only enable this behind a load balancer that sends the header — with it enabled, direct DNS clients are rejected. The HTTP transport uses `new Swoole\Http('0.0.0.0', 443, trustProxy: true)` instead, which reads the `X-Forwarded-For` header. PROXY protocol is a TCP feature: UDP transports always see the packet source address, so preserve it at the network layer (for example `externalTrafficPolicy: Local` on Kubernetes).

## DNS client

The bundled client can query any DNS server and returns fully decoded messages.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Utopia\DNS\Client;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

$client = new Client('1.1.1.1');

$query = Message::query(
    new Question('example.com', Record::TYPE_A)
);

$response = $client->query($query);

foreach ($response->answers as $answer) {
    echo "{$answer->name} {$answer->ttl} {$answer->getTypeName()} {$answer->rdata}\n";
}
```

## Telemetry

`Server::setTelemetry()` accepts any adapter from [`utopia-php/telemetry`](https://github.com/utopia-php/telemetry). Counters (`dns.queries.total`, `dns.responses.total`) and a histogram (`dns.query.duration`) are emitted automatically, enabling Prometheus or OpenTelemetry pipelines with minimal configuration.

## Development
- Install dependencies: `composer install`
- Static analysis: `composer check`
- Coding standards: `composer lint` (use `composer format` to auto-fix)
- Tests: `composer test`
- Sample Swoole server for manual testing: `docker compose up`

## Benchmarking

Run `composer bench` to start a sample Swoole server and measure UDP, TCP, and DNS over HTTPS throughput against it with [dnspyre](https://github.com/Tantalor93/dnspyre) (fetched automatically when not installed; tune with the `PORT`, `QUERIES`, `CONCURRENCY`, and `DOMAINS` environment variables). To benchmark an already-running server directly:

```bash
dnspyre -n 250 -c 20 --server 127.0.0.1:5300 dev.appwrite.io
```

The report covers requests per second, success counts, and latency percentiles per transport.

## Upgrading from 1.x

Version 2.0 replaces per-adapter protocol flags with composable transports:

- `new Native($host, $port)` → `new Native([new Native\Udp($host, $port), new Native\Tcp($host, $port)])`
- `new Swoole($host, $port, $numWorkers)` → `new Swoole([new Swoole\Udp($host, $port), new Swoole\Tcp($host, $port)], workers: $numWorkers)`
- `enableTcp: false` → omit the `Tcp` transport
- TCP tuning options (`maxTcpClients`, `maxTcpBufferSize`, `maxTcpFrameSize`, `tcpIdleTimeout`) moved to the `Native\Tcp` constructor as `maxClients`, `maxBufferSize`, `maxFrameSize`, and `idleTimeout`
- `Resolver::resolve()` now receives a `Utopia\DNS\Query` carrying the decoded message and its source (`$query->message`, `$query->ip`, `$query->port`, `$query->protocol`) instead of a bare `Message`
- `Adapter::getName()` and `Resolver::getName()` were removed without replacement
- The server no longer emits `utopia-php/span` traces (`dns.packet`); metrics via `Server::setTelemetry()` are unchanged. Emit spans from your `Resolver` implementation if you need tracing

## License

MIT
