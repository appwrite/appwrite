//! `scheme://user:password@host:port/path?query` parsing (`Utopia\DSN\DSN`).

use std::collections::HashMap;

/// A parsed DSN.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Dsn {
    pub scheme: String,
    pub user: Option<String>,
    pub password: Option<String>,
    pub host: String,
    pub port: Option<u16>,
    pub path: Option<String>,
    pub query: Option<String>,
}

impl Dsn {
    /// Parses a DSN; returns `None` when there is no `scheme://`.
    pub fn parse(input: &str) -> Option<Self> {
        let (scheme, rest) = input.split_once("://")?;
        if scheme.is_empty() {
            return None;
        }
        let (rest, query) = match rest.split_once('?') {
            Some((r, q)) => (r, Some(q.to_owned())),
            None => (rest, None),
        };
        let (authority, path) = match rest.find('/') {
            Some(i) => (&rest[..i], Some(rest[i + 1..].to_owned()).filter(|p| !p.is_empty())),
            None => (rest, None),
        };
        let (userinfo, hostport) = match authority.rfind('@') {
            Some(i) => (Some(&authority[..i]), &authority[i + 1..]),
            None => (None, authority),
        };
        let (user, password) = match userinfo {
            Some(u) => match u.split_once(':') {
                Some((a, b)) => (Some(decode(a)), Some(decode(b))),
                None => (Some(decode(u)), None),
            },
            None => (None, None),
        };
        let (host, port) = match hostport.rsplit_once(':') {
            Some((h, p)) if p.chars().all(|c| c.is_ascii_digit()) && !p.is_empty() => (h.to_owned(), p.parse().ok()),
            _ => (hostport.to_owned(), None),
        };
        Some(Self { scheme: scheme.to_owned(), user, password, host, port, path, query })
    }

    /// A query parameter.
    pub fn param(&self, key: &str) -> Option<String> {
        let q = self.query.as_deref()?;
        form_urlencoded::parse(q.as_bytes()).find(|(k, _)| k == key).map(|(_, v)| v.into_owned())
    }

    pub fn params(&self) -> HashMap<String, String> {
        self.query
            .as_deref()
            .map(|q| form_urlencoded::parse(q.as_bytes()).map(|(k, v)| (k.into_owned(), v.into_owned())).collect())
            .unwrap_or_default()
    }
}

fn decode(s: &str) -> String {
    form_urlencoded::parse(format!("x={s}").as_bytes()).next().map(|(_, v)| v.into_owned()).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses() {
        let d = Dsn::parse("appwrite://database_db_main?database=appwrite&namespace=ns").unwrap();
        assert_eq!(d.host, "database_db_main");
        assert_eq!(d.param("namespace").as_deref(), Some("ns"));
        let d = Dsn::parse("postgresql://user:p%40ss@postgresql:5432/appwrite").unwrap();
        assert_eq!(d.password.as_deref(), Some("p@ss"));
        assert_eq!(d.port, Some(5432));
        assert_eq!(d.path.as_deref(), Some("appwrite"));
        assert!(Dsn::parse("database_db_main").is_none());
    }
}
