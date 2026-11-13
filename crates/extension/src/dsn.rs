//! `Utopia\DSN\DSN` over [`utopia_dsn::Dsn`]: each method takes what the PHP
//! method takes and returns what it returns. The DSN and its credentials are
//! `#[\SensitiveParameter]`, as in PHP, so exception traces never hold them.

use std::borrow::Cow;

use ext_php_rs::convert::{FromZval, IntoZval, StubLiteral};
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendCallable, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use php_std::value::{Number, numeric_bytes_ex};
use utopia_dsn::Error;

/// What PHP throws for a DSN `parse_url` cannot split. Rust reads the DSN as
/// UTF-8, so a DSN of other bytes is refused with it too.
const MALFORMED: &str = "Unable to parse DSN: malformed";

/// What `DSN::from()` throws for a part that cannot be represented as given.
const NOT_REPRESENTABLE: &str = "Unable to build DSN: a part does not survive encoding";

/// `Utopia\DSN\DSN`. Its protected properties are the PHP class's, computed from the
/// parsed DSN when PHP reads them (`==`, `var_dump()`, `(array)`), so construction
/// stores nothing twice.
#[php_class]
#[php(name = "Utopia\\DSN\\DSN")]
#[derive(Clone)]
pub struct Dsn {
    dsn: utopia_dsn::Dsn,
}

impl Dsn {
    fn wrap(dsn: utopia_dsn::Dsn) -> Self {
        Self { dsn }
    }
}

/// A PHP string made straight from bytes: one allocation, where `Binary` copies
/// into a `Vec` first.
pub struct Bytes<'a>(&'a [u8]);

/// What `getParam()` returns: the parameter's bytes, or the caller's default.
pub enum Param<'a> {
    Found(&'a [u8]),
    Default(Vec<u8>),
}

impl IntoZval for Param<'_> {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> ext_php_rs::error::Result<()> {
        match self {
            Param::Found(bytes) => Bytes(bytes).set_zval(zv, persistent),
            Param::Default(default) => Bytes(&default).set_zval(zv, persistent),
        }
    }
}

impl IntoZval for Bytes<'_> {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> ext_php_rs::error::Result<()> {
        zv.set_zend_string(ZendStr::new(self.0, persistent));
        Ok(())
    }
}

/// A `string` parameter of `DSN.php`, which has no `strict_types`, so PHP
/// converts scalars: ints, floats and bools become strings (floats through
/// `strval()`, so `precision` applies) and a `Stringable` object its
/// `__toString()`. `null`, arrays and other objects are a `TypeError`.
pub struct PhpString<'a>(Cow<'a, [u8]>);

impl<'a> FromZval<'a> for PhpString<'a> {
    const TYPE: DataType = DataType::String;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        if let Some(string) = zval.zend_str() {
            return Some(Self(Cow::Borrowed(string.as_bytes())));
        }
        if let Some(long) = zval.long() {
            return Some(Self(Cow::Owned(long.to_string().into_bytes())));
        }
        if let Some(bool) = zval.bool() {
            return Some(Self(Cow::Borrowed(if bool { b"1" } else { b"" })));
        }
        let stringable = zval.object().is_some_and(|object| object.instance_of(ce::stringable()));
        if zval.double().is_none() && !stringable {
            return None;
        }
        let converted = ZendCallable::try_from_name("strval").ok()?.try_call(vec![zval]).ok()?;
        converted.zend_str().map(|string| Self(Cow::Owned(string.as_bytes().to_vec())))
    }
}

impl PhpString<'_> {
    fn bytes(&self) -> &[u8] {
        &self.0
    }
}

/// A string default, such as `getParam()`'s `$default = ''`.
impl<'a> From<&'a str> for PhpString<'a> {
    fn from(default: &'a str) -> Self {
        Self(Cow::Borrowed(default.as_bytes()))
    }
}

impl StubLiteral for PhpString<'_> {
    fn stub_literal(&self) -> String {
        String::from_utf8_lossy(&self.0).as_ref().stub_literal()
    }
}

/// A `?int` parameter of `DSN.php`: an int, a bool, a float (truncated, as
/// PHP does with a deprecation) or a numeric string. Anything else is a
/// `TypeError`; `null` is handled by the `Option` around it.
pub struct PhpInt(i64);

impl FromZval<'_> for PhpInt {
    const TYPE: DataType = DataType::Long;

    #[expect(clippy::cast_possible_truncation, reason = "PHP truncates a float argument to an int")]
    fn from_zval(zval: &Zval) -> Option<Self> {
        if let Some(long) = zval.long() {
            return Some(Self(long));
        }
        if let Some(bool) = zval.bool() {
            return Some(Self(i64::from(bool)));
        }
        let float = |f: f64| (f.is_finite() && f >= i64::MIN as f64 && f < i64::MAX as f64).then(|| Self(f as i64));
        if let Some(double) = zval.double() {
            return float(double);
        }
        match numeric_bytes_ex(zval.zend_str()?.as_bytes())?.0 {
            Number::Int(int) => Some(Self(int)),
            Number::Float(double) => float(double),
        }
    }
}

/// `from()`'s `array $params = []`: an array argument whose default is empty.
pub struct Params<'a>(Option<&'a ZendHashTable>);

impl<'a> FromZval<'a> for Params<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.array().map(|array| Params(Some(array)))
    }
}

impl StubLiteral for Params<'_> {
    fn stub_literal(&self) -> String {
        "[]".into()
    }
}

/// Bytes as the lossy UTF-8 a property shows; the getters return them exactly.
fn lossy(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
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
    pub fn __construct(dsn: PhpString<'_>) -> PhpResult<Self> {
        utopia_dsn::Dsn::parse(text(dsn.bytes(), MALFORMED)?).map(Self::wrap).map_err(thrown)
    }

    /// `from(string $scheme, string $host, #[\SensitiveParameter] ?string $user = null,
    /// #[\SensitiveParameter] ?string $password = null, ?int $port = null, string $path = '',
    /// array $params = []): self`.
    #[php(sensitive(user, password), defaults(path = "\"\"", params = "Params(None)"))]
    pub fn from(
        scheme: PhpString<'_>,
        host: PhpString<'_>,
        user: Option<PhpString<'_>>,
        password: Option<PhpString<'_>>,
        port: Option<PhpInt>,
        path: PhpString<'_>,
        params: Params<'_>,
    ) -> PhpResult<Self> {
        // PHP compares the parsed query to `$params` with `!==`: a value that
        // is not a string never reads back as given.
        let mut pairs: Vec<(String, &str)> = Vec::new();
        for (key, value) in params.0.into_iter().flat_map(ZendHashTable::iter) {
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
        let scheme = text(scheme.bytes(), NOT_REPRESENTABLE)?;
        let host = text(host.bytes(), NOT_REPRESENTABLE)?;
        let user = user.as_ref().map(PhpString::bytes);
        let password = password.as_ref().map(PhpString::bytes);
        let port = port.map(|port| port.0);
        utopia_dsn::Dsn::from(scheme, host, user, password, port, path.bytes(), &pairs).map(Self::wrap).map_err(thrown)
    }

    /// The protected properties of `DSN.php`, in its declaration order.
    #[php(getter, name = "scheme", vis = "protected")]
    pub fn scheme_property(&self) -> String {
        self.dsn.scheme().into()
    }

    #[php(getter, name = "user", vis = "protected")]
    pub fn user_property(&self) -> Option<String> {
        self.dsn.user().map(lossy)
    }

    #[php(getter, name = "password", vis = "protected")]
    pub fn password_property(&self) -> Option<String> {
        self.dsn.password().map(lossy)
    }

    #[php(getter, name = "host", vis = "protected")]
    pub fn host_property(&self) -> String {
        self.dsn.host().into()
    }

    #[php(getter, name = "port", vis = "protected")]
    pub fn port_property(&self) -> Option<String> {
        self.dsn.port().map(|port| port.to_string())
    }

    #[php(getter, name = "path", vis = "protected")]
    pub fn path_property(&self) -> Option<String> {
        Some(lossy(self.dsn.path()))
    }

    #[php(getter, name = "query", vis = "protected")]
    pub fn query_property(&self) -> Option<String> {
        self.dsn.query().map(Into::into)
    }

    /// PHP caches the parsed query here on the first `getParam()`; Rust caches
    /// it in [`utopia_dsn::Dsn`], so this stays null.
    #[php(getter, name = "params", vis = "protected")]
    pub fn params_property(&self) -> Option<Vec<String>> {
        None
    }

    /// `getScheme(): string`.
    pub fn get_scheme(&self) -> &str {
        self.dsn.scheme()
    }

    /// `getUser(): ?string`.
    pub fn get_user(&self) -> Option<Bytes<'_>> {
        self.dsn.user().map(Bytes)
    }

    /// `getPassword(): ?string`.
    pub fn get_password(&self) -> Option<Bytes<'_>> {
        self.dsn.password().map(Bytes)
    }

    /// `getHost(): string`.
    pub fn get_host(&self) -> &str {
        self.dsn.host()
    }

    /// `getPort(): ?string`: the port's decimal form, as PHP stores it.
    pub fn get_port(&self) -> Option<String> {
        self.dsn.port().map(|port| port.to_string())
    }

    /// `getPath(): ?string`: `''` when the DSN has none.
    pub fn get_path(&self) -> Option<Bytes<'_>> {
        Some(Bytes(self.dsn.path()))
    }

    /// `getQuery(): ?string`.
    pub fn get_query(&self) -> Option<&str> {
        self.dsn.query()
    }

    /// `getParam(string $key, string $default = ''): string`.
    #[php(defaults(default = "\"\""))]
    pub fn get_param(&self, key: PhpString<'_>, default: PhpString<'_>) -> PhpResult<Param<'_>> {
        let key = text(key.bytes(), MALFORMED).unwrap_or_default();
        Ok(match self.dsn.param(key).map_err(thrown)? {
            Some(value) => Param::Found(value),
            None => Param::Default(default.0.into_owned()),
        })
    }

    /// `__toString(): string`.
    pub fn __to_string(&self) -> String {
        self.dsn.to_string()
    }
}
