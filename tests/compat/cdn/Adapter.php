<?php

namespace Tests\Compat\Cdn;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer as OptionBalancer;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter as CacheAdapter;
use Utopia\Cdn\Cache\Adapter\Balancer;
use Utopia\Cdn\Cache\Adapter\Cloudflare as CloudflareCache;
use Utopia\Cdn\Cache\Adapter\Fastly as FastlyCache;
use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Challenge;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Provider\Cloudflare as CloudflareCertificates;
use Utopia\Cdn\Certificates\Provider\Fastly as FastlyCertificates;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Provider\Proxy;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Cdn\Exception\Purge;
use Utopia\Cdn\Extend\CdnOption;

require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/Doubles.php';

/**
 * Maps tests/compat/cdn/spec.json operations onto utopia-php/cdn. Glue only: no logic.
 *
 * Providers send through a recorded Transport (`"transport": "recorded"`,
 * the default) or the library's own client against the `cdn-http` fixture
 * server (`"transport": "http"`); no CDN is ever called. Every call reports
 * its result or error and the requests the provider sent.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'validate' => ['Normalise and validate a mixed-case hostname', fn (Session $s) => fn () => Domain::validate('Shop.Example.COM')],
            'validate_paths' => ['Check ten cache paths', function (Session $s) {
                $paths = array_map(static fn (int $i): string => '/assets/app-' . $i . '.js', range(1, 10));

                return fn () => Domain::validatePaths($paths);
            }],
            'fastly_purge_paths' => ['Purge three paths through Fastly on a transport answering at once', function (Session $s) {
                $transport = new BenchTransport();
                $fastly = new FastlyCache('token', 'domain-', 'service', false, $transport);

                return fn () => $fastly->purgePaths('example.com', ['/index.html', '/hello world.png', '/a?b=c']);
            }],
            'cloudflare_purge_keys' => ['Purge 40 cache tags through Cloudflare (two batches) on a transport answering at once', function (Session $s) {
                $transport = new BenchTransport();
                $cloudflare = new CloudflareCache('zone', 'token', $transport);
                $keys = array_map(static fn (int $i): string => 'tag-' . $i, range(1, 40));

                return fn () => $cloudflare->purgeKeys($keys);
            }],
            'fastly_tls_status' => ['Read an issued, activated Fastly TLS subscription', function (Session $s) {
                $transport = new BenchTransport([
                    '{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_certificates":{"data":[{"id":"cert_1","type":"tls_certificate"}]},"tls_domains":{"data":[{"id":"example.com","type":"tls_domain"}]}}}],"included":[{"id":"cert_1","type":"tls_certificate","attributes":{"not_after":"2027-02-01T00:00:00Z"}}]}',
                    '{"data":[{"id":"act_1","type":"tls_activation","relationships":{"tls_certificate":{"data":{"type":"tls_certificate","id":"cert_1"}}}}]}',
                ]);
                $tls = new FastlyTls('token', 'config', 'certainly', $transport);

                return fn () => $tls->getCertificateStatus('example.com', null);
            }],
            'balancer_purge_domain' => ['Fan a domain purge out over three recording adapters, one filtered away', function (Session $s) {
                $balancer = new OptionBalancer(new First());
                $balancer
                    ->addOption(new CdnOption(new NullAdapter(), CdnOption::PROVIDER_FASTLY, true))
                    ->addOption(new CdnOption(new NullAdapter(), CdnOption::PROVIDER_FASTLY))
                    ->addOption(new CdnOption(new NullAdapter(), CdnOption::PROVIDER_CLOUDFLARE))
                    ->addFilter(fn (CdnOption $option): bool => !$option->isEdge());
                $cache = new Balancer($balancer);

                return fn () => $cache->purgeDomain('Example.com');
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'domain.validate' => fn (array $a, Session $s) => Domain::validate($a['domain']),
            'domain.validate_paths' => fn (array $a, Session $s) => Domain::validatePaths($a['paths']),
            'log.new' => fn (array $a, Session $s) => $s->handle(new \ArrayObject()),
            'log.take' => function (array $a, Session $s) {
                $log = self::log($a['log'], $s);
                $entries = $log->getArrayCopy();
                $log->exchangeArray([]);

                return $entries;
            },
            'cache.new' => fn (array $a, Session $s) => $s->handle(self::cache($a, $s)),
            'cache.call' => fn (array $a, Session $s) => self::callCache($s->get($a['cache']), $a),
            'cache.once' => fn (array $a, Session $s) => self::callCache(self::cache($a, $s), $a),
            'option.new' => fn (array $a, Session $s) => $s->handle(new CdnOption(
                self::cacheHandle($a['adapter'], $s)['cache'],
                $a['provider'],
                ...(\array_key_exists('edge', $a) ? [$a['edge']] : []),
            )),
            'option.read' => function (array $a, Session $s) {
                $option = self::option($a['option'], $s);

                return [
                    'provider' => $option->getProvider(),
                    'edge' => $option->isEdge(),
                    'adapter' => $option->getAdapter() === self::cacheHandle($a['adapter'], $s)['cache'],
                ];
            },
            'option.filter' => function (array $a, Session $s) {
                $options = array_map(fn ($h) => self::option($h, $s), $a['options']);
                $balancer = self::balancer($options, $a['filters'] ?? []);
                $index = static fn (mixed $option): ?int => ($i = array_search($option, $options, true)) === false ? null : $i;

                return [
                    'filtered' => array_map($index, $balancer->getFilteredOptions()),
                    'run' => $index($balancer->run()),
                ];
            },
            'certificates.new' => fn (array $a, Session $s) => $s->handle(self::provider($a, $s)),
            'certificates.call' => fn (array $a, Session $s) => self::callProvider($s->get($a['provider']), $a),
            'certificates.once' => fn (array $a, Session $s) => self::callProvider(self::provider($a, $s), $a),
            'challenge.new' => fn (array $a, Session $s) => self::challenge(new Challenge($a['type'], $a['record_type'], $a['record_name'], $a['values'])),
            'exception.certificate' => fn (array $a, Session $s) => self::error(new Certificate(
                $a['message'],
                $a['status'],
                array_map(static fn (array $c) => new Challenge($c['type'], $c['record_type'], $c['record_name'], $c['values']), $a['challenges'] ?? []),
                $a['warnings'] ?? [],
            )),
            'exception.purge' => fn (array $a, Session $s) => self::error(new Purge(
                $a['message'],
                array_map(static fn (string $m) => new \RuntimeException($m), $a['errors'] ?? []),
            )),
            'constants' => fn (array $a, Session $s) => [
                'cloudflare' => ['PATHS_PER_PURGE' => CloudflareCache::PATHS_PER_PURGE, 'KEYS_PER_PURGE' => CloudflareCache::KEYS_PER_PURGE],
                'fastly' => ['KEYS_PER_PURGE' => FastlyCache::KEYS_PER_PURGE],
                'option' => [
                    'ADAPTER' => CdnOption::ADAPTER,
                    'PROVIDER' => CdnOption::PROVIDER,
                    'EDGE' => CdnOption::EDGE,
                    'PROVIDER_FASTLY' => CdnOption::PROVIDER_FASTLY,
                    'PROVIDER_CLOUDFLARE' => CdnOption::PROVIDER_CLOUDFLARE,
                ],
                'status' => [Status::PENDING, Status::PROCESSING, Status::ISSUED, Status::RENEWING, Status::FAILED, Status::BLOCKED, Status::UNKNOWN],
                'fastly_tls' => ['CHALLENGE_MANAGED_DNS' => FastlyTls::CHALLENGE_MANAGED_DNS],
            ],
        ];
    }

    /** @return \ArrayObject<int, mixed> */
    private static function log(mixed $handle, Session $s): \ArrayObject
    {
        $log = $s->get($handle);

        return $log instanceof \ArrayObject ? $log : throw new Fault('not a log');
    }

    private static function option(mixed $handle, Session $s): CdnOption
    {
        $option = $s->get($handle);

        return $option instanceof CdnOption ? $option : throw new Fault('not an option');
    }

    /** @return array{cache: CacheAdapter|Cache, transport: Transport|Fixture|null} */
    private static function cacheHandle(mixed $handle, Session $s): array
    {
        $cache = $s->get($handle);

        return \is_array($cache) && isset($cache['cache']) ? $cache : throw new Fault('not a cache');
    }

    /**
     * @param list<CdnOption> $options
     * @param list<array<string, mixed>> $filters
     */
    private static function balancer(array $options, array $filters): OptionBalancer
    {
        $balancer = new OptionBalancer(new First());
        foreach ($options as $option) {
            $balancer->addOption($option);
        }
        foreach ($filters as $filter) {
            $balancer->addFilter(match (true) {
                \array_key_exists('edge', $filter) => fn (CdnOption $o): bool => $o->isEdge() === $filter['edge'],
                \array_key_exists('provider', $filter) => fn (CdnOption $o): bool => $o->getProvider() === $filter['provider'],
                default => throw new Fault('a filter needs `edge` or `provider`'),
            });
        }

        return $balancer;
    }

    /** @return array{0: Transport|Fixture, 1: ?Transport, 2: ?string} transport, client, API base */
    private static function transport(array $a, Session $s, string $base): array
    {
        return match ($a['transport'] ?? 'recorded') {
            'recorded' => [$t = new Transport(), $t, $a['api_base'] ?? $base],
            'http' => [$f = new Fixture($s->service('cdn-http'), $s->ns), null, $f->base()],
            default => throw new Fault('unknown transport ' . json_encode($a['transport'])),
        };
    }

    /** @return array{cache: CacheAdapter|Cache, transport: Transport|Fixture|null} */
    private static function cache(array $a, Session $s): array
    {
        switch ($a['type']) {
            case 'cloudflare':
                [$transport, $client, $base] = self::transport($a, $s, 'https://api.cloudflare.com/client/v4');

                return ['cache' => new CloudflareCache($a['zone'], $a['token'], $client, $base), 'transport' => $transport];
            case 'fastly':
                [$transport, $client, $base] = self::transport($a, $s, 'https://api.fastly.com');

                return ['cache' => new FastlyCache($a['token'], $a['prefix'], $a['service'] ?? null, $a['soft'] ?? false, $client, $base), 'transport' => $transport];
            case 'recorder':
                return ['cache' => new CacheRecorder($a['name'], self::log($a['log'], $s), $a['supports_keys'] ?? true, $a['fails'] ?? false), 'transport' => null];
            case 'balancer':
                $options = array_map(fn ($h) => self::option($h, $s), $a['options']);

                return ['cache' => new Balancer(self::balancer($options, $a['filters'] ?? [])), 'transport' => null];
            case 'facade':
                $inner = self::cacheHandle($a['adapter'], $s);

                return ['cache' => new Cache($inner['cache']), 'transport' => $inner['transport']];
            default:
                throw new Fault('unknown cache type ' . json_encode($a['type']));
        }
    }

    /** @return array{provider: Provider|Certificates, transport: Transport|Fixture|null} */
    private static function provider(array $a, Session $s): array
    {
        $handle = static function (mixed $h) use ($s): Provider {
            $provider = $s->get($h);
            $provider = \is_array($provider) ? ($provider['provider'] ?? null) : null;

            return $provider instanceof Provider ? $provider : throw new Fault('not a provider');
        };
        switch ($a['type']) {
            case 'cloudflare':
                [$transport, $client, $base] = self::transport($a, $s, 'https://api.cloudflare.com/client/v4');

                return ['provider' => new CloudflareCertificates($a['zone'], $a['token'], $client, $base), 'transport' => $transport];
            case 'fastly':
                [$transport, $client, $base] = self::transport($a, $s, 'https://api.fastly.com');

                return ['provider' => new FastlyCertificates(
                    $a['token'],
                    $a['service'],
                    $a['ca'] ?? 'certainly',
                    $client,
                    $base,
                    $a['poll_attempts'] ?? 10,
                    $a['poll_interval'] ?? 5000,
                    $a['tls_config'] ?? '',
                ), 'transport' => $transport];
            case 'fastly_tls':
                [$transport, $client, $base] = self::transport($a, $s, 'https://api.fastly.com');

                return ['provider' => new FastlyTls($a['token'], $a['tls_config'], $a['ca'] ?? 'certainly', $client, $base), 'transport' => $transport];
            case 'recorder':
                return ['provider' => new ProviderRecorder(
                    $a['name'],
                    self::log($a['log'], $s),
                    $a['status'] ?? Status::ISSUED,
                    $a['instant'] ?? false,
                    $a['date'] ?? null,
                    $a['renew'] ?? false,
                ), 'transport' => null];
            case 'proxy':
                return ['provider' => new Proxy($a['app_domain'], $handle($a['app']), $handle($a['network']), array_map($handle, $a['custom'] ?? [])), 'transport' => null];
            case 'facade':
                $inner = $s->get($a['provider']);

                return ['provider' => new Certificates($handle($a['provider'])), 'transport' => \is_array($inner) ? ($inner['transport'] ?? null) : null];
            default:
                throw new Fault('unknown provider type ' . json_encode($a['type']));
        }
    }

    private static function callCache(mixed $handle, array $a): array
    {
        $cache = \is_array($handle) ? ($handle['cache'] ?? null) : null;
        if (!$cache instanceof CacheAdapter && !$cache instanceof Cache) {
            throw new Fault('not a cache');
        }

        return self::call($handle['transport'], $a, fn () => match ($a['method']) {
            'purgePaths' => $cache->purgePaths($a['domain'], $a['paths']),
            'purgeDomain' => $cache->purgeDomain($a['domain']),
            'purgeKeys' => $cache->purgeKeys($a['keys']),
            'purgeZone' => $cache->purgeZone(),
            default => throw new Fault('unknown cache method ' . $a['method']),
        });
    }

    private static function callProvider(mixed $handle, array $a): array
    {
        $provider = \is_array($handle) ? ($handle['provider'] ?? null) : null;
        if (!$provider instanceof Provider && !$provider instanceof Certificates) {
            throw new Fault('not a provider');
        }
        $type = $a['domain_type'] ?? null;

        return self::call($handle['transport'], $a, fn () => match ($a['method']) {
            'issueCertificate' => $provider->issueCertificate($a['cert_name'] ?? 'cert', $a['domain'], $type),
            'isInstantGeneration' => $provider->isInstantGeneration($a['domain'], $type),
            'getCertificateStatus' => $provider->getCertificateStatus($a['domain'], $type),
            'isRenewRequired' => $provider->isRenewRequired($a['domain'], $type),
            'deleteCertificate' => $provider->deleteCertificate($a['domain'], $type),
            default => throw new Fault('unknown provider method ' . $a['method']),
        });
    }

    /**
     * One call: its result or error, and the requests the provider sent.
     *
     * @return array<string, mixed>
     */
    private static function call(Transport|Fixture|null $transport, array $a, callable $invoke): array
    {
        $transport?->queue($a['responses'] ?? []);
        try {
            $out = ['result' => $invoke()];
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $error) {
            $out = ['error' => self::error($error)];
        }
        if ($transport !== null) {
            $out['requests'] = $transport->take();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function error(\Throwable $error): array
    {
        $out = ['class' => $error::class, 'message' => $error->getMessage()];
        if ($error instanceof Purge) {
            $out['errors'] = array_map(self::error(...), $error->getErrors());
            $out['previous'] = $error->getPrevious() === null ? null : self::error($error->getPrevious());
        }
        if ($error instanceof Certificate) {
            $out['status'] = $error->getStatus();
            $out['blocked'] = $error->isBlocked();
            $out['challenges'] = array_map(self::challenge(...), $error->getChallenges());
            $out['warnings'] = $error->getWarnings();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function challenge(Challenge $challenge): array
    {
        return [
            'type' => $challenge->type,
            'record_type' => $challenge->recordType,
            'record_name' => $challenge->recordName,
            'values' => $challenge->values,
        ];
    }
}

/** A transport for the benchmarks: answers every request at once, without recording it. */
final class BenchTransport implements \Psr\Http\Client\ClientInterface
{
    private int $next = 0;

    /** @param list<string> $bodies Answered in turn (`{"success":true}` when none). */
    public function __construct(private readonly array $bodies = [])
    {
    }

    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $body = $this->bodies === [] ? '{"success":true}' : $this->bodies[$this->next++ % \count($this->bodies)];

        return new \Utopia\Psr7\Response(200, body: new \Utopia\Psr7\Stream($body));
    }
}

/** A cache adapter for the benchmarks that does nothing. */
final class NullAdapter implements CacheAdapter
{
    public function purgePaths(string $domain, array $paths): void
    {
    }

    public function purgeDomain(string $domain): void
    {
    }

    public function purgeKeys(array $keys): void
    {
    }

    public function purgeZone(): void
    {
    }
}
