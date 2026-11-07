//! Parse data source names (`scheme://user:password@host:port/path?query`):
//! the connection strings that configure databases, caches, queues and other
//! services.
//!
//! [`Dsn::parse`] splits a DSN into its scheme, credentials, host, port, path
//! and query, and [`Dsn::param`] reads one query parameter. Error messages
//! never quote the DSN, and the `Debug` output of a [`Dsn`] hides the
//! password, so credentials do not leak into logs.
//!
//! PHP: ports `utopia-php/dsn`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `new DSN($dsn)` | [`Dsn::parse`] |
//! | `getScheme()`, `getHost()` | [`Dsn::scheme`], [`Dsn::host`] |
//! | `getUser()`, `getPassword()` | [`Dsn::user`], [`Dsn::password`] (bytes: they are URL-decoded) |
//! | `getPort()` (`?string`) | [`Dsn::port`] (`Option<u16>`; PHP's string is its decimal form) |
//! | `DSN::from(...)` | [`Dsn::from`] |
//! | `getPath()`, `getQuery()` | [`Dsn::path`] (bytes: it is URL-decoded), [`Dsn::query`] |
//! | `__toString()` | [`Dsn`]'s `Display` |
//! | `getParam($key, $default)` | [`Dsn::param`] (`Ok(None)` where PHP returns `$default`), [`Dsn::param_or`] |
//! | `\InvalidArgumentException`, `\TypeError` | [`Error::InvalidArgument`], [`Error::Type`] |
//!
//! PHP: the DSN is split by PHP's `parse_url` and the query by PHP's
//! `parse_str` ([`php_std`]), so every input reads exactly as it does in PHP.
//! PHP marks the argument `#[\SensitiveParameter]`.
//!
//! Read a database connection string:
//!
//! ```
//! use utopia_dsn::Dsn;
//!
//! let dsn = Dsn::parse("mariadb://user:secret@localhost:3306/appwrite?charset=utf8mb4")?;
//! assert_eq!(dsn.scheme(), "mariadb");
//! assert_eq!(dsn.host(), "localhost");
//! assert_eq!(dsn.port(), Some(3306));
//! assert_eq!(dsn.path(), b"appwrite");
//! assert_eq!(dsn.param("charset")?, Some(&b"utf8mb4"[..]));
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\DSN\DSN;
//!
//! $dsn = new DSN('mariadb://user:secret@localhost:3306/appwrite?charset=utf8mb4');
//! echo $dsn->getScheme(), "\n";          // mariadb
//! echo $dsn->getHost(), "\n";            // localhost
//! echo $dsn->getPort(), "\n";            // 3306
//! echo $dsn->getPath(), "\n";            // appwrite
//! echo $dsn->getParam('charset'), "\n";  // utf8mb4
//! ```

#![warn(missing_docs)]

use std::fmt;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
use std::sync::OnceLock;

use php_std::encoding::{parse_str, rawurldecode, rawurlencode, urldecode};
use php_std::{Array, ArrayKey, Value};

/// Why a DSN was refused or a parameter could not be read.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// The DSN is malformed or lacks a scheme or host.
    ///
    /// PHP: `\InvalidArgumentException`.
    ///
    /// ```
    /// use utopia_dsn::{Dsn, Error};
    ///
    /// let error = Dsn::parse("localhost:3306").unwrap_err();
    /// assert!(matches!(error, Error::InvalidArgument(_)));
    /// assert_eq!(error.to_string(), "Unable to parse DSN: scheme is required");
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// try {
    ///     new DSN('localhost:3306');
    /// } catch (\InvalidArgumentException $e) {
    ///     echo $e->getMessage(), "\n"; // Unable to parse DSN: scheme is required
    /// }
    /// ```
    #[error("{0}")]
    InvalidArgument(&'static str),
    /// [`Dsn::param`] was called on a key the query holds as a list or map
    /// (`a[b]=c`), which has no single value to return.
    ///
    /// PHP: `\TypeError`, as PHP cannot return an array as a string.
    #[error("{0}")]
    Type(&'static str),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `InvalidArgumentException`.
    ///
    /// PHP: the class of the exception `utopia-php/dsn` throws for the same
    /// input.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let error = Dsn::parse("redis://").unwrap_err();
    /// assert_eq!(error.php_class(), "InvalidArgumentException");
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// try {
    ///     new DSN('redis://');
    /// } catch (\Throwable $e) {
    ///     echo get_class($e), "\n"; // InvalidArgumentException
    /// }
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Type(_) => "TypeError",
        }
    }
}

/// A parsed DSN.
///
/// Its `Debug` output hides the password:
///
/// ```
/// use utopia_dsn::Dsn;
///
/// let dsn = Dsn::parse("redis://default:secret@cache:6379")?;
/// let printed = format!("{dsn:?}");
/// assert!(printed.contains("cache"));
/// assert!(!printed.contains("secret"));
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\DSN\DSN;
///
/// $dsn = new DSN('redis://default:secret@cache:6379');
/// echo $dsn->getHost(), "\n"; // cache
/// ```
#[derive(Clone)]
pub struct Dsn {
    scheme: String,
    user: Option<Vec<u8>>,
    password: Option<Vec<u8>>,
    host: String,
    port: Option<u16>,
    path: Vec<u8>,
    query: Option<String>,
    /// The query parsed by `parse_str`, on first use.
    params: OnceLock<Array>,
}

impl Dsn {
    /// Parses a DSN.
    ///
    /// Fails with [`Error::InvalidArgument`] when the input is malformed, or
    /// the scheme or host is missing or empty. A scheme or host of `"0"`
    /// counts as missing.
    ///
    /// PHP: `new DSN($dsn)`. It fails where PHP's `parse_url` rejects the
    /// input, and `"0"` is missing because PHP's `empty()` says so.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("redis://cache:6379")?;
    /// assert_eq!(dsn.host(), "cache");
    /// assert!(Dsn::parse("redis://").is_err());
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('redis://cache:6379');
    /// echo $dsn->getHost(), "\n"; // cache
    /// ```
    pub fn parse(dsn: &str) -> Result<Self, Error> {
        let url =
            php_std::url::parse_url(dsn.as_bytes()).ok_or(Error::InvalidArgument("Unable to parse DSN: malformed"))?;
        let scheme = url.scheme().filter(|s| !blank(s));
        let host = url.host().filter(|h| !blank(h));
        let (Some(scheme), Some(host)) = (scheme, host) else {
            return Err(Error::InvalidArgument(if url.scheme().is_none_or(|s| blank(&s)) {
                "Unable to parse DSN: scheme is required"
            } else {
                "Unable to parse DSN: host is required"
            }));
        };
        // Components of a `&str` split at ASCII bytes are UTF-8.
        let text = |b: &[u8]| String::from_utf8_lossy(b).into_owned();
        Ok(Self {
            scheme: text(&scheme),
            user: url.user().map(|u| urldecode(&u)),
            password: url.pass().map(|p| urldecode(&p)),
            host: text(&host),
            port: url.port(),
            path: url.path().map(|p| rawurldecode(php_std::string::ltrim(&p, b"/"))).unwrap_or_default(),
            query: url.query().map(|q| text(&q)),
            params: OnceLock::new(),
        })
    }

    /// Builds a DSN from its parts, encoding each one, so a value cannot
    /// reshape it (a `?` in the path starting the query, an `@` in the user
    /// moving the host).
    ///
    /// Fails with [`Error::InvalidArgument`] when `password` is given without
    /// `user`, or when a part would not read back exactly as given (a host
    /// holding `/`, `@` or `:`, a parameter key `getParam` rewrites, like
    /// `a.b` or `x[y]`).
    ///
    /// PHP: `DSN::from($scheme, $host, $user, $password, $port, $path,
    /// $params)`. The port is PHP's `?int`; a key of `params` that is a
    /// canonical integer is an integer key, as in a PHP array literal, and a
    /// repeated key keeps its last value.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::from(
    ///     "s3",
    ///     "minio.example.com",
    ///     Some(b"admin@example.com"),
    ///     Some(b"p@ss/word"),
    ///     Some(9000),
    ///     b"bucket",
    ///     &[("region", "us-east-1")],
    /// )?;
    /// assert_eq!(dsn.to_string(), "s3://admin%40example.com:p%40ss%2Fword@minio.example.com:9000/bucket?region=us-east-1");
    /// assert!(Dsn::from("s3", "user@minio.example.com", None, None, None, b"", &[]).is_err());
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = DSN::from(
    ///     scheme: 's3',
    ///     host: 'minio.example.com',
    ///     user: 'admin@example.com',
    ///     password: 'p@ss/word',
    ///     port: 9000,
    ///     path: 'bucket',
    ///     params: ['region' => 'us-east-1'],
    /// );
    /// echo $dsn, "\n"; // s3://admin%40example.com:p%40ss%2Fword@minio.example.com:9000/bucket?region=us-east-1
    /// ```
    pub fn from(
        scheme: &str,
        host: &str,
        user: Option<&[u8]>,
        password: Option<&[u8]>,
        port: Option<i64>,
        path: &[u8],
        params: &[(&str, &str)],
    ) -> Result<Self, Error> {
        if user.is_none() && password.is_some() {
            return Err(Error::InvalidArgument("Unable to build DSN: a password needs a user"));
        }
        let mut wanted = Array::new();
        for (key, value) in params {
            wanted.set(ArrayKey::from(*key), Value::from(*value));
        }

        let mut dsn = format!("{scheme}://");
        write_credentials(&mut dsn, user, password);
        dsn.push_str(host);
        if let Some(port) = port {
            dsn.push(':');
            dsn.push_str(&port.to_string());
        }
        write_path(&mut dsn, path);
        if !wanted.is_empty() {
            // http_build_query($params, '', '&', PHP_QUERY_RFC3986) of string values.
            dsn.push('?');
            for (index, (key, value)) in wanted.iter().enumerate() {
                if index > 0 {
                    dsn.push('&');
                }
                let key = match key {
                    php_std::types::KeyRef::Int(i) => i.to_string(),
                    php_std::types::KeyRef::Str(s) => rawurlencode(s),
                };
                dsn.push_str(&key);
                dsn.push('=');
                if let Value::Str(value) = value {
                    dsn.push_str(&rawurlencode(value));
                }
            }
        }

        let parsed = Self::parse(&dsn)?;
        drop(dsn);

        // getParam() reads the query with parse_str, which nests `a[b]` and
        // rewrites `.` and spaces in keys.
        let query = parse_str(parsed.query.as_deref().unwrap_or_default().as_bytes());
        if parsed.scheme != scheme
            || parsed.host != host
            || parsed.user.as_deref() != user
            || parsed.password.as_deref() != password
            || parsed.port.map(i64::from) != port
            || parsed.path != path
            || !Value::Array(query).strict_eq(&Value::Array(wanted))
        {
            return Err(Error::InvalidArgument("Unable to build DSN: a part does not survive encoding"));
        }
        Ok(parsed)
    }

    /// The scheme: the part before `://`.
    ///
    /// PHP: `getScheme()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("postgresql://db:5432/appwrite")?;
    /// assert_eq!(dsn.scheme(), "postgresql");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('postgresql://db:5432/appwrite');
    /// echo $dsn->getScheme(), "\n"; // postgresql
    /// ```
    pub fn scheme(&self) -> &str {
        &self.scheme
    }

    /// The URL-decoded user, which can be any bytes (`%FF`).
    ///
    /// PHP: `getUser()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("smtp://mail%40example.com:secret@smtp.example.com:587")?;
    /// assert_eq!(dsn.user(), Some(&b"mail@example.com"[..]));
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('smtp://mail%40example.com:secret@smtp.example.com:587');
    /// echo $dsn->getUser(), "\n"; // mail@example.com
    /// ```
    pub fn user(&self) -> Option<&[u8]> {
        self.user.as_deref()
    }

    /// The URL-decoded password, which can be any bytes (`%FF`).
    ///
    /// PHP: `getPassword()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("redis://default:p%40ss%2Fword@cache")?;
    /// assert_eq!(dsn.password(), Some(&b"p@ss/word"[..]));
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('redis://default:p%40ss%2Fword@cache');
    /// echo $dsn->getPassword(), "\n"; // p@ss/word
    /// ```
    pub fn password(&self) -> Option<&[u8]> {
        self.password.as_deref()
    }

    /// The host name or address.
    ///
    /// PHP: `getHost()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("mongodb://user:secret@mongo.internal:27017/appwrite")?;
    /// assert_eq!(dsn.host(), "mongo.internal");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('mongodb://user:secret@mongo.internal:27017/appwrite');
    /// echo $dsn->getHost(), "\n"; // mongo.internal
    /// ```
    pub fn host(&self) -> &str {
        &self.host
    }

    /// The port, or `None` when the DSN has none.
    ///
    /// PHP: `getPort()` returns the port as a string (or `null`); Rust as a
    /// number.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// assert_eq!(Dsn::parse("redis://cache:6379")?.port(), Some(6379));
    /// assert_eq!(Dsn::parse("redis://cache")?.port(), None);
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// var_dump((new DSN('redis://cache:6379'))->getPort()); // string(4) "6379"
    /// var_dump((new DSN('redis://cache'))->getPort());      // NULL
    /// ```
    pub fn port(&self) -> Option<u16> {
        self.port
    }

    /// The URL-decoded path without its leading slashes, which can be any
    /// bytes (`%FF`); empty when there is none.
    ///
    /// PHP: `getPath()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// assert_eq!(Dsn::parse("s3://key:secret@s3.amazonaws.com/backups/daily")?.path(), b"backups/daily");
    /// assert_eq!(Dsn::parse("s3://localhost/b%3Furl%3Dx")?.path(), b"b?url=x");
    /// assert_eq!(Dsn::parse("redis://cache")?.path(), b"");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// echo (new DSN('s3://key:secret@s3.amazonaws.com/backups/daily'))->getPath(), "\n"; // backups/daily
    /// ```
    pub fn path(&self) -> &[u8] {
        &self.path
    }

    /// The raw query string, or `None` when the DSN has none.
    ///
    /// PHP: `getQuery()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("mariadb://db/appwrite?charset=utf8mb4&timeout=5")?;
    /// assert_eq!(dsn.query(), Some("charset=utf8mb4&timeout=5"));
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// echo (new DSN('mariadb://db/appwrite?charset=utf8mb4&timeout=5'))->getQuery(), "\n"; // charset=utf8mb4&timeout=5
    /// ```
    pub fn query(&self) -> Option<&str> {
        self.query.as_deref()
    }

    /// The URL-decoded value of a query parameter, or `Ok(None)` when it is
    /// absent.
    ///
    /// A query that is empty or `"0"` has no parameters. Dots and spaces in
    /// keys read as underscores (`a.b` is `a_b`), and a key written with
    /// brackets (`a[b]=c`) holds a list or map, so reading it fails with
    /// [`Error::Type`].
    ///
    /// PHP: `getParam($key)`, where `Ok(None)` stands for PHP returning the
    /// default. Keys follow PHP's `parse_str`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("sms://key:secret@api.twilio.com?from=%2B15550100")?;
    /// assert_eq!(dsn.param("from")?, Some(&b"+15550100"[..]));
    /// assert_eq!(dsn.param("region")?, None);
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('sms://key:secret@api.twilio.com?from=%2B15550100');
    /// echo $dsn->getParam('from'), "\n";          // +15550100
    /// var_dump($dsn->getParam('region'));         // string(0) ""
    /// ```
    pub fn param(&self, key: &str) -> Result<Option<&[u8]>, Error> {
        let query = match self.query.as_deref() {
            None | Some("" | "0") => return Ok(None),
            Some(q) => q,
        };
        let params = self.params.get_or_init(|| parse_str(query.as_bytes()));
        match params.get_str(key) {
            None => Ok(None),
            Some(Value::Str(value)) => Ok(Some(value)),
            Some(_) => {
                Err(Error::Type("Utopia\\DSN\\DSN::getParam(): Return value must be of type string, array returned"))
            }
        }
    }

    /// The parameter, or `default` when it is absent.
    ///
    /// PHP: `getParam($key, $default)`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("s3://key:secret@s3.amazonaws.com/backups")?;
    /// assert_eq!(dsn.param_or("region", b"us-east-1")?, b"us-east-1");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// $dsn = new DSN('s3://key:secret@s3.amazonaws.com/backups');
    /// echo $dsn->getParam('region', 'us-east-1'), "\n"; // us-east-1
    /// ```
    pub fn param_or<'a>(&'a self, key: &str, default: &'a [u8]) -> Result<&'a [u8], Error> {
        Ok(self.param(key)?.unwrap_or(default))
    }
}

/// `user[:password]@`, each part `rawurlencode`d.
fn write_credentials(dsn: &mut String, user: Option<&[u8]>, password: Option<&[u8]>) {
    if let Some(user) = user {
        dsn.push_str(&rawurlencode(user));
        if let Some(password) = password {
            dsn.push(':');
            dsn.push_str(&rawurlencode(password));
        }
        dsn.push('@');
    }
}

/// `/` and the path's segments, each `rawurlencode`d; nothing for an empty path.
fn write_path(dsn: &mut String, path: &[u8]) {
    if !path.is_empty() {
        for segment in path.split(|&b| b == b'/') {
            dsn.push('/');
            dsn.push_str(&rawurlencode(segment));
        }
    }
}

impl fmt::Display for Dsn {
    /// The DSN, with each part encoded as [`Dsn::from`] encodes it, and the
    /// query as it was given.
    ///
    /// PHP: `__toString()`.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// let dsn = Dsn::parse("mariadb://user:p%40ss@localhost:3306/data%20base?charset=utf8")?;
    /// assert_eq!(dsn.to_string(), "mariadb://user:p%40ss@localhost:3306/data%20base?charset=utf8");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// echo new DSN('mariadb://user:p%40ss@localhost:3306/data%20base?charset=utf8'), "\n";
    /// // mariadb://user:p%40ss@localhost:3306/data%20base?charset=utf8
    /// ```
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let mut dsn = format!("{}://", self.scheme);
        write_credentials(&mut dsn, self.user.as_deref(), self.password.as_deref());
        dsn.push_str(&self.host);
        if let Some(port) = self.port {
            dsn.push(':');
            dsn.push_str(&port.to_string());
        }
        write_path(&mut dsn, &self.path);
        if let Some(query) = &self.query {
            dsn.push('?');
            dsn.push_str(query);
        }
        f.write_str(&dsn)
    }
}

/// PHP `empty()` on a string: `""` and `"0"`.
fn blank(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

impl fmt::Debug for Dsn {
    /// Formats every component except the password, which shows as
    /// `<hidden>`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Dsn")
            .field("scheme", &self.scheme)
            .field("user", &self.user.as_deref().map(String::from_utf8_lossy))
            .field("password", &self.password.as_ref().map(|_| "<hidden>"))
            .field("host", &self.host)
            .field("port", &self.port)
            .field("path", &String::from_utf8_lossy(&self.path))
            .field("query", &self.query)
            .finish()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_like_php() {
        let d = Dsn::parse("mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC").unwrap();
        assert_eq!(d.scheme(), "mariadb");
        assert_eq!(d.user(), Some(&b"user"[..]));
        assert_eq!(d.password(), Some(&b"password"[..]));
        assert_eq!(d.port(), Some(3306));
        assert_eq!(d.path(), b"database");
        assert_eq!(d.param("timezone").unwrap(), Some(&b"UTC"[..]));
        assert_eq!(d.param_or("region", b"us-east-1").unwrap(), b"us-east-1");
        let d = Dsn::parse("sms://user:sl%2Fsh%2B%24%40no%3Aher@localhost").unwrap();
        assert_eq!(d.password(), Some(&b"sl/sh+$@no:her"[..]));
        assert_eq!(d.path(), b"");
        assert_eq!(d.query(), None);
    }

    #[test]
    fn refuses_without_quoting_credentials() {
        for dsn in
            ["s3://AKIAKEY:SECRETKEY@/backups", "//AKIAKEY:SECRETKEY@localhost/db", "AKIAKEY:SECRETKEY@localhost"]
        {
            let e = Dsn::parse(dsn).unwrap_err();
            let printed = format!("{e} {e:?}");
            assert!(!printed.contains("AKIAKEY") && !printed.contains("SECRETKEY"), "{printed}");
            assert_eq!(e.php_class(), "InvalidArgumentException");
        }
        let d = Dsn::parse("redis://user:SECRETKEY@host").unwrap();
        assert!(!format!("{d:?}").contains("SECRETKEY"));
    }

    #[test]
    fn array_params_are_type_errors() {
        let d = Dsn::parse("x://h?a[b]=1&c=2").unwrap();
        assert_eq!(d.param("a").unwrap_err().php_class(), "TypeError");
        assert_eq!(d.param("c").unwrap(), Some(&b"2"[..]));
        assert_eq!(Dsn::parse("x://h?0").unwrap().param("0").unwrap(), None);
    }
}
