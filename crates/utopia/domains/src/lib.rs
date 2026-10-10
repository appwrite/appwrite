//! Domain names against the Public Suffix List, with the semantics of
//! `utopia-php/domains`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Domains\Domain` | [`Domain`] |
//! | `new Domain($domain)` | [`Domain::new`] (the global list) or [`Domain::with_list`] |
//! | `get()`, `getApex()`, `getTLD()`, `getSuffix()`, `getRule()` | [`Domain::get`], [`Domain::apex`], [`Domain::tld`], [`Domain::suffix`], [`Domain::rule`] |
//! | `getRegisterable()`, `getName()`, `getSub()` | [`Domain::registerable`], [`Domain::name`], [`Domain::sub`] |
//! | `isKnown()`, `isICANN()`, `isPrivate()`, `isTest()` | [`Domain::is_known`], [`Domain::is_icann`], [`Domain::is_private`], [`Domain::is_test`] |
//! | `data/data.php` (the list `Domain` includes) | [`PublicSuffixList`] |
//! | `Exception` thrown by the constructor | [`Error::Domain`] |
//!
//! The list is the data file shipped with the PHP package
//! (`packages/domains/data/data.php`), parsed once. PHP includes it from the
//! package directory; Rust reads it from there too unless a list is
//! installed with [`PublicSuffixList::install`] (a server ships the file
//! with its assets).
//!
//! Only `Domain` is converted so far: the registrar adapters, the cache and
//! the `Validator\*` classes of `utopia-php/domains` are not (crates/CONVERSION.md).

mod literal;

use std::borrow::Cow;
use std::collections::{HashMap, HashSet};
use std::path::{Path, PathBuf};
use std::sync::OnceLock;

use php_std::mb;

/// Errors, one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `Exception`: `new Domain()` was given a URL (`http://`, `https://`).
    #[error("{0}")]
    Domain(String),
    /// The list could not be read or is not a PHP array literal (PHP fails
    /// with a warning and a `TypeError` on the first lookup).
    #[error("{0}")]
    Data(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Domain(_) => "Exception",
            Error::Data(_) => "TypeError",
        }
    }
}

/// The section of the Public Suffix List a rule comes from (`type`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Section {
    /// `ICANN` domains.
    Icann,
    /// `PRIVATE` domains.
    Private,
    /// A rule without a known section.
    Other,
}

/// The Public Suffix List: every rule as written (`com`, `*.ck`, `!www.ck`)
/// with its section, plus the exception and wildcard rules indexed without
/// their `!` and `*.` prefixes.
#[derive(Debug, Default)]
pub struct PublicSuffixList {
    rules: HashMap<Box<str>, Section>,
    exceptions: HashSet<Box<str>>,
    wildcards: HashSet<Box<str>>,
}

static GLOBAL: OnceLock<PublicSuffixList> = OnceLock::new();

impl PublicSuffixList {
    /// The list as PHP includes it: `data/data.php` of the PHP package in
    /// this repository.
    pub fn default_path() -> PathBuf {
        Path::new(env!("CARGO_MANIFEST_DIR")).join("../../../packages/domains/data/data.php")
    }

    /// Parses `data.php`: `return ['<rule>' => ['suffix' => ..., 'type' => 'ICANN' | 'PRIVATE', 'comments' => [...]], ...];`.
    pub fn parse(source: &str) -> Result<Self, Error> {
        let entries = literal::parse(source).map_err(Error::Data)?;
        let mut list = PublicSuffixList::default();
        for (key, value) in entries.into_map().ok_or_else(|| Error::Data("the list is not an array".into()))? {
            let section = match value.get("type").and_then(literal::Literal::as_str) {
                Some("ICANN") => Section::Icann,
                Some("PRIVATE") => Section::Private,
                _ => Section::Other,
            };
            if let Some(rest) = key.strip_prefix('!') {
                list.exceptions.insert(rest.into());
            }
            if let Some(rest) = key.strip_prefix("*.") {
                list.wildcards.insert(rest.into());
            }
            list.rules.insert(key.into_boxed_str(), section);
        }
        Ok(list)
    }

    /// Reads and parses a `data.php` file.
    pub fn load(path: &Path) -> Result<Self, Error> {
        let source = std::fs::read_to_string(path).map_err(|e| Error::Data(format!("{}: {e}", path.display())))?;
        Self::parse(&source)
    }

    /// Installs the process-wide list used by [`Domain::new`]. The first list
    /// installed (or loaded) wins: returns whether this one was installed.
    pub fn install(self) -> bool {
        GLOBAL.set(self).is_ok()
    }

    /// The process-wide list: the installed one, else [`Self::default_path`]
    /// (empty when that file cannot be read).
    pub fn global() -> &'static Self {
        GLOBAL.get_or_init(|| Self::load(&Self::default_path()).unwrap_or_default())
    }

    /// Number of rules.
    pub fn len(&self) -> usize {
        self.rules.len()
    }

    pub fn is_empty(&self) -> bool {
        self.rules.is_empty()
    }

    /// The section of a rule, when the list has it (`array_key_exists`).
    pub fn section(&self, rule: &str) -> Option<Section> {
        self.rules.get(rule).copied()
    }
}

/// Which rule matched, borrowing from the domain.
#[derive(Debug, Clone, Copy)]
enum Rule<'d> {
    /// No rule: the rule is `''`.
    None,
    /// `!<joined>`.
    Exception(&'d str),
    /// `<joined>`.
    Exact(&'d str),
    /// `*.<next>`.
    Wildcard(&'d str),
}

/// A domain name, lower-cased, split against a [`PublicSuffixList`]
/// (`Utopia\Domains\Domain`).
#[derive(Debug, Clone)]
pub struct Domain<'l> {
    domain: String,
    list: Option<&'l PublicSuffixList>,
}

/// PHP `empty()` of a string.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// `mb_substr($s, 0, -$cut)`.
fn cut_end(s: &str, cut: usize) -> String {
    let cut = i64::try_from(cut).unwrap_or(i64::MAX);
    let bytes = mb::mb_substr(s.as_bytes(), 0, Some(-cut)).unwrap_or_default();
    String::from_utf8(bytes).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

impl Domain<'static> {
    /// `new Domain($domain)` on the process-wide list ([`PublicSuffixList::global`],
    /// read on first use).
    pub fn new(domain: &str) -> Result<Self, Error> {
        Self::build(domain, None)
    }
}

impl<'l> Domain<'l> {
    /// `new Domain($domain)` on a given list.
    pub fn with_list(domain: &str, list: &'l PublicSuffixList) -> Result<Self, Error> {
        Self::build(domain, Some(list))
    }

    fn build(domain: &str, list: Option<&'l PublicSuffixList>) -> Result<Self, Error> {
        if domain.starts_with("http://") || domain.starts_with("https://") {
            return Err(Error::Domain(format!("'{domain}' must be a valid domain or hostname")));
        }
        let lower = mb::mb_strtolower(domain.as_bytes());
        let domain = String::from_utf8(lower).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned());
        Ok(Self { domain, list })
    }

    fn list(&self) -> &'l PublicSuffixList {
        match self.list {
            Some(list) => list,
            None => PublicSuffixList::global(),
        }
    }

    /// `getSuffix()`'s search: for each label from the left, an exception
    /// rule `!<rest>`, then the rule `<rest>`, then a wildcard `*.<after>`.
    fn find(&self) -> (&str, Rule<'_>) {
        let d = self.domain.as_str();
        let list = self.list();
        let mut start = 0;
        loop {
            let joined = &d[start..];
            let next_start = joined.find('.').map(|p| start + p + 1);
            let next = next_start.map_or("", |n| &d[n..]);
            if list.exceptions.contains(joined) {
                return (next, Rule::Exception(joined));
            }
            if list.rules.contains_key(joined) {
                return (joined, Rule::Exact(joined));
            }
            if list.wildcards.contains(next) {
                return (joined, Rule::Wildcard(next));
            }
            match next_start {
                Some(n) => start = n,
                None => return ("", Rule::None),
            }
        }
    }

    /// `get()`: the lower-cased domain.
    pub fn get(&self) -> &str {
        &self.domain
    }

    /// `getTLD()`: the last label.
    pub fn tld(&self) -> &str {
        self.domain.rsplit('.').next().unwrap_or("")
    }

    /// `getSuffix()`: the public suffix, `''` when no rule matches.
    pub fn suffix(&self) -> &str {
        self.find().0
    }

    /// `getRule()`: the rule that matched (`com`, `*.ck`, `!www.ck`), `''` when none did.
    pub fn rule(&self) -> Cow<'_, str> {
        match self.find().1 {
            Rule::None => Cow::Borrowed(""),
            Rule::Exception(joined) => Cow::Owned(format!("!{joined}")),
            Rule::Exact(joined) => Cow::Borrowed(joined),
            Rule::Wildcard(next) => Cow::Owned(format!("*.{next}")),
        }
    }

    /// `mb_strlen('.' . suffix)`, the suffix being the TLD when no rule matched.
    fn suffix_len(&self) -> usize {
        let suffix = self.suffix();
        let suffix = if empty(suffix) { self.tld() } else { suffix };
        1 + mb::mb_strlen(suffix.as_bytes())
    }

    /// `getName()`: the label before the suffix.
    pub fn name(&self) -> String {
        let mut rest = cut_end(&self.domain, self.suffix_len());
        match rest.rfind('.') {
            Some(p) => rest.split_off(p + 1),
            None => rest,
        }
    }

    /// `getSub()`: what comes before the name.
    pub fn sub(&self) -> String {
        let name = self.name();
        let name_len = if empty(&name) { 0 } else { 1 + mb::mb_strlen(name.as_bytes()) };
        cut_end(&self.domain, name_len + self.suffix_len())
    }

    /// `getApex()`: `name.suffix`.
    pub fn apex(&self) -> String {
        format!("{}.{}", self.name(), self.suffix())
    }

    /// `getRegisterable()`: `name.suffix` when the suffix is known, else `''`.
    pub fn registerable(&self) -> String {
        if !self.is_known() {
            return String::new();
        }
        self.apex()
    }

    /// `isKnown()`: the rule is in the list.
    pub fn is_known(&self) -> bool {
        self.list().rules.contains_key(&*self.rule())
    }

    /// `isICANN()`: the rule is in the ICANN section.
    pub fn is_icann(&self) -> bool {
        self.list().section(&self.rule()) == Some(Section::Icann)
    }

    /// `isPrivate()`: the rule is in the PRIVATE section.
    pub fn is_private(&self) -> bool {
        self.list().section(&self.rule()) == Some(Section::Private)
    }

    /// `isTest()`: the TLD is reserved for testing (`test`, `localhost`).
    pub fn is_test(&self) -> bool {
        matches!(self.tld(), "test" | "localhost")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn list() -> PublicSuffixList {
        PublicSuffixList::parse(
            "<?php\n\nreturn [\n\t'com' => [\n\t\t'suffix' => 'com',\n\t\t'type' => 'ICANN',\n\t\t'comments' => [\n\t\t\t'it\\'s',\n\t\t],\n\t],\n\
             \t'co.uk' => ['suffix' => 'co.uk', 'type' => 'ICANN', 'comments' => []],\n\
             \t'*.ck' => ['suffix' => '*.ck', 'type' => 'ICANN', 'comments' => []],\n\
             \t'!www.ck' => ['suffix' => '!www.ck', 'type' => 'ICANN', 'comments' => []],\n\
             \t'github.io' => ['suffix' => 'github.io', 'type' => 'PRIVATE', 'comments' => []],\n];\n",
        )
        .unwrap()
    }

    #[test]
    fn parts() {
        let list = list();
        let d = Domain::with_list("Mail.Sub.Example.CO.UK", &list).unwrap();
        assert_eq!(d.get(), "mail.sub.example.co.uk");
        assert_eq!((d.suffix(), &*d.rule(), d.tld()), ("co.uk", "co.uk", "uk"));
        assert_eq!(
            (d.name(), d.sub(), d.registerable()),
            ("example".into(), "mail.sub".into(), "example.co.uk".into())
        );
        assert!(d.is_known() && d.is_icann() && !d.is_private());

        let d = Domain::with_list("a.b.ck", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name()), ("b.ck", "*.ck", "a".into()));
        let d = Domain::with_list("www.ck", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name()), ("ck", "!www.ck", "www".into()));
        let d = Domain::with_list("me.github.io", &list).unwrap();
        assert!(d.is_private());

        let d = Domain::with_list("example.test", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name(), d.sub()), ("", "", "example".into(), String::new()));
        assert!(!d.is_known() && d.is_test());
        assert_eq!(d.registerable(), "");

        let d = Domain::with_list("x.0.com", &list).unwrap();
        assert_eq!((d.name(), d.sub()), ("0".into(), "x.0".into()));
    }

    #[test]
    fn urls() {
        let e = Domain::new("http://example.com").unwrap_err();
        assert_eq!(e.to_string(), "'http://example.com' must be a valid domain or hostname");
        assert_eq!(e.php_class(), "Exception");
    }

    #[test]
    fn data_file() {
        let list = PublicSuffixList::load(&PublicSuffixList::default_path()).unwrap();
        assert!(list.len() > 9000);
        assert_eq!(list.section("com"), Some(Section::Icann));
    }
}
