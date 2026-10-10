//! Data source names (`scheme://user:password@host:port/path?query`) with the
//! semantics of `utopia-php/dsn`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `new DSN($dsn)` | [`Dsn::parse`] |
//! | `getScheme()`, `getHost()` | [`Dsn::scheme`], [`Dsn::host`] |
//! | `getUser()`, `getPassword()` | [`Dsn::user`], [`Dsn::password`] (bytes: they are URL-decoded) |
//! | `getPort()` (`?string`) | [`Dsn::port`] (`Option<u16>`; PHP's string is its decimal form) |
//! | `getPath()`, `getQuery()` | [`Dsn::path`], [`Dsn::query`] |
//! | `getParam($key, $default)` | [`Dsn::param`] (`Ok(None)` where PHP returns `$default`), [`Dsn::param_or`] |
//! | `\InvalidArgumentException`, `\TypeError` | [`Error::InvalidArgument`], [`Error::Type`] |
//!
//! The DSN is split by PHP's `parse_url` and the query by PHP's `parse_str`
//! ([`php_std`]), so every input reads exactly as it does in PHP. Error
//! messages never quote the DSN, and [`Dsn`]'s `Debug` hides the password:
//! credentials do not leak into logs (PHP marks the argument
//! `#[\SensitiveParameter]`).
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
//! assert_eq!(dsn.path(), "appwrite");
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

use std::fmt;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
use std::sync::OnceLock;

use php_std::encoding::{parse_str, urldecode};
use php_std::zval::{Array, Key, Zval};

/// Why a DSN was refused or a parameter could not be read.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `\InvalidArgumentException`: the DSN is malformed or lacks a scheme
    /// or host.
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
    /// `\TypeError`: [`Dsn::param`] on a key the query holds as an array
    /// (`a[b]=c`), which PHP cannot return as a string.
    #[error("{0}")]
    Type(&'static str),
}

impl Error {
    /// The PHP exception class this error corresponds to.
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
    path: String,
    query: Option<String>,
    /// The query parsed by `parse_str`, on first use.
    params: OnceLock<Array>,
}

impl Dsn {
    /// `new DSN($dsn)`.
    ///
    /// Fails like PHP: [`Error::InvalidArgument`] when `parse_url` rejects
    /// the input, or the scheme or host is missing or empty (or `"0"`, which
    /// PHP's `empty()` also treats as missing).
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
            path: url.path().map(|p| text(php_std::string::ltrim(&p, b"/"))).unwrap_or_default(),
            query: url.query().map(|q| text(&q)),
            params: OnceLock::new(),
        })
    }

    /// `getScheme()`: the part before `://`.
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

    /// `getUser()`: the URL-decoded user, which can be any bytes (`%FF`).
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

    /// `getPassword()`: the URL-decoded password, which can be any bytes (`%FF`).
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

    /// `getHost()`.
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

    /// `getPort()`: PHP returns the port as a string (or `null`); Rust as a number.
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

    /// `getPath()`: the path without its leading slashes; empty when there is none.
    ///
    /// ```
    /// use utopia_dsn::Dsn;
    ///
    /// assert_eq!(Dsn::parse("s3://key:secret@s3.amazonaws.com/backups/daily")?.path(), "backups/daily");
    /// assert_eq!(Dsn::parse("redis://cache")?.path(), "");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DSN\DSN;
    ///
    /// echo (new DSN('s3://key:secret@s3.amazonaws.com/backups/daily'))->getPath(), "\n"; // backups/daily
    /// ```
    pub fn path(&self) -> &str {
        &self.path
    }

    /// `getQuery()`: the raw query string.
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

    /// `getParam($key)`: the URL-decoded query parameter, or `Ok(None)`
    /// where PHP returns the default (no such key, or a query that is empty
    /// or `"0"`). Keys follow `parse_str`: `a.b` is read as `a_b`, `a[b]=c`
    /// makes `a` an array, which fails with [`Error::Type`] as in PHP.
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
        match params.get(&Key::from_bytes(key.as_bytes())) {
            None => Ok(None),
            Some(Zval::String(value)) => Ok(Some(value)),
            Some(_) => {
                Err(Error::Type("Utopia\\DSN\\DSN::getParam(): Return value must be of type string, array returned"))
            }
        }
    }

    /// `getParam($key, $default)`: the parameter, or `default` when it is absent.
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

/// PHP `empty()` on a string: `""` and `"0"`.
fn blank(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

impl fmt::Debug for Dsn {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Dsn")
            .field("scheme", &self.scheme)
            .field("user", &self.user.as_deref().map(String::from_utf8_lossy))
            .field("password", &self.password.as_ref().map(|_| "<hidden>"))
            .field("host", &self.host)
            .field("port", &self.port)
            .field("path", &self.path)
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
        assert_eq!(d.path(), "database");
        assert_eq!(d.param("timezone").unwrap(), Some(&b"UTC"[..]));
        assert_eq!(d.param_or("region", b"us-east-1").unwrap(), b"us-east-1");
        let d = Dsn::parse("sms://user:sl%2Fsh%2B%24%40no%3Aher@localhost").unwrap();
        assert_eq!(d.password(), Some(&b"sl/sh+$@no:her"[..]));
        assert_eq!(d.path(), "");
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
