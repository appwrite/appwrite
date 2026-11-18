A domain name such as `blog.example.co.uk` has a structure DNS alone does not tell you: `co.uk` is a public suffix (anyone can register under it), `example` is the registered name, and `blog` is a subdomain. This library reads that structure from the Public Suffix List. This guide covers the common tasks.

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
