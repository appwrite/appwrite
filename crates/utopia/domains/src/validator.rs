//! Validators of domain names against the Public Suffix List.

use std::borrow::Cow;
use std::sync::RwLock;

use php_std::filter::{self, FILTER_VALIDATE_IP, FILTER_VALIDATE_URL, Options};
use php_std::url::parse_url;
use php_std::value::loose_str_eq;
use utopia_validators::View;
use utopia_validators::{Error, Input, Type, Validator, Verdict};

use crate::Domain;

/// Domains accepted by [`PublicDomain`] and [`ApexDomain`] although no
/// public suffix rule matches them ([`PublicDomain::allow`]), shared by the
/// whole process.
///
/// PHP: keeps them in a static property.
static ALLOWED: RwLock<Vec<String>> = RwLock::new(Vec::new());

/// Whether the value passes a validation filter.
///
/// PHP: `filter_var($value, $filter) !== false`.
fn passes(value: Input<'_>, filter: i64) -> bool {
    matches!(
        filter::filter_var(&value.filter_value(), filter, &Options::Flags(0)),
        Ok(f) if f.value != filter::Value::Bool(false)
    )
}

/// What `isValid()` looks at: the host of a URL, else the value itself.
enum Subject<'a> {
    Value(Input<'a>),
    /// The host of a URL value, `None` when it has none.
    ///
    /// PHP: `parse_url($value, PHP_URL_HOST)`; `None` is PHP's `null`.
    Host(Option<Vec<u8>>),
}

impl<'a> Subject<'a> {
    fn of(value: Input<'a>) -> Result<Self, Error> {
        if !passes(value, FILTER_VALIDATE_URL) {
            return Ok(Subject::Value(value));
        }
        let url = value.to_bytes()?;
        Ok(Subject::Host(parse_url(&url).and_then(|u| u.host()).map(Cow::into_owned)))
    }

    /// The string the value is (`is_string()`).
    fn as_str(&self) -> Option<&[u8]> {
        match self {
            Subject::Value(v) => v.as_str(),
            Subject::Host(h) => h.as_deref(),
        }
    }

    /// The value as a `string` parameter takes it: scalars convert, `null`,
    /// arrays and objects are a `TypeError`.
    fn domain(&self) -> Result<Domain<'static>, Error> {
        let given = match self {
            Subject::Host(Some(h)) => return new_domain(h),
            Subject::Host(None) => "null",
            Subject::Value(v) => match v.view() {
                View::Null => "null",
                View::Array(_) => "array",
                View::Object(_) => "stdClass",
                _ => return new_domain(&v.to_bytes()?),
            },
        };
        Err(Error::Type(format!(
            "Utopia\\Domains\\Domain::__construct(): Argument #1 ($domain) must be of type string, {given} given"
        )))
    }
}

fn new_domain(bytes: &[u8]) -> Result<Domain<'static>, Error> {
    Domain::new(&String::from_utf8_lossy(bytes)).map_err(|e| Error::Exception(e.to_string()))
}

/// Whether the subject is a known public domain or an allowed one.
fn is_public(subject: &Subject<'_>) -> Result<bool, Error> {
    let domain = subject.domain()?;
    if domain.is_known() {
        return Ok(true);
    }
    let allowed = ALLOWED.read().unwrap_or_else(|e| e.into_inner());
    Ok(allowed.iter().any(|a| loose_str_eq(domain.get().as_bytes(), a.as_bytes())))
}

/// A domain under a known public suffix, or one of the domains allowed with
/// [`PublicDomain::allow`]. A URL is judged by its host.
///
/// ```
/// use utopia_domains::validator::PublicDomain;
/// use utopia_validators::Validator;
///
/// let validator = PublicDomain;
/// assert!(validator.is_valid(&"example.com".into()));
/// assert!(validator.is_valid(&"https://www.google.com".into()));
/// assert!(!validator.is_valid(&"wiki.team.local".into()));
/// ```
///
/// ```php
/// use Utopia\Domains\Validator\PublicDomain;
///
/// $validator = new PublicDomain();
/// var_dump($validator->isValid('example.com'));            // bool(true)
/// var_dump($validator->isValid('https://www.google.com')); // bool(true)
/// var_dump($validator->isValid('wiki.team.local'));        // bool(false)
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct PublicDomain;

impl PublicDomain {
    /// Accepts these domains too, from now on and in every validator of the
    /// process (they add to those allowed before).
    ///
    /// PHP: `PublicDomain::allow()`.
    ///
    /// ```
    /// use utopia_domains::validator::PublicDomain;
    /// use utopia_validators::Validator;
    ///
    /// PublicDomain::allow(["intranet.example".to_owned()]);
    /// assert!(PublicDomain.is_valid(&"intranet.example".into()));
    /// ```
    ///
    /// ```php
    /// use Utopia\Domains\Validator\PublicDomain;
    ///
    /// PublicDomain::allow(['intranet.example']);
    /// var_dump((new PublicDomain())->isValid('intranet.example')); // bool(true)
    /// ```
    pub fn allow(domains: impl IntoIterator<Item = String>) {
        ALLOWED.write().unwrap_or_else(|e| e.into_inner()).extend(domains);
    }
}

impl Validator for PublicDomain {
    /// What a valid value is, in words.
    fn description(&self) -> String {
        "Value must be a public domain".to_owned()
    }

    /// Whether a JSON value is valid; values that cannot be read as a domain
    /// are not.
    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.validate(Input::Json(value)).is_ok_and(|v| v.valid)
    }

    /// The verdict on a value, or an error for a value that cannot be read as
    /// a domain.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        is_public(&Subject::of(value)?).map(Verdict::of)
    }

    /// Values are strings.
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A public domain that is its own apex (`example.co.uk`, not
/// `blog.example.co.uk`). A URL is judged by its host.
///
/// ```
/// use utopia_domains::validator::ApexDomain;
/// use utopia_validators::Validator;
///
/// assert!(ApexDomain.is_valid(&"bbc.co.uk".into()));
/// assert!(!ApexDomain.is_valid(&"blog.bbc.co.uk".into()));
/// ```
///
/// ```php
/// use Utopia\Domains\Validator\ApexDomain;
///
/// $validator = new ApexDomain();
/// var_dump($validator->isValid('bbc.co.uk'));      // bool(true)
/// var_dump($validator->isValid('blog.bbc.co.uk')); // bool(false)
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct ApexDomain;

impl Validator for ApexDomain {
    /// What a valid value is, in words.
    fn description(&self) -> String {
        "Value must be a public apex domain".to_owned()
    }

    /// Whether a JSON value is valid; values that cannot be read as a domain
    /// are not.
    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.validate(Input::Json(value)).is_ok_and(|v| v.valid)
    }

    /// The verdict on a value, or an error for a value that cannot be read as
    /// a domain.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let subject = Subject::of(value)?;
        // The public check runs on the host again, as a value of its own.
        let public = match &subject {
            Subject::Value(v) => is_public(&Subject::of(*v)?)?,
            Subject::Host(Some(host)) => {
                let host = serde_json::Value::String(String::from_utf8_lossy(host).into_owned());
                is_public(&Subject::of(Input::Json(&host))?)?
            }
            Subject::Host(None) => is_public(&subject)?,
        };
        if !public {
            return Ok(Verdict::INVALID);
        }
        let apex = subject.domain()?.apex();
        Ok(Verdict::of(subject.as_str() == Some(apex.as_bytes())))
    }

    /// Values are strings.
    fn kind(&self) -> Type {
        Type::String
    }
}

/// A lower-case domain name under a known public suffix (`example.com`,
/// `app.example.com`), that is not a public suffix itself (`com`, `co.uk`,
/// `github.io`), an IP address, a URL or a single label like `localhost`.
///
/// ```
/// use utopia_domains::validator::RegistrableDomain;
/// use utopia_validators::Validator;
///
/// assert!(RegistrableDomain.is_valid(&"app.example.com".into()));
/// assert!(!RegistrableDomain.is_valid(&"github.io".into()));
/// assert!(!RegistrableDomain.is_valid(&"Example.com".into()));
/// ```
///
/// ```php
/// use Utopia\Domains\Validator\RegistrableDomain;
///
/// $validator = new RegistrableDomain();
/// var_dump($validator->isValid('app.example.com')); // bool(true)
/// var_dump($validator->isValid('github.io'));       // bool(false)
/// var_dump($validator->isValid('Example.com'));     // bool(false)
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct RegistrableDomain;

impl Validator for RegistrableDomain {
    /// What a valid value is, in words.
    fn description(&self) -> String {
        "Value must be a lowercase domain name such as \"example.com\". IP addresses and public suffixes are not allowed."
            .to_owned()
    }

    /// Whether a JSON value is valid; values that cannot be read as a domain
    /// are not.
    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.validate(Input::Json(value)).is_ok_and(|v| v.valid)
    }

    /// The verdict on a value, or an error for a value that cannot be read as
    /// a domain.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(s) = value.as_str() else {
            return Ok(Verdict::INVALID);
        };
        if php_std::string::strtolower(s) != s {
            return Ok(Verdict::INVALID);
        }
        if !utopia_validators::Domain::default().validate(value)?.valid || passes(value, FILTER_VALIDATE_IP) {
            return Ok(Verdict::INVALID);
        }
        let domain = new_domain(s)?;
        Ok(Verdict::of(domain.is_known() && domain.suffix().as_bytes() != s))
    }

    /// Values are strings.
    fn kind(&self) -> Type {
        Type::String
    }
}
