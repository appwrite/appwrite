# Utopia Domains

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/domains`](https://github.com/utopia-php/monorepo/tree/main/packages/domains) — please open issues and pull requests there.

![Total downloads](https://img.shields.io/packagist/dt/utopia-php/domains.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Domains parses domain names using the [Public Suffix List](https://publicsuffix.org/). It can identify a domain's suffix, registerable name, and subdomain. Registrar adapters provide domain registration operations through OpenSRS and Name.com.

## Installation

Install the package with Composer:

```bash
composer require utopia-php/domains
```

Utopia Domains requires PHP 8.5 or later with the cURL, mbstring, and SimpleXML extensions.

## Domain parsing

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Domains\Domain;

$domain = new Domain('demo.example.co.uk');

$domain->get();             // demo.example.co.uk
$domain->getTLD();          // uk
$domain->getSuffix();       // co.uk
$domain->getRegisterable(); // example.co.uk
$domain->getName();         // example
$domain->getSub();          // demo
$domain->isKnown();         // true
$domain->isICANN();         // true
$domain->isPrivate();       // false
$domain->isTest();          // false
```

For a URL, extract its host before creating the domain:

```php
$host = parse_url('https://www.example.com/path', PHP_URL_HOST);
$domain = new Domain($host);
```

The parser exposes these methods:

- `get()` returns the complete domain name.
- `getTLD()` returns the top-level domain.
- `getSuffix()` returns the matching public suffix.
- `getRegisterable()` returns the public suffix plus its preceding label.
- `getName()` returns the registerable domain name without its suffix.
- `getSub()` returns the subdomain.
- `isKnown()` reports whether the suffix exists in the dataset.
- `isICANN()` reports whether the suffix belongs to the ICANN section.
- `isPrivate()` reports whether the suffix belongs to the private section.
- `isTest()` reports whether the top-level domain is `localhost` or `test`.

The generated dataset lives in `data/data.php`. Maintainers can refresh it with:

```bash
php data/import.php
```

## Registrar adapters

Create an adapter for the registrar and pass it to `Registrar`. Default nameservers belong to `Registrar`, not the adapter.

Both adapters default to `utopia-php/client` with cURL. Either accepts `client: $client` for any PSR-18 transport, including `Utopia\Client\Pool`. Injected clients own their configuration; registrar timeout setters only affect the default transport. Keep TLS verification enabled and redirects disabled, and avoid automatic retries for purchases or transfers.

### OpenSRS adapter

```php
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\OpenSRS;

$adapter = new OpenSRS(
    'api-key',
    'username',
    'password',
    'https://horizon.opensrs.net:55443',
);

// Default transport timeouts in seconds; OpenSRS now honors these settings.
$adapter->setConnectTimeout(5);
$adapter->setTimeout(10);

$registrar = new Registrar($adapter, [
    'ns1.nameserver.com',
    'ns2.nameserver.com',
]);
```

Use `https://rr-n1-tor.opensrs.net:55443` as the OpenSRS production endpoint.

### Name.com

```php
use Utopia\Client\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\NameCom;

// Optional: configure an injected transport instead of the registrar's defaults.
$client = new Client(new CurlAdapter())
    ->withConnectTimeout(5)
    ->withTimeout(10)
    ->withSslVerification(true)
    ->withFollowRedirects(false);

$adapter = new NameCom(
    'username',
    'api-token',
    'https://api.name.com',
    client: $client,
);

$registrar = new Registrar($adapter, [
    'ns1.name.com',
    'ns2.name.com',
]);
```

Name.com's sandbox endpoint is `https://api.dev.name.com`.

### Registrar operations

```php
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\UpdateDetails;

$contact = new Contact(
    'First',
    'Last',
    '+1.5555555555',
    'person@example.com',
    '123 Example Street',
    '',
    '',
    'Example City',
    'CA',
    'US',
    '12345',
    'Example Inc.',
);

$availability = $registrar->available(['example.com', 'example.net']);
$orderId = $registrar->purchase('example.com', $contact, 1);
$suggestions = $registrar->suggest(['example'], ['com', 'net'], 10);
$details = $registrar->getDomain('example.com');
$renewal = $registrar->renew('example.com', 1);
$transferOrderId = $registrar->transfer('example.com', 'auth-code');
$registrar->updateDomain('example.com', new UpdateDetails(autoRenew: true));
```

The registrar API also provides `tlds()`, `updateNameservers()`, `getPrice()`, `getAuthCode()`, `cancelPurchase()`, and `checkTransferStatus()`.

### Price caching

Pass a `Cache` wrapping any `utopia-php/cache` adapter to `Registrar` to cache prices per domain and period. The `ttl` argument of `getPrice()` controls how long a cached price is reused.

```php
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Domains\Cache;

$cache = new Cache(new UtopiaCache(new Redis($redis)));
$registrar = new Registrar($adapter, ['ns1.name.com', 'ns2.name.com'], $cache);

$availability = $registrar->available($domains);
$price = $registrar->getPrice($domains[0], ttl: 86400);
```

With Name.com, `available()` also caches the registration and renewal prices it receives, so pricing a batch of domains after checking their availability costs one registrar request per 50 domains instead of two per domain.

## Testing

```sh
composer test       # unit tests
composer test:e2e   # registrar tests; requires the registrar credentials below
```

The Name.com tests require `NAMECOM_USERNAME` and `NAMECOM_TOKEN`. The OpenSRS
tests require `OPENSRS_USERNAME` and `OPENSRS_KEY`. The end-to-end suite skips
an adapter when its credentials are unavailable.

## License

Utopia Domains is available under the [MIT License](LICENSE.md).
