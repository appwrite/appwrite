//! The free and disposable domain lists an address's domain is classified
//! with.
//!
//! PHP: `data/free-domains.php` and `data/disposable-domains.php`.

use std::collections::HashSet;
use std::sync::OnceLock;

use php_std::value;
use serde_json::Value;

/// A set of domains, matched as PHP's `in_array($domain, $list)`: a loose
/// comparison, so numeric strings compare as numbers (`"1e3"` matches an
/// entry `"1000"`).
#[derive(Debug, Default)]
pub struct DomainList {
    exact: HashSet<Box<[u8]>>,
    /// Entries that are numeric strings, which a numeric needle can equal
    /// without being byte-identical.
    numeric: Vec<String>,
}

impl DomainList {
    /// Whether `domain` is on the list.
    ///
    /// PHP: `in_array($domain, $list)`.
    pub fn contains(&self, domain: &[u8]) -> bool {
        if self.exact.contains(domain) {
            return true;
        }
        if self.numeric.is_empty() || value::numeric_bytes_ex(domain).is_none() {
            return false;
        }
        // A numeric string is ASCII.
        let needle = Value::String(String::from_utf8_lossy(domain).into_owned());
        self.numeric.iter().any(|entry| value::loose_eq(&needle, &Value::String(entry.clone())))
    }

    /// The number of entries.
    pub fn len(&self) -> usize {
        self.exact.len()
    }

    /// Whether the list has no entries.
    pub fn is_empty(&self) -> bool {
        self.exact.is_empty()
    }
}

impl<'a> FromIterator<&'a str> for DomainList {
    fn from_iter<I: IntoIterator<Item = &'a str>>(iter: I) -> Self {
        let mut list = DomainList::default();
        for domain in iter {
            if value::numeric_str(domain).is_some() {
                list.numeric.push(domain.to_owned());
            }
            list.exact.insert(domain.as_bytes().into());
        }
        list
    }
}

/// The free and disposable domain lists.
#[derive(Debug, Default)]
pub struct Lists {
    /// Domains of free mail services (`gmail.com`).
    pub free: DomainList,
    /// Domains of disposable, throwaway mail services.
    pub disposable: DomainList,
}

static GLOBAL: OnceLock<Lists> = OnceLock::new();

impl Lists {
    /// The free domains the crate embeds: one per line, in the order of
    /// `data/free-domains.php`, which `data/export.php` writes them from.
    pub const FREE: &'static str = include_str!("../data/free-domains.txt");

    /// The disposable domains the crate embeds, from
    /// `data/disposable-domains.php`, in the format of [`Self::FREE`].
    pub const DISPOSABLE: &'static str = include_str!("../data/disposable-domains.txt");

    /// One list: a domain per line.
    pub fn parse(source: &str) -> DomainList {
        source.lines().collect()
    }

    /// The embedded lists, parsed.
    pub fn embedded() -> Self {
        Self { free: Self::parse(Self::FREE), disposable: Self::parse(Self::DISPOSABLE) }
    }

    /// The embedded lists, parsed on first use.
    pub fn global() -> &'static Self {
        GLOBAL.get_or_init(Self::embedded)
    }

    /// Whether the domain, or else its registrable domain (empty when it has
    /// none), is disposable.
    ///
    /// PHP: `isDisposable()`, given `getRegisterable()` of the domain.
    pub fn is_disposable(&self, domain: &[u8], registerable: &[u8]) -> bool {
        self.disposable.contains(domain) || (!registerable.is_empty() && self.disposable.contains(registerable))
    }

    /// Whether the domain is on the free list.
    ///
    /// PHP: `in_array($this->domain, self::$freeDomains)`; `isFree()` is this
    /// and not [`Self::is_disposable`].
    pub fn is_free(&self, domain: &[u8]) -> bool {
        self.free.contains(domain)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse() {
        let list = Lists::parse("gmail.com\nit's.com\n1000\n");
        assert_eq!(list.len(), 3);
        assert!(list.contains(b"gmail.com") && list.contains(b"it's.com"));
        assert!(list.contains(b"1e3") && list.contains(b" 1000") && !list.contains(b"1e4"));
        assert!(!list.contains(b"GMAIL.COM"));
    }

    #[test]
    fn embedded_lists_parse_whole() {
        let lists = Lists::embedded();
        for (list, source) in [(&lists.free, Lists::FREE), (&lists.disposable, Lists::DISPOSABLE)] {
            let lines = source.lines().count();
            assert!(source.ends_with('\n') && !source.lines().any(str::is_empty));
            assert_eq!(list.len(), lines, "every line is one distinct domain");
            assert!(lines > 30_000, "the list has {lines} domains");
        }
        assert!(lists.is_free(b"gmail.com") && lists.is_disposable(b"x.mailinator.com", b"mailinator.com"));
    }
}
