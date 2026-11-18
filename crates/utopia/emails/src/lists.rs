//! The free and disposable domain lists an address's domain is classified
//! with.
//!
//! PHP: `data/free-domains.php` and `data/disposable-domains.php`.
//!
//! The embedded lists are the `data/*.txt` text itself plus, built by
//! `build.rs`, a hash table of their lines' byte offsets (`table.rs`): a lookup
//! probes one or two slots, with nothing allocated or built at run time. Both
//! live in the library's read-only pages, shared by every process.

use std::borrow::Cow;
use std::sync::OnceLock;

use php_std::value;
use serde_json::Value;

mod table {
    include!("table.rs");
}

include!(concat!(env!("OUT_DIR"), "/tables.rs"));

/// A set of domains, matched as PHP's `in_array($domain, $list)`: a loose
/// comparison, so numeric strings compare as numbers (`"1e3"` matches an
/// entry `"1000"`).
#[derive(Debug)]
pub struct DomainList {
    /// The domains, one per line.
    source: Cow<'static, [u8]>,
    /// The hash table of `source`'s distinct lines (`table.rs`).
    table: Cow<'static, [u32]>,
    /// The number of distinct domains.
    entries: usize,
    /// The entries that are numeric strings, which a numeric needle can equal
    /// without being byte-identical; found on the first numeric needle.
    numeric: OnceLock<Vec<String>>,
}

impl Default for DomainList {
    fn default() -> Self {
        Self::from_source(Vec::new())
    }
}

impl DomainList {
    /// An embedded list and its build-time table.
    const fn embedded(source: &'static str, table: &'static [u32], entries: usize) -> Self {
        Self {
            source: Cow::Borrowed(source.as_bytes()),
            table: Cow::Borrowed(table),
            entries,
            numeric: OnceLock::new(),
        }
    }

    /// A list from text, one domain per line, with its table built now.
    fn from_source(source: Vec<u8>) -> Self {
        let (table, entries) = table::build(&source);
        Self { source: Cow::Owned(source), table: Cow::Owned(table), entries, numeric: OnceLock::new() }
    }

    /// Whether `domain` is on the list.
    ///
    /// PHP: `in_array($domain, $list)`.
    pub fn contains(&self, domain: &[u8]) -> bool {
        if table::contains(&self.source, &self.table, domain) {
            return true;
        }
        if value::numeric_bytes_ex(domain).is_none() {
            return false;
        }
        let numeric = self.numeric.get_or_init(|| {
            self.source
                .split(|&b| b == b'\n')
                .filter_map(|entry| std::str::from_utf8(entry).ok())
                .filter(|entry| value::numeric_str(entry).is_some())
                .map(str::to_owned)
                .collect()
        });
        if numeric.is_empty() {
            return false;
        }
        // A numeric string is ASCII.
        let needle = Value::String(String::from_utf8_lossy(domain).into_owned());
        numeric.iter().any(|entry| value::loose_eq(&needle, &Value::String(entry.clone())))
    }

    /// The number of entries.
    pub fn len(&self) -> usize {
        self.entries
    }

    /// Whether the list has no entries.
    pub fn is_empty(&self) -> bool {
        self.entries == 0
    }
}

impl<'a> FromIterator<&'a str> for DomainList {
    fn from_iter<I: IntoIterator<Item = &'a str>>(iter: I) -> Self {
        let mut source = Vec::new();
        for domain in iter {
            source.extend_from_slice(domain.as_bytes());
            source.push(b'\n');
        }
        Self::from_source(source)
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

static GLOBAL: Lists = Lists::embedded();

impl Lists {
    /// The free domains the crate embeds: one per line, in the order of
    /// `data/free-domains.php`, which `data/export.php` writes them from.
    pub const FREE: &'static str = include_str!("../data/free-domains.txt");

    /// The disposable domains the crate embeds, from
    /// `data/disposable-domains.php`, in the format of [`Self::FREE`].
    pub const DISPOSABLE: &'static str = include_str!("../data/disposable-domains.txt");

    /// One list: a domain per line.
    pub fn parse(source: &str) -> DomainList {
        DomainList::from_source(source.as_bytes().to_vec())
    }

    /// The embedded lists, with tables built at compile time: no work and no
    /// allocation.
    pub const fn embedded() -> Self {
        Self {
            free: DomainList::embedded(Self::FREE, &FREE_TABLE, FREE_ENTRIES),
            disposable: DomainList::embedded(Self::DISPOSABLE, &DISPOSABLE_TABLE, DISPOSABLE_ENTRIES),
        }
    }

    /// The embedded lists, shared by every caller.
    pub fn global() -> &'static Self {
        &GLOBAL
    }

    /// Whether the domain, or else its registrable domain (empty when it has
    /// none), is disposable.
    ///
    /// PHP: `isDisposable()`, given `getRegisterable()` of the domain.
    pub fn is_disposable(&self, domain: &[u8], registerable: &[u8]) -> bool {
        self.disposable.contains(domain) || (!registerable.is_empty() && self.disposable.contains(registerable))
    }

    /// Whether the domain is on the free list. Not PHP's `isFree()`, which is false
    /// for a domain that is also disposable: that is this and not
    /// [`Self::is_disposable`].
    ///
    /// PHP: `in_array($this->domain, self::$freeDomains)`.
    pub fn is_on_free_list(&self, domain: &[u8]) -> bool {
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
        assert!(lists.is_on_free_list(b"gmail.com") && lists.is_disposable(b"x.mailinator.com", b"mailinator.com"));
    }

    /// The build-time table finds every line of each embedded list, and only those.
    #[test]
    fn embedded_table_finds_every_entry() {
        let lists = Lists::global();
        for (list, source) in [(&lists.free, Lists::FREE), (&lists.disposable, Lists::DISPOSABLE)] {
            for domain in source.lines() {
                assert!(list.contains(domain.as_bytes()), "{domain} is on its list");
                assert!(
                    !list.contains(format!("{domain}x").as_bytes())
                        || source.lines().any(|l| l == format!("{domain}x"))
                );
                assert!(
                    !list.contains(format!("x{domain}").as_bytes())
                        || source.lines().any(|l| l == format!("x{domain}"))
                );
            }
            assert!(!list.contains(b"") && !list.contains(b"\n") && !list.contains(b"not-a-listed-domain.invalid"));
        }
    }

    /// The build-time table is the one a run-time parse builds.
    #[test]
    fn embedded_table_matches_a_parse() {
        let lists = Lists::global();
        for (list, source) in [(&lists.free, Lists::FREE), (&lists.disposable, Lists::DISPOSABLE)] {
            let parsed = Lists::parse(source);
            assert_eq!(list.table.as_ref(), parsed.table.as_ref());
            assert_eq!(list.len(), parsed.len());
        }
    }
}
