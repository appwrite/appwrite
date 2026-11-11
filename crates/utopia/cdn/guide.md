# Getting started

`utopia-cdn` purges content cached by a CDN and manages the TLS certificates of custom domains, with one interface per job: a caller says what to purge or which domain to certify, never which vendor API to call. Every call is async and checks the domain first, so a provider only ever sees a lower-cased hostname.

## Check a domain before using it

Domains are folded to lower case, and anything that is not a bare hostname (a scheme, a port, a path) is refused.

```
use utopia_cdn::domain;

assert_eq!(domain::validate("Shop.Example.COM")?, "shop.example.com");
assert!(domain::validate("shop.example.com:443").is_err());
assert!(domain::validate_paths(&["/index.html"]).is_ok());
# Ok::<(), utopia_cdn::Error>(())
```

```php
use Utopia\Cdn\Domain;

echo Domain::validate('Shop.Example.COM'), "\n"; // shop.example.com
try {
    Domain::validate('shop.example.com:443');
} catch (\InvalidArgumentException $e) {
    echo "refused\n";
}
```

## Purge a page after it changes

Wrap a provider in `Cache` and purge the paths that changed. Cloudflare batches 30 URLs a request; Fastly purges each URL on its own.

```no_run
use utopia_cdn::cache::{Cache, Cloudflare};

# async fn run() -> Result<(), utopia_cdn::Error> {
let cache = Cache::new(Cloudflare::new("zone-id", "api-token"));
cache.purge_paths("example.com", &["/blog/".into(), "/blog/feed.xml".into()]).await?;
# Ok(())
# }
```

```php
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Cloudflare;

$cache = new Cache(new Cloudflare('zone-id', 'api-token'));
// $cache->purgePaths('example.com', ['/blog/', '/blog/feed.xml']);
```

## Purge a domain on Fastly

Fastly has no host purge, so a domain is purged through the surrogate key its origin attaches to every response: the prefix given to the adapter followed by the hostname. Key purges need the service ID.

```no_run
use utopia_cdn::cache::{Cache, Fastly};

# async fn run() -> Result<(), utopia_cdn::Error> {
let fastly = Fastly::new("api-token", "domain-").with_service_id("service-id").with_soft_purge(true);
Cache::new(fastly).purge_domain("example.com").await?; // purges `domain-example.com`
# Ok(())
# }
```

```php
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Fastly;

$cache = new Cache(new Fastly('api-token', 'domain-', 'service-id', softPurge: true));
// $cache->purgeDomain('example.com');
```

## Purge every provider that caches a domain

Put each provider in a balancer as a `CdnOption`, filter the options, and purge through `cache::Balancer`. Every remaining option is purged even when one fails; the failures come back together in `Error::Purge`.

```no_run
use std::sync::Arc;

use utopia_balancer::{Balancer as Options, First};
use utopia_cdn::cache::{Balancer, Cache, Cloudflare, Fastly};
use utopia_cdn::{CdnOption, Error};

# async fn run() -> Result<(), Error> {
let mut options = Options::new(First);
options
    .add_option(CdnOption::new(Arc::new(Fastly::new("token", "domain-").with_service_id("edge")), CdnOption::PROVIDER_FASTLY, true))
    .add_option(CdnOption::new(Arc::new(Fastly::new("token", "domain-").with_service_id("sites")), CdnOption::PROVIDER_FASTLY, false))
    .add_option(CdnOption::new(Arc::new(Cloudflare::new("zone", "token")), CdnOption::PROVIDER_CLOUDFLARE, false))
    .add_filter(|option: &CdnOption| !option.is_edge());

match Cache::new(Balancer::new(options)).purge_domain("customer.example.com").await {
    Err(Error::Purge { message, errors }) => eprintln!("{message} ({} failures)", errors.len()),
    other => other?,
}
# Ok(())
# }
```

```php
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer as Options;
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Balancer;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Cdn\Exception\Purge;
use Utopia\Cdn\Extend\CdnOption;

$options = new Options(new First());
$options
    ->addOption(new CdnOption(new Fastly('token', 'domain-', 'edge'), CdnOption::PROVIDER_FASTLY, true))
    ->addOption(new CdnOption(new Fastly('token', 'domain-', 'sites'), CdnOption::PROVIDER_FASTLY))
    ->addOption(new CdnOption(new Cloudflare('zone', 'token'), CdnOption::PROVIDER_CLOUDFLARE))
    ->addFilter(fn (CdnOption $option): bool => !$option->isEdge());

$cache = new Cache(new Balancer($options));
// try { $cache->purgeDomain('customer.example.com'); } catch (Purge $e) { ... $e->getErrors() ... }
```

## Issue a certificate and wait for it

Ask whether a certificate is needed, issue it, then poll its status. A certificate authority waiting on the domain owner is an `Error::Certificate` carrying the DNS records to create, so a poll never mistakes it for progress.

```no_run
use utopia_cdn::Error;
use utopia_cdn::certificates::{Certificates, Fastly, Status};

# async fn run() -> Result<(), Error> {
let certificates = Certificates::new(Fastly::new("api-token", "service-id").with_tls_configuration_id("tls-configuration"));
if certificates.is_renew_required("shop.example.com", None).await? {
    certificates.issue_certificate("shop", "shop.example.com", None).await?;
}
match certificates.certificate_status("shop.example.com", None).await {
    Ok(Status::Issued) => println!("serving"),
    Ok(status) => println!("still {status}"),
    Err(Error::Certificate(blocked)) => {
        for challenge in &blocked.challenges {
            println!("create {} {} -> {}", challenge.record_type, challenge.record_name, challenge.values.join(" or "));
        }
    }
    Err(e) => return Err(e),
}
# Ok(())
# }
```

```php
use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider\Fastly;
use Utopia\Cdn\Exception\Certificate;

$certificates = new Certificates(new Fastly('api-token', 'service-id', tlsConfigurationId: 'tls-configuration'));
// if ($certificates->isRenewRequired('shop.example.com', null)) {
//     $certificates->issueCertificate('shop', 'shop.example.com', null);
// }
// try { echo $certificates->getCertificateStatus('shop.example.com', null); }
// catch (Certificate $e) { foreach ($e->getChallenges() as $c) { echo $c->recordName, "\n"; } }
```

## Route certificates by domain

`Proxy` sends the app domain, network domains and custom domains to different providers.

```
use std::sync::Arc;

use utopia_cdn::certificates::{Cloudflare, FastlyTls, Proxy};

let proxy = Proxy::new(
    "app.example.com",
    Arc::new(FastlyTls::new("token", "app-configuration")),
    Arc::new(FastlyTls::new("token", "edge-configuration")),
    vec![Arc::new(Cloudflare::new("zone", "token"))],
)?;
assert_eq!(proxy.app_domain(), "app.example.com");
# Ok::<(), utopia_cdn::Error>(())
```

```php
use Utopia\Cdn\Certificates\Provider\Cloudflare;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Provider\Proxy;

$proxy = new Proxy(
    'app.example.com',
    new FastlyTls('token', 'app-configuration'),
    new FastlyTls('token', 'edge-configuration'),
    [new Cloudflare('zone', 'token')],
);
```
