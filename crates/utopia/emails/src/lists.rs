//! The free and disposable domain lists an address's domain is classified
//! with.
//!
//! PHP: `data/free-domains.php` and `data/disposable-domains.php`.

use std::collections::HashSet;
use std::path::Path;
use std::sync::OnceLock;

use php_std::value;
use serde_json::Value;

/// A set of domains.
///
/// Numeric strings compare as numbers, so `"1e3"` matches an entry `"1000"`.
///
/// PHP: matched like PHP's `in_array($domain, $list)`, a loose comparison.
#[derive(Debug, Default)]
pub struct DomainList {
    exact: HashSet<Box<str>>,
    /// Entries that are numeric strings, which a numeric needle can equal
    /// without being byte-identical.
    numeric: Vec<Box<str>>,
}

impl DomainList {
    /// Whether `domain` is on the list.
    ///
    /// PHP: `in_array($domain, $list)`.
    pub fn contains(&self, domain: &str) -> bool {
        if self.exact.contains(domain) {
            return true;
        }
        if self.numeric.is_empty() || value::numeric_str(domain).is_none() {
            return false;
        }
        let needle = Value::String(domain.to_owned());
        self.numeric.iter().any(|entry| value::loose_eq(&needle, &Value::String(entry.to_string())))
    }

    /// The number of domains on the list.
    pub fn len(&self) -> usize {
        self.exact.len()
    }

    /// Whether the list has no domains.
    pub fn is_empty(&self) -> bool {
        self.exact.is_empty()
    }
}

impl<S: Into<Box<str>>> FromIterator<S> for DomainList {
    /// A list of the given domains.
    fn from_iter<I: IntoIterator<Item = S>>(iter: I) -> Self {
        let mut list = DomainList::default();
        for domain in iter {
            let domain: Box<str> = domain.into();
            if value::numeric_str(&domain).is_some() {
                list.numeric.push(domain.clone());
            }
            list.exact.insert(domain);
        }
        list
    }
}

/// The free and disposable domain lists [`crate::Email`] classifies domains
/// with.
#[derive(Debug, Default)]
pub struct Lists {
    /// Domains of free mail services (`gmail.com`).
    pub free: DomainList,
    /// Domains of disposable, throwaway mail services.
    pub disposable: DomainList,
}

static GLOBAL: OnceLock<Lists> = OnceLock::new();

impl Lists {
    /// The free domains the crate embeds, `data/free-domains.txt`: one
    /// domain per line, written by the `emails-import` task.
    ///
    /// PHP: `data/free-domains.php`, the same domains in the same order.
    pub const FREE: &'static str = include_str!("../data/free-domains.txt");

    /// The disposable domains the crate embeds,
    /// `data/disposable-domains.txt`, in the format of [`Self::FREE`].
    ///
    /// PHP: `data/disposable-domains.php`.
    pub const DISPOSABLE: &'static str = include_str!("../data/disposable-domains.txt");

    /// Parses one list: a domain per line (empty lines are skipped).
    ///
    /// ```
    /// use utopia_emails::Lists;
    ///
    /// let list = Lists::parse("gmail.com\nyahoo.com\n");
    /// assert_eq!(list.len(), 2);
    /// assert!(list.contains("gmail.com"));
    /// ```
    pub fn parse(source: &str) -> DomainList {
        source.split('\n').filter(|line| !line.is_empty()).collect()
    }

    /// The embedded lists ([`Self::FREE`], [`Self::DISPOSABLE`]), parsed.
    pub fn embedded() -> Self {
        Self { free: Self::parse(Self::FREE), disposable: Self::parse(Self::DISPOSABLE) }
    }

    /// Reads both lists from `dir`, from `free-domains.txt` and
    /// `disposable-domains.txt` (the embedded lists' format).
    pub fn load(dir: &Path) -> Result<Self, String> {
        let read = |name: &str| -> Result<DomainList, String> {
            let path = dir.join(name);
            let source = std::fs::read_to_string(&path).map_err(|e| format!("{}: {e}", path.display()))?;
            Ok(Self::parse(&source))
        };
        Ok(Self { free: read("free-domains.txt")?, disposable: read("disposable-domains.txt")? })
    }

    /// Installs the process-wide lists. The first lists installed (or
    /// loaded) win: returns whether these were installed.
    pub fn install(self) -> bool {
        GLOBAL.set(self).is_ok()
    }

    /// The process-wide lists: the installed ones, else the embedded ones,
    /// parsed on first use.
    pub fn global() -> &'static Self {
        GLOBAL.get_or_init(Self::embedded)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse() {
        let list = Lists::parse("gmail.com\nit's.com\n\n1000\n");
        assert_eq!(list.len(), 3);
        assert!(list.contains("gmail.com") && list.contains("it's.com"));
        // in_array() compares numeric strings as numbers.
        assert!(list.contains("1e3") && list.contains(" 1000") && !list.contains("1e4"));
        assert!(!list.contains("GMAIL.COM"));
    }

    #[test]
    fn embedded() {
        let lists = Lists::embedded();
        assert!(lists.free.contains("gmail.com") && lists.disposable.contains("mailinator.com"));
        assert!(lists.disposable.len() > 10_000);
        assert_eq!(lists.free.len(), Lists::FREE.lines().count());
    }
}
