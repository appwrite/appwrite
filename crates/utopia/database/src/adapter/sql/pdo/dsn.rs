//! PHP DSN strings: which driver, and its connection settings.
//!
//! PHP: `PDO::__construct()`'s DSN handling, `pdo_pgsql`'s conninfo,
//! `php_pdo_parse_data_source()` (`pdo_mysql`), `pdo_sqlite`'s file name,
//! and `Utopia\Database\PDO::parseDsn()` for [`hostname`].

use super::{Driver, PdoError};
use crate::error::{Error, Kind, Result};

/// PostgreSQL settings (libpq conninfo keywords).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct Postgres {
    pub(crate) host: Option<String>,
    pub(crate) port: Option<String>,
    pub(crate) dbname: Option<String>,
    pub(crate) user: Option<String>,
    pub(crate) password: Option<String>,
}

/// MySQL settings (`host`, `port`, `dbname`, `charset`).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct Mysql {
    pub(crate) host: Option<String>,
    pub(crate) port: Option<String>,
    pub(crate) dbname: Option<String>,
    pub(crate) charset: Option<String>,
}

/// What a DSN connects to.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) enum Target {
    #[cfg(feature = "postgres")]
    Postgres(Postgres),
    #[cfg(feature = "mariadb")]
    Mysql(Mysql),
    #[cfg(feature = "sqlite")]
    Sqlite(String),
}

impl Target {
    pub(crate) fn driver(&self) -> Driver {
        match self {
            #[cfg(feature = "postgres")]
            Target::Postgres(_) => Driver::Postgres,
            #[cfg(feature = "mariadb")]
            Target::Mysql(_) => Driver::Mysql,
            #[cfg(feature = "sqlite")]
            Target::Sqlite(_) => Driver::Sqlite,
        }
    }
}

/// Reads a DSN as `new PDO($dsn)` does: `could not find driver` for a
/// driver this build lacks.
pub(crate) fn parse(dsn: &str) -> Result<Target> {
    let Some((driver, rest)) = dsn.split_once(':') else {
        return Err(
            PdoError::new("PDO::__construct(): Argument #1 ($dsn) must be a valid data source name").into_error()
        );
    };
    let _ = rest;
    match driver {
        #[cfg(feature = "postgres")]
        "pgsql" => Ok(Target::Postgres(conninfo(rest))),
        #[cfg(feature = "mariadb")]
        "mysql" => Ok(Target::Mysql(data_source(rest))),
        #[cfg(feature = "sqlite")]
        "sqlite" => Ok(Target::Sqlite(rest.to_owned())),
        _ => Err(PdoError::new("could not find driver").into_error()),
    }
}

/// libpq's conninfo, after `pdo_pgsql` turned every `;` into a space:
/// `key = value` pairs separated by whitespace, values optionally in single
/// quotes with backslash escapes. A later keyword overrides an earlier one.
#[cfg_attr(not(feature = "postgres"), allow(dead_code))]
pub(crate) fn conninfo(s: &str) -> Postgres {
    let s: Vec<char> = s.chars().map(|c| if c == ';' { ' ' } else { c }).collect();
    let mut out = Postgres::default();
    let mut i = 0;
    while i < s.len() {
        while i < s.len() && s[i].is_whitespace() {
            i += 1;
        }
        let start = i;
        while i < s.len() && s[i] != '=' && !s[i].is_whitespace() {
            i += 1;
        }
        let key: String = s[start..i].iter().collect();
        while i < s.len() && s[i].is_whitespace() {
            i += 1;
        }
        if i >= s.len() || s[i] != '=' {
            continue;
        }
        i += 1;
        while i < s.len() && s[i].is_whitespace() {
            i += 1;
        }
        let mut value = String::new();
        if i < s.len() && s[i] == '\'' {
            i += 1;
            while i < s.len() && s[i] != '\'' {
                if s[i] == '\\' && i + 1 < s.len() {
                    i += 1;
                }
                value.push(s[i]);
                i += 1;
            }
            i += 1;
        } else {
            while i < s.len() && !s[i].is_whitespace() {
                if s[i] == '\\' && i + 1 < s.len() {
                    i += 1;
                }
                value.push(s[i]);
                i += 1;
            }
        }
        match key.as_str() {
            "host" | "hostaddr" => out.host = Some(value),
            "port" => out.port = Some(value),
            "dbname" => out.dbname = Some(value),
            "user" => out.user = Some(value),
            "password" => out.password = Some(value),
            _ => {}
        }
    }
    out
}

/// `php_pdo_parse_data_source()`: `name=value` pairs separated by `;`
/// (`;;` is a literal `;` in a value); names are matched exactly.
#[cfg_attr(not(feature = "mariadb"), allow(dead_code))]
pub(crate) fn data_source(s: &str) -> Mysql {
    let b = s.as_bytes();
    let mut out = Mysql::default();
    let mut i = 0;
    let mut optstart = 0;
    while i < b.len() {
        if b[i] == 0 {
            break;
        }
        if b[i] != b'=' {
            i += 1;
            continue;
        }
        i += 1;
        let valstart = i;
        let mut value = Vec::new();
        while i < b.len() {
            if b[i] == 0 {
                i += 1;
                break;
            }
            if b[i] == b';' {
                if i + 1 >= b.len() || b[i + 1] != b';' {
                    i += 1;
                    break;
                }
                value.push(b';');
                i += 2;
                continue;
            }
            value.push(b[i]);
            i += 1;
        }
        let name = &s[optstart..valstart - 1];
        let value = Some(String::from_utf8_lossy(&value).into_owned());
        match name {
            "host" => out.host = value,
            "port" => out.port = value,
            "dbname" => out.dbname = value,
            "charset" => out.charset = value,
            _ => {}
        }
        while i < b.len() && b[i].is_ascii_whitespace() {
            i += 1;
        }
        optstart = i;
    }
    out
}

/// `Utopia\Database\PDO::getHostname()`: the `host` of the DSN as
/// `parseDsn()` reads it (`;`-separated `name=value` pairs, trimmed; a
/// SQLite DSN has a path and no host).
pub(crate) fn hostname(dsn: &str) -> Result<String> {
    if dsn.is_empty() || !dsn.contains(':') {
        return Err(Error::new(Kind::InvalidArgument, "Malformed DSN: missing driver separator."));
    }
    let (driver, parameters) = dsn.split_once(':').unwrap_or((dsn, ""));
    let no_host = || Error::new(Kind::Exception, "No host found in DSN");
    if driver == "sqlite" && !parameters.is_empty() {
        return Err(no_host());
    }
    let mut host = None;
    for segment in parameters.split(';').filter(|s| !s.is_empty() && *s != "0") {
        let (name, value) = match segment.split_once('=') {
            Some((n, v)) => (n, Some(v)),
            None => (segment, None),
        };
        if trim(name) == "host" {
            host = value.map(|v| trim(v).to_owned());
        }
    }
    host.ok_or_else(no_host)
}

/// PHP's `trim()` with its default characters.
fn trim(s: &str) -> &str {
    s.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_conninfo() {
        let c = conninfo("host=db;port=5433;dbname=app user='a b' password=x\\'y");
        assert_eq!(c.host.as_deref(), Some("db"));
        assert_eq!(c.port.as_deref(), Some("5433"));
        assert_eq!(c.dbname.as_deref(), Some("app"));
        assert_eq!(c.user.as_deref(), Some("a b"));
        assert_eq!(c.password.as_deref(), Some("x'y"));
    }

    #[test]
    fn reads_data_sources() {
        let m = data_source("host=db;port=3307;charset=utf8mb4;dbname=a;;b");
        assert_eq!(m.host.as_deref(), Some("db"));
        assert_eq!(m.port.as_deref(), Some("3307"));
        assert_eq!(m.charset.as_deref(), Some("utf8mb4"));
        assert_eq!(m.dbname.as_deref(), Some("a;b"));
    }

    #[test]
    fn finds_hosts() {
        assert_eq!(hostname("mysql:host=db ;port=1").unwrap(), "db");
        assert_eq!(hostname("sqlite::memory:").unwrap_err().message(), "No host found in DSN");
        assert_eq!(hostname("pgsql:port=1").unwrap_err().message(), "No host found in DSN");
        assert_eq!(hostname("nothing").unwrap_err().kind(), Kind::InvalidArgument);
    }
}
