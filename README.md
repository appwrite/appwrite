# Utopia CDN

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/cdn`](https://github.com/utopia-php/monorepo/tree/main/packages/cdn) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cdn.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia CDN is a lightweight PHP library for interacting with CDN providers. It currently focuses on two workflows: purging cached URLs and managing CDN-backed certificates. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it can be used as a standalone package with any PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/cdn
```

## Cache purging

The cache API supports four purge modes:

- path purges scoped to a domain
- domain-wide purges
- key or cache-tag purges for providers like Fastly and Cloudflare
- zone-wide purges, for when the whole cache has to go

Domains are lowercase hostnames without a scheme or trailing slash. Paths begin with `/`; CDN resources are assumed to use HTTPS.

### Cloudflare

```php
<?php

use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Cloudflare;

$cache = new Cache(new Cloudflare(
    zoneId: 'YOUR_ZONE_ID',
    apiToken: 'YOUR_API_TOKEN'
));

$cache->purgePaths('example.com', [
    '/files/hero.png',
    '/files/logo.svg',
]);

$cache->purgeDomain('example.com');

$cache->purgeKeys([
    'host-deadbeef',
    'deployment-12345',
]);
```

Cloudflare purges a hostname natively, so `purgeDomain()` evicts everything served for that hostname and nothing served for another. Every purge method is [available on all plans](https://developers.cloudflare.com/changelog/post/2025-04-01-purge-for-all/). `purgeKeys()` purges cache tags, which only match responses the origin tagged with a `Cache-Tag` header.

URLs and tags are batched at `PATHS_PER_PURGE` and `KEYS_PER_PURGE` per request, both 30. Cloudflare documents higher ceilings — 100 URLs per request, 500 on Enterprise, and 100 operations for tags — so 30 is a conservative default rather than a limit, and unchanged from what this adapter has always sent.

`purgeZone()` purges every cached response in the zone (`purge_everything`).

### Fastly

```php
<?php

use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Fastly;

$cache = new Cache(new Fastly(
    apiToken: 'YOUR_API_TOKEN',
    domainKeyPrefix: 'domain-',
    serviceId: 'YOUR_SERVICE_ID',
    softPurge: false
));

$cache->purgePaths('example.com', [
    '/files/hero.png',
    '/files/logo.svg',
]);

// Purges the surrogate key "domain-example.com".
$cache->purgeDomain('example.com');

$cache->purgeKeys([
    'host-deadbeef',
    'deployment-12345',
]);
```

`domainKeyPrefix` is required because [Fastly has no purge-by-host operation](https://www.fastly.com/documentation/reference/api/purging/) — its purge API offers URL, surrogate key and whole-service purges and nothing in between. A domain is addressed by the surrogate key the origin attaches to every response it serves for that domain, so the adapter has to know how those keys are named. Pass `''` when the key is the bare hostname.

Keys are sent as given, in the request body, batched up to 256 per request. A Fastly adapter with no service ID can still purge paths; key, domain and zone purges raise `Exception\UnsupportedOperation`.

`purgeZone()` purges everything on the service (`purge_all`), which Fastly documents as taking up to two minutes, being incompatible with soft purge, and likely to spike origin traffic on a busy service. Prefer `purgeDomain()` or `purgeKeys()`.

### Cache balancing

`Cache\Adapter\Balancer` turns provider selection into configuration. Every provider is declared once as an option, filters decide which options a purge applies to, and the purge then reaches **all** of them — content cached by two providers has to be evicted from both.

Options are `Extend\CdnOption`, a [utopia-php/balancer](https://github.com/utopia-php/balancer) `Option` with typed accessors, so a filter reads `$option->getProvider()` rather than guessing a state key. The balancer itself is the library's own, unwrapped:

```php
<?php

use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Balancer as BalancerAdapter;
use Utopia\Cdn\Extend\CdnOption;

// $fastlyEdge, $fastlyRun and $cloudflare are adapters built as shown above.
$balancer = (new Balancer(new First()))
    ->addOption(new CdnOption($fastlyEdge, CdnOption::PROVIDER_FASTLY, edge: true))
    ->addOption(new CdnOption($fastlyRun, CdnOption::PROVIDER_FASTLY))
    ->addOption(new CdnOption($cloudflare, CdnOption::PROVIDER_CLOUDFLARE));

// Custom domains are cached by the run service and by Cloudflare, so purge both.
$balancer->addFilter(fn (CdnOption $option): bool => !$option->isEdge());

$cache = new Cache(new BalancerAdapter($balancer));

// One call, two providers: a Fastly surrogate key purge and a Cloudflare cache-tag purge.
$cache->purgeKeys(['domain-customer.example.com']);
```

`isEdge()` marks options that front the platform's own edge network rather than customer-owned custom domains. Filters compose, so narrowing to a single option is just a matter of adding another:

```php
$balancer
    ->addFilter(fn (CdnOption $option): bool => $option->getProvider() === CdnOption::PROVIDER_FASTLY)
    ->addFilter(fn (CdnOption $option): bool => $option->isEdge());
```

Failures are aggregated rather than short-circuiting: every matching option is attempted, then the collected errors are raised together as `Exception\Purge`, whose `getErrors()` returns one error per failed provider. A provider outage therefore cannot stop the purge from reaching the others. When no option matches the filters, the purge raises `Exception\Configuration` instead of passing silently.

`purgeZone()` fans out like the rest, so one call drops every matching provider's whole cache — every domain it holds, not only the ones these options front. Filters still apply, which is the only thing keeping it from reaching the options they exclude.

Options stay ordinary balancer options, so `run()` still picks a single one through the `Algorithm` for callers that want exactly that.

### The adapter contract

`Cache\Adapter` declares the four purges every adapter offers — `purgePaths()`, `purgeDomain()`, `purgeKeys()` and `purgeZone()` — so a caller never has to know which provider is behind it. Providers differ in what they expose natively, and the adapter absorbs the difference: Cloudflare purges a hostname directly, while Fastly maps the same call onto a surrogate key. Where an adapter cannot serve an operation with the configuration it was given it raises `Exception\UnsupportedOperation`, rather than quietly doing something wider.

Adapters name things alike, with provider-specific numbers behind the same names: `KEYS_PER_PURGE` is how many cache keys one request may carry, 256 on Fastly and 30 on Cloudflare. Only Cloudflare declares `PATHS_PER_PURGE`, because a Fastly URL purge takes one URL and has nothing to batch.

## Certificates

The current certificate provider support is focused on CDN-managed certificates through Fastly TLS subscriptions.

```php
<?php

use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider\FastlyTls;

$certificates = new Certificates(new FastlyTls(
    apiToken: 'YOUR_API_TOKEN',
    tlsConfigurationId: 'YOUR_TLS_CONFIGURATION_ID'
));

$renewDate = $certificates->issueCertificate(
    certName: 'my-cert',
    domain: 'cdn.example.com',
    domainType: null
);

$status = $certificates->getCertificateStatus('cdn.example.com', null);
$renewRequired = $certificates->isRenewRequired('cdn.example.com', null);
```

`issueCertificate()` returns a renew date when Fastly already has an issued or renewing certificate. For asynchronous states like `pending` or `processing`, it returns `null`.

When Fastly domain management owns the domain lifecycle, use the managed provider instead. It creates domains without a service version on the configured service and removes both the domain and TLS subscription on deletion. Classic domains are removed by cloning and activating their service version first.

```php
use Utopia\Cdn\Certificates\Provider\Fastly;

$fastlyCertificates = new Fastly(
    apiToken: 'YOUR_API_TOKEN',
    serviceId: 'YOUR_SERVICE_ID',
);
```

### Cloudflare certificates

Cloudflare certificates use Cloudflare for SaaS custom hostnames, which must be enabled for the zone and plan.

```php
use Utopia\Cdn\Certificates\Provider\Cloudflare;

$cloudflareCertificates = new Cloudflare(
    zoneId: 'YOUR_ZONE_ID',
    apiToken: 'YOUR_API_TOKEN',
);
```

Cloudflare custom-hostname issuance is treated as instant. Certificate status retrieval is not supported by this provider.

### Certificate routing

`Certificates\Provider\Proxy` sends `site`, `network`, and `redirect` domains to the network provider, the application domain to its provider, and all other domains to every custom-domain provider.

```php
use Utopia\Cdn\Certificates\Provider\Proxy;

$certificates = new Certificates(new Proxy(
    appDomain: 'app.example.com',
    appDomainProvider: $appDomainCertificates,
    networkProvider: $fastlyCertificates,
    customDomainProviders: [$cloudflareCertificates, $fastlyCertificates],
));
```

## Supported providers

### Cache

- [x] [Cloudflare](https://www.cloudflare.com/)
- [x] [Fastly](https://www.fastly.com/)

### Certificates

- [x] [Fastly TLS subscriptions](https://www.fastly.com/documentation/guides/getting-started/domains/securing-domains-with-tls/)
- [x] [Cloudflare for SaaS custom hostnames](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/domain-support/create-custom-hostnames/)

## System requirements

Utopia CDN requires PHP 8.1 or later. We recommend using the latest PHP version whenever possible.

## Tests

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Run formatting checks:

```bash
composer lint
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
