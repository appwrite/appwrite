Utopia Emails parses email addresses and answers the questions an application asks about them: is the address well formed, what is its canonical form at its mail provider, and is its domain free, disposable or corporate. It also ships validators for request parameters. This guide covers the common tasks.

## Validate an address from a sign-up form

`EmailValidator` accepts a string that parses as a valid address. Surrounding spaces and upper case are fine, because the address is trimmed and lower-cased first.

```
use utopia_emails::EmailValidator;

let validator = EmailValidator::default();

assert!(validator.is_valid_address("  Jane.Doe@Example.com "));
assert!(!validator.is_valid_address("jane.doe"));
assert!(!validator.is_valid_address("jane@doe@example.com"));
assert!(!validator.is_valid_address(""));

// An optional field: allow it to stay empty.
assert!(EmailValidator::new(true).is_valid_address(""));
```

```php
use Utopia\Emails\Validator\Email;

$validator = new Email();

var_dump($validator->isValid('  Jane.Doe@Example.com ')); // bool(true)
var_dump($validator->isValid('jane.doe'));                // bool(false)
var_dump($validator->isValid('jane@doe@example.com'));    // bool(false)
var_dump($validator->isValid(''));                        // bool(false)

var_dump((new Email(true))->isValid('')); // bool(true)
```

## Read the parts of an address

Parse the address once, then read its local part, its domain, and the provider and subdomain inside the domain. `formatted` returns any one of them by name.

```
use utopia_emails::{Email, Format};

let email = Email::new("  Jane@Mail.Example.co.uk ")?;

assert_eq!(email.get(), "jane@mail.example.co.uk");
assert_eq!(email.local(), "jane");
assert_eq!(email.domain(), "mail.example.co.uk");
assert_eq!(email.provider(), "example.co.uk");
assert_eq!(email.subdomain(), "mail");
assert!(email.has_subdomain());
assert_eq!(email.formatted(Format::Provider), "example.co.uk");
assert_eq!(email.formatted(Format::from_name("local")), "jane");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

$email = new Email('  Jane@Mail.Example.co.uk ');

echo $email->get(), "\n";                                // jane@mail.example.co.uk
echo $email->getLocal(), "\n";                           // jane
echo $email->getDomain(), "\n";                          // mail.example.co.uk
echo $email->getProvider(), "\n";                        // example.co.uk
echo $email->getSubdomain(), "\n";                       // mail
var_dump($email->hasSubdomain());                        // bool(true)
echo $email->getFormatted(Email::FORMAT_PROVIDER), "\n"; // example.co.uk
echo $email->getFormatted('local'), "\n";                // jane
```

## Check the local part and the domain

`FILTER_VALIDATE_EMAIL` allows more than most applications want. `has_valid_local` allows only letters, digits and `._+-` with no stray dots, and `has_valid_domain` requires a known public suffix. `EmailLocal` and `EmailDomain` apply the same rules as validators.

```
use utopia_emails::{Email, EmailDomain, EmailLocal};

let email = Email::new("o'brien@example.com")?;
assert!(email.is_valid());
assert!(!email.has_valid_local());
assert!(email.has_valid_domain());

assert!(EmailLocal.is_valid_address("jane.doe+news@example.com"));
assert!(!EmailLocal.is_valid_address("o'brien@example.com"));
assert!(EmailDomain.is_valid_address("jane@mail.example.com"));
assert!(!EmailDomain.is_valid_address("jane@example.notatld"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;
use Utopia\Emails\Validator\EmailDomain;
use Utopia\Emails\Validator\EmailLocal;

$email = new Email("o'brien@example.com");
var_dump($email->isValid());        // bool(true)
var_dump($email->hasValidLocal());  // bool(false)
var_dump($email->hasValidDomain()); // bool(true)

var_dump((new EmailLocal())->isValid('jane.doe+news@example.com')); // bool(true)
var_dump((new EmailLocal())->isValid("o'brien@example.com"));       // bool(false)
var_dump((new EmailDomain())->isValid('jane@mail.example.com'));    // bool(true)
var_dump((new EmailDomain())->isValid('jane@example.notatld'));     // bool(false)
```

## Normalize an address before storing it

Many providers deliver several spellings to the same inbox. Gmail ignores dots and `+tags`, and Outlook ignores `+tags`. Store `canonical` to stop one person from signing up many times. Unknown domains keep their address as is.

```
use utopia_emails::Email;

let canonical = |address: &str| Email::new(address)?.canonical();

assert_eq!(canonical("Jane.Doe+promo@googlemail.com")?, "janedoe@gmail.com");
assert_eq!(canonical("jane.doe+promo@hotmail.co.uk")?, "jane.doe@outlook.com");
assert_eq!(canonical("jane.doe+promo@me.com")?, "jane.doe@icloud.com");
assert_eq!(canonical("jane.doe+promo@example.com")?, "jane.doe+promo@example.com");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

echo (new Email('Jane.Doe+promo@googlemail.com'))->getCanonical(), "\n"; // janedoe@gmail.com
echo (new Email('jane.doe+promo@hotmail.co.uk'))->getCanonical(), "\n";  // jane.doe@outlook.com
echo (new Email('jane.doe+promo@me.com'))->getCanonical(), "\n";         // jane.doe@icloud.com
echo (new Email('jane.doe+promo@example.com'))->getCanonical(), "\n";    // jane.doe+promo@example.com
```

## Find the mail provider behind a domain

`is_canonical_supported` says whether a provider with aliasing rules owns the domain, and `canonical_domain` names that provider's main domain. Use it to group users by provider.

```
use utopia_emails::Email;

let email = Email::new("jane@live.com")?;
assert!(email.is_canonical_supported());
assert_eq!(email.canonical_domain(), Some("outlook.com"));

let email = Email::new("jane@example.com")?;
assert!(!email.is_canonical_supported());
assert_eq!(email.canonical_domain(), None);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

$email = new Email('jane@live.com');
var_dump($email->isCanonicalSupported()); // bool(true)
echo $email->getCanonicalDomain(), "\n";  // outlook.com

$email = new Email('jane@example.com');
var_dump($email->isCanonicalSupported()); // bool(false)
var_dump($email->getCanonicalDomain());   // NULL
```

## Reject disposable domains

Each address is free (a public mail service), disposable (a throwaway inbox) or corporate (anything else). Use `EmailNotDisposable` to block throwaway inboxes, and `EmailCorporate` when only work addresses are welcome.

```
use utopia_emails::{Email, EmailCorporate, EmailNotDisposable};

let email = Email::new("jane@mailinator.com")?;
assert!(email.is_disposable());
assert!(!email.is_free());

let email = Email::new("jane@gmail.com")?;
assert!(email.is_free());
assert!(!email.is_corporate());

assert!(!EmailNotDisposable.is_valid_address("jane@10minutemail.com"));
assert!(EmailNotDisposable.is_valid_address("jane@gmail.com"));
assert!(!EmailCorporate.is_valid_address("jane@gmail.com"));
assert!(EmailCorporate.is_valid_address("jane@company.org"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;
use Utopia\Emails\Validator\EmailCorporate;
use Utopia\Emails\Validator\EmailNotDisposable;

$email = new Email('jane@mailinator.com');
var_dump($email->isDisposable()); // bool(true)
var_dump($email->isFree());       // bool(false)

$email = new Email('jane@gmail.com');
var_dump($email->isFree());       // bool(true)
var_dump($email->isCorporate());  // bool(false)

var_dump((new EmailNotDisposable())->isValid('jane@10minutemail.com')); // bool(false)
var_dump((new EmailNotDisposable())->isValid('jane@gmail.com'));        // bool(true)
var_dump((new EmailCorporate())->isValid('jane@gmail.com'));            // bool(false)
var_dump((new EmailCorporate())->isValid('jane@company.org'));          // bool(true)
```

## Report addresses that cannot be parsed

Parsing fails on an empty value or one without exactly one `@`. Normalizing fails when a provider's rules leave nothing of the local part. Each error carries a message you can show.

```
use utopia_emails::{Email, Error};

for value in ["", "jane.doe", "jane@doe@example.com"] {
    match Email::new(value) {
        Err(Error::Parse(_)) => {}
        other => panic!("expected a parse error, got {other:?}"),
    }
}
assert_eq!(Email::new("jane.doe").unwrap_err().to_string(), "'jane.doe' must be a valid email address");

let error = Email::new("...@gmail.com")?.canonical().unwrap_err();
assert!(matches!(error, Error::InvalidArgument(_)));
assert_eq!(error.to_string(), "Email local part cannot be empty after normalization");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Email;

foreach (['', 'jane.doe', 'jane@doe@example.com'] as $value) {
    try {
        new Email($value);
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n";
    }
}
// Email address cannot be empty
// 'jane.doe' must be a valid email address
// 'jane@doe@example.com' must be a valid email address

try {
    (new Email('...@gmail.com'))->getCanonical();
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage(), "\n"; // Email local part cannot be empty after normalization
}
```

## Add rules for your own mail provider

Implement `Provider` for a domain the built-in providers do not know, then pass it to `canonical_with` ahead of the defaults. The first provider that supports the domain wins.

```
use utopia_emails::{Canonical, Email, Error, Provider, PROVIDERS};

struct Acme;

impl Provider for Acme {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        let local = local.split('-').next().unwrap_or(local);
        Ok(Canonical { local: local.to_owned(), domain: "acme.com".to_owned() })
    }

    fn canonical_domain(&self) -> &str {
        "acme.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["acme.com", "acme.net"]
    }
}

let providers: Vec<&dyn Provider> = std::iter::once(&Acme as &dyn Provider).chain(PROVIDERS.iter().copied()).collect();

assert_eq!(Email::new("jane-news@acme.net")?.canonical_with(&providers)?, "jane@acme.com");
assert_eq!(Email::new("jane+news@gmail.com")?.canonical_with(&providers)?, "jane@gmail.com");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Emails\Canonicals\Provider;
use Utopia\Emails\Canonicals\Providers\Gmail;
use Utopia\Emails\Email;

$acme = new class extends Provider
{
    public function supports(string $domain): bool
    {
        return in_array($domain, $this->getSupportedDomains(), true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        return ['local' => explode('-', $local)[0], 'domain' => 'acme.com'];
    }

    public function getCanonicalDomain(): string
    {
        return 'acme.com';
    }

    public function getSupportedDomains(): array
    {
        return ['acme.com', 'acme.net'];
    }
};

$canonical = function (string $address) use ($acme): string {
    $email = new Email($address);
    foreach ([$acme, new Gmail()] as $provider) {
        if ($provider->supports($email->getDomain())) {
            $result = $provider->getCanonical($email->getLocal(), $email->getDomain());

            return $result['local'].'@'.$result['domain'];
        }
    }

    return $email->getCanonical();
};

echo $canonical('jane-news@acme.net'), "\n";  // jane@acme.com
echo $canonical('jane+news@gmail.com'), "\n"; // jane@gmail.com
```
