A domain name such as `blog.example.co.uk` has a structure DNS alone does not tell you: `co.uk` is a public suffix (anyone can register under it), `example` is the registered name, and `blog` is a subdomain. This library reads that structure from the Public Suffix List, validates user input with it, and talks to domain registrars to check availability, quote prices and buy, renew or transfer domains. Registrar calls are async, so those examples run on a Tokio runtime. This guide covers the common tasks.

## Split a domain into its parts

`Domain::new` lower-cases the name and finds its public suffix. `registerable` is what someone actually owns: empty when the suffix is unknown.

```
use utopia_domains::Domain;

let domain = Domain::new("Shop.Example.CO.UK")?;

assert_eq!(domain.get(), "shop.example.co.uk");
assert_eq!(domain.tld(), "uk");
assert_eq!(domain.suffix(), "co.uk");
assert_eq!(domain.name(), "example");
assert_eq!(domain.sub(), "shop");
assert_eq!(domain.registerable(), "example.co.uk");
assert!(domain.is_known() && domain.is_icann());
# Ok::<(), utopia_domains::Error>(())
```

```php
use Utopia\Domains\Domain;

$domain = new Domain('Shop.Example.CO.UK');

echo $domain->get(), "\n";             // shop.example.co.uk
echo $domain->getTLD(), "\n";          // uk
echo $domain->getSuffix(), "\n";       // co.uk
echo $domain->getName(), "\n";         // example
echo $domain->getSub(), "\n";          // shop
echo $domain->getRegisterable(), "\n"; // example.co.uk
var_dump($domain->isKnown() && $domain->isICANN()); // bool(true)
```

## Tell private platforms and test domains apart

The list also has a private section for platforms that hand out subdomains (`github.io`, cloud providers): each user's site is then its own registrable domain. Test and local names have no suffix at all.

```
use utopia_domains::Domain;

let site = Domain::new("my-site.github.io")?;
assert!(site.is_private());
assert_eq!(site.registerable(), "my-site.github.io");

let local = Domain::new("api.localhost")?;
assert!(local.is_test() && !local.is_known());
assert_eq!(local.registerable(), "");
# Ok::<(), utopia_domains::Error>(())
```

```php
use Utopia\Domains\Domain;

$site = new Domain('my-site.github.io');
var_dump($site->isPrivate());            // bool(true)
echo $site->getRegisterable(), "\n";     // my-site.github.io

$local = new Domain('api.localhost');
var_dump($local->isTest() && !$local->isKnown()); // bool(true)
var_dump($local->getRegisterable());     // string(0) ""
```

## Validate domains people type

Three validators cover the usual checks: `PublicDomain` (under a known suffix; a URL is judged by its host), `ApexDomain` (the registrable domain itself) and `RegistrableDomain` (a strict lower-case name that is not a suffix, an IP or a URL).

```
use utopia_domains::validator::{ApexDomain, PublicDomain, RegistrableDomain};
use utopia_validators::Validator;

assert!(PublicDomain.is_valid(&"https://www.example.com/login".into()));
assert!(!PublicDomain.is_valid(&"printer.local".into()));

assert!(ApexDomain.is_valid(&"example.com".into()));
assert!(!ApexDomain.is_valid(&"www.example.com".into()));

assert!(RegistrableDomain.is_valid(&"app.example.com".into()));
assert!(!RegistrableDomain.is_valid(&"co.uk".into()));
assert_eq!(RegistrableDomain.description(), "Value must be a lowercase domain name such as \"example.com\". IP addresses and public suffixes are not allowed.");
```

```php
use Utopia\Domains\Validator\ApexDomain;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Domains\Validator\RegistrableDomain;

var_dump((new PublicDomain())->isValid('https://www.example.com/login')); // bool(true)
var_dump((new PublicDomain())->isValid('printer.local'));                 // bool(false)

var_dump((new ApexDomain())->isValid('example.com'));     // bool(true)
var_dump((new ApexDomain())->isValid('www.example.com')); // bool(false)

var_dump((new RegistrableDomain())->isValid('app.example.com')); // bool(true)
var_dump((new RegistrableDomain())->isValid('co.uk'));           // bool(false)
echo (new RegistrableDomain())->getDescription(), "\n";
```

## Check availability and prices

A `Registrar` drives an adapter. The `Mock` adapter keeps its registry in memory, which makes it the one to use in tests; `NameCom` and `OpenSrs` talk to those registrars. Prices are quoted per registration type and period.

```
use utopia_domains::registrar::{Mock, RegType, Registrar};

let registrar = Registrar::new(Mock::new());

tokio::runtime::Runtime::new()?.block_on(async {
    let availability = registrar.available(&["my-shop.com".to_owned(), "google.com".to_owned()]).await?;
    assert!(availability["my-shop.com"]);
    assert!(!availability["google.com"]);

    let price = registrar.price("my-shop.com", 2, RegType::New, 3600).await?;
    assert_eq!(price.price, 25.98);
    assert!(!price.premium);

    let premium = registrar.price("premium.com", 1, RegType::New, 3600).await?;
    assert!(premium.premium);
    Ok::<_, utopia_domains::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\Mock;

$registrar = new Registrar(new Mock());

$availability = $registrar->available(['my-shop.com', 'google.com']);
var_dump($availability['my-shop.com']); // bool(true)
var_dump($availability['google.com']);  // bool(false)

$price = $registrar->getPrice('my-shop.com', 2, Registrar::REG_TYPE_NEW);
echo $price->price, "\n";  // 25.98
var_dump($price->premium); // bool(false)

var_dump($registrar->getPrice('premium.com')->premium); // bool(true)
```

## Buy a domain

A purchase needs contacts: one `Contact` for every role, or contacts by role (`owner`, `admin`, `tech`, `billing`). The order id comes back; the registrar's errors say why a purchase failed.

```
use utopia_domains::Error;
use utopia_domains::registrar::{Contact, Mock, Purchase, Registrar};

let contact = Contact {
    firstname: "Ada".into(), lastname: "Lovelace".into(), phone: "+442071234567".into(),
    email: "ada@example.com".into(), address1: "12 St James's Square".into(), city: "London".into(),
    state: "LDN".into(), country: "GB".into(), postalcode: "SW1Y 4JH".into(), ..Contact::default()
};
let registrar = Registrar::new(Mock::new());

tokio::runtime::Runtime::new()?.block_on(async {
    let order = registrar.purchase(&Purchase::new("ada-lovelace.com", contact.clone())).await?;
    assert!(order.starts_with("mock_"));

    let taken = registrar.purchase(&Purchase::new("google.com", contact)).await.unwrap_err();
    assert!(matches!(taken, Error::DomainTaken { .. }));
    assert_eq!(taken.to_string(), "Domain google.com is not available for registration");
    Ok::<_, Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\Mock;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Exception\DomainTakenException;

$contact = new Contact('Ada', 'Lovelace', '+442071234567', 'ada@example.com', "12 St James's Square", '', '', 'London', 'LDN', 'GB', 'SW1Y 4JH', '');
$registrar = new Registrar(new Mock());

$order = $registrar->purchase('ada-lovelace.com', $contact);
var_dump(str_starts_with($order, 'mock_')); // bool(true)

try {
    $registrar->purchase('google.com', $contact);
} catch (DomainTakenException $e) {
    echo $e->getMessage(), "\n"; // Domain google.com is not available for registration
}
```

## Cache prices

Give the registrar a `Cache` and price lookups are answered from it for as long as the `ttl` allows. `MemoryStore` keeps entries in the process; implement `Store` to keep them anywhere else.

```
use utopia_domains::registrar::{Config, Mock, RegType, Registrar};
use utopia_domains::{Cache, MemoryStore};

let cache = Cache::new(MemoryStore::new());
let registrar = Registrar::with_config(Mock::new(), Config { cache: Some(cache.clone()), ..Config::default() });

tokio::runtime::Runtime::new()?.block_on(async {
    registrar.price("my-shop.com", 1, RegType::New, 3600).await?;
    let cached = cache.load("my-shop.com", 3600).await.expect("the price is cached");
    assert_eq!(cached["price"], 12.99);
    Ok::<_, utopia_domains::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Domains\Cache;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\Mock;

$cache = new Cache(new UtopiaCache(new Memory()));
$registrar = new Registrar(new Mock(), [], $cache);

$registrar->getPrice('my-shop.com', 1, Registrar::REG_TYPE_NEW, 3600);
echo $cache->load('my-shop.com', 3600)['price'], "\n"; // 12.99
```

## Route registrar traffic through your own transport

The API adapters send requests through a `Transport`. Pass one to record, replay or proxy their traffic; without one they use `HttpTransport` with the registrar's timeouts.

```
use std::sync::{Arc, Mutex};
use utopia_domains::BoxFuture;
use utopia_domains::registrar::{NameCom, Registrar};
use utopia_domains::transport::{Request, Response, Transport, TransportError};

/// Answers every request with `{"authCode": "secret"}` and remembers the URLs.
#[derive(Default)]
struct Replay(Mutex<Vec<String>>);

impl Transport for Replay {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
        self.0.lock().unwrap().push(request.url);
        Box::pin(async { Ok(Response { status: 200, headers: vec![], body: br#"{"authCode":"secret"}"#.to_vec() }) })
    }
}

let replay = Arc::new(Replay::default());
let registrar = Registrar::new(NameCom::new("user", "token", "https://api.dev.name.com", Some(replay.clone())));

let code = tokio::runtime::Runtime::new()?.block_on(registrar.auth_code("example.com"))?;
assert_eq!(code, "secret");
assert_eq!(*replay.0.lock().unwrap(), ["https://api.dev.name.com/core/v1/domains/example.com:getAuthCode"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\NameCom;
use Utopia\Psr7\Response\Factory;
use Utopia\Psr7\Stream\Factory as StreamFactory;

$replay = new class implements ClientInterface {
    public array $urls = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->urls[] = (string) $request->getUri();

        return (new Factory())->createResponse(200)
            ->withBody((new StreamFactory())->createStream('{"authCode":"secret"}'));
    }
};
$registrar = new Registrar(new NameCom('user', 'token', 'https://api.dev.name.com', $replay));

echo $registrar->getAuthCode('example.com'), "\n"; // secret
echo $replay->urls[0], "\n"; // https://api.dev.name.com/core/v1/domains/example.com:getAuthCode
```
