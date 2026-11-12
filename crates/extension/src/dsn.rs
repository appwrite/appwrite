//! `Utopia\DSN\DSN` over [`utopia_dsn::Dsn`]: each method takes what the PHP
//! method takes and returns what it returns. The DSN and its credentials are
//! `#[\SensitiveParameter]`, as in PHP, so exception traces never hold them.

use ext_php_rs::binary::Binary;
use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::PropertyFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendHashTable};
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_dsn::Error;

/// What PHP throws for a DSN `parse_url` cannot split. Rust reads the DSN as
/// UTF-8, so a DSN of other bytes is refused with it too.
const MALFORMED: &str = "Unable to parse DSN: malformed";

/// What `DSN::from()` throws for a part that cannot be represented as given.
const NOT_REPRESENTABLE: &str = "Unable to build DSN: a part does not survive encoding";

/// `Utopia\DSN\DSN`. The protected properties are the PHP class's, so `==`,
/// `var_dump()` and `(array)` see what they see in PHP; methods read the
/// parsed DSN.
#[php_class]
#[php(name = "Utopia\\DSN\\DSN")]
#[derive(Clone)]
pub struct Dsn {
    dsn: utopia_dsn::Dsn,
    #[php(prop, flags = PropertyFlags::Protected)]
    scheme: String,
    #[php(prop, flags = PropertyFlags::Protected)]
    user: Option<String>,
    #[php(prop, flags = PropertyFlags::Protected)]
    password: Option<String>,
    #[php(prop, flags = PropertyFlags::Protected)]
    host: String,
    #[php(prop, flags = PropertyFlags::Protected)]
    port: Option<String>,
    #[php(prop, flags = PropertyFlags::Protected)]
    path: Option<String>,
    #[php(prop, flags = PropertyFlags::Protected)]
    query: Option<String>,
    /// PHP caches the parsed query here on the first `getParam()`; Rust
    /// caches it in [`utopia_dsn::Dsn`], so this stays null.
    #[php(prop, flags = PropertyFlags::Protected)]
    params: Option<Vec<String>>,
}

impl Dsn {
    /// `dsn` with the PHP class's properties filled in. A user, password or
    /// path that URL-decodes to bytes that are not UTF-8 (`%FF`) reads exactly
    /// through its getter and lossily through the property.
    fn wrap(dsn: utopia_dsn::Dsn) -> Self {
        let text = |bytes: &[u8]| String::from_utf8_lossy(bytes).into_owned();
        Self {
            scheme: dsn.scheme().into(),
            user: dsn.user().map(text),
            password: dsn.password().map(text),
            host: dsn.host().into(),
            port: dsn.port().map(|port| port.to_string()),
            path: Some(text(dsn.path())),
            query: dsn.query().map(Into::into),
            params: None,
            dsn,
        }
    }
}

/// `error` as the exception PHP throws for it.
fn thrown(error: Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.to_string(), 0, class)
}

fn invalid(message: &'static str) -> PhpException {
    thrown(Error::InvalidArgument(message))
}

/// `bytes` as UTF-8, or the exception for a part Rust cannot take.
fn text<'a>(bytes: &'a [u8], message: &'static str) -> PhpResult<&'a str> {
    std::str::from_utf8(bytes).map_err(|_| invalid(message))
}

#[php_impl]
impl Dsn {
    /// `__construct(#[\SensitiveParameter] string $dsn)`.
    #[php(sensitive(dsn))]
    pub fn __construct(dsn: BinarySlice<u8>) -> PhpResult<Self> {
        utopia_dsn::Dsn::parse(text(&dsn, MALFORMED)?).map(Self::wrap).map_err(thrown)
    }

    /// `from(string $scheme, string $host, #[\SensitiveParameter] ?string $user = null,
    /// #[\SensitiveParameter] ?string $password = null, ?int $port = null, string $path = '',
    /// array $params = []): self`.
    #[php(sensitive(user, password), defaults(path = "\"\""))]
    pub fn from(
        scheme: String,
        host: String,
        user: Option<BinarySlice<u8>>,
        password: Option<BinarySlice<u8>>,
        port: Option<i64>,
        path: String,
        params: Option<&ZendHashTable>,
    ) -> PhpResult<Self> {
        // PHP compares the parsed query to `$params` with `!==`: a value that
        // is not a string never reads back as given.
        let mut pairs: Vec<(String, &str)> = Vec::new();
        for (key, value) in params.into_iter().flat_map(ZendHashTable::iter) {
            let key = match key {
                ArrayKey::Long(key) => key.to_string(),
                ArrayKey::String(key) => key,
                ArrayKey::Str(key) => key.to_string(),
                ArrayKey::ZendString(key) => key.as_str().map_err(|_| invalid(NOT_REPRESENTABLE))?.to_string(),
            };
            let value = value.str().ok_or_else(|| invalid(NOT_REPRESENTABLE))?;
            pairs.push((key, value));
        }
        let pairs: Vec<(&str, &str)> = pairs.iter().map(|(key, value)| (key.as_str(), *value)).collect();
        let user = user.as_ref().map(|user| &user[..]);
        let password = password.as_ref().map(|password| &password[..]);
        utopia_dsn::Dsn::from(&scheme, &host, user, password, port, path.as_bytes(), &pairs)
            .map(Self::wrap)
            .map_err(thrown)
    }

    /// `getScheme(): string`.
    pub fn get_scheme(&self) -> String {
        self.dsn.scheme().into()
    }

    /// `getUser(): ?string`.
    pub fn get_user(&self) -> Option<Binary<u8>> {
        self.dsn.user().map(|user| Binary::from(user.to_vec()))
    }

    /// `getPassword(): ?string`.
    pub fn get_password(&self) -> Option<Binary<u8>> {
        self.dsn.password().map(|password| Binary::from(password.to_vec()))
    }

    /// `getHost(): string`.
    pub fn get_host(&self) -> String {
        self.dsn.host().into()
    }

    /// `getPort(): ?string`: the port's decimal form, as PHP stores it.
    pub fn get_port(&self) -> Option<String> {
        self.dsn.port().map(|port| port.to_string())
    }

    /// `getPath(): ?string`: `''` when the DSN has none.
    pub fn get_path(&self) -> Binary<u8> {
        Binary::from(self.dsn.path().to_vec())
    }

    /// `getQuery(): ?string`.
    pub fn get_query(&self) -> Option<String> {
        self.dsn.query().map(Into::into)
    }

    /// `getParam(string $key, string $default = ''): string`.
    #[php(defaults(default = "\"\""))]
    pub fn get_param(&self, key: String, default: String) -> PhpResult<Binary<u8>> {
        let value = self.dsn.param_or(&key, default.as_bytes()).map_err(thrown)?;
        Ok(Binary::from(value.to_vec()))
    }

    /// `__toString(): string`.
    pub fn __to_string(&self) -> String {
        self.dsn.to_string()
    }
}
