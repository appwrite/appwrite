//! Domain names split against the Public Suffix List into suffix,
//! registrable name and subdomain.

use std::borrow::Cow;
use std::collections::{HashMap, HashSet};
use std::path::Path;
use std::sync::OnceLock;

use php_std::mb;

use crate::Error;

/// The section of the Public Suffix List a rule comes from.
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
    /// The list the crate embeds, `data/public-suffix-list.txt`, written by
    /// the `domains-import` task (see the crate's guide).
    ///
    /// One line per entry: `<section>\t<rule>` for a rule (section `ICANN`,
    /// `PRIVATE`, or `-` outside both), after a `#\t<comment>` line for each
    /// comment the list gives the rule.
    ///
    /// PHP: `data/data.php`, the same rules, sections and comments in the
    /// same order, written by `data/import.php`.
    pub const EMBEDDED: &'static str = include_str!("../data/public-suffix-list.txt");

    /// Parses a list in the format of [`Self::EMBEDDED`].
    ///
    /// ```
    /// use utopia_domains::{PublicSuffixList, Section};
    ///
    /// let list = PublicSuffixList::parse("#\tcom : the commercial TLD\nICANN\tcom\nPRIVATE\tgithub.io\n")?;
    /// assert_eq!(list.len(), 2);
    /// assert_eq!(list.section("com"), Some(Section::Icann));
    /// assert_eq!(list.section("github.io"), Some(Section::Private));
    /// # Ok::<(), utopia_domains::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Domains\Domain;
    ///
    /// // The list ships with the package and is read on first use.
    /// var_dump((new Domain('example.com'))->isICANN()); // bool(true)
    /// ```
    pub fn parse(source: &str) -> Result<Self, Error> {
        let mut list = PublicSuffixList::default();
        for (n, line) in source.split('\n').enumerate() {
            if line.is_empty() {
                continue;
            }
            let Some((kind, rule)) = line.split_once('\t') else {
                return Err(Error::Data(format!("line {}: expected `<section>\\t<rule>`", n + 1)));
            };
            let section = match kind {
                "#" => continue,
                "ICANN" => Section::Icann,
                "PRIVATE" => Section::Private,
                "-" => Section::Other,
                other => return Err(Error::Data(format!("line {}: unknown section `{other}`", n + 1))),
            };
            if let Some(rest) = rule.strip_prefix('!') {
                list.exceptions.insert(rest.into());
            }
            if let Some(rest) = rule.strip_prefix("*.") {
                list.wildcards.insert(rest.into());
            }
            list.rules.insert(rule.into(), section);
        }
        Ok(list)
    }

    /// Reads and parses a list file in the format of [`Self::EMBEDDED`].
    pub fn load(path: &Path) -> Result<Self, Error> {
        let source = std::fs::read_to_string(path).map_err(|e| Error::Data(format!("{}: {e}", path.display())))?;
        Self::parse(&source)
    }

    /// The embedded list ([`Self::EMBEDDED`]), parsed.
    pub fn embedded() -> Self {
        Self::parse(Self::EMBEDDED).unwrap_or_default()
    }

    /// Installs the process-wide list used by [`Domain::new`]. The first list
    /// installed (or loaded) wins: returns whether this one was installed.
    pub fn install(self) -> bool {
        GLOBAL.set(self).is_ok()
    }

    /// The process-wide list: the installed one, else the embedded one,
    /// parsed on first use.
    pub fn global() -> &'static Self {
        GLOBAL.get_or_init(Self::embedded)
    }

    /// The number of rules.
    pub fn len(&self) -> usize {
        self.rules.len()
    }

    /// Whether the list has no rules.
    pub fn is_empty(&self) -> bool {
        self.rules.is_empty()
    }

    /// The section of a rule, when the list has it.
    pub fn section(&self, rule: &str) -> Option<Section> {
        self.rules.get(rule).copied()
    }
}

/// Which rule matched: where its text starts in the domain.
#[derive(Debug, Clone, Copy)]
enum Rule {
    /// No rule: the rule is `''`.
    None,
    /// `!<joined>`.
    Exception(usize),
    /// `<joined>`.
    Exact(usize),
    /// `*.<next>`.
    Wildcard(usize),
}

/// The parts of a domain, found once: byte offsets into it.
#[derive(Debug, Clone, Copy)]
struct Parts {
    /// Where the suffix starts (the domain's length for none).
    suffix: usize,
    rule: Rule,
    /// The registrable name.
    name: (usize, usize),
    /// Where the subdomain ends (it starts the domain).
    sub: usize,
}

/// A domain name, lower-cased, split against a [`PublicSuffixList`] into
/// its suffix, registrable name and subdomain.
///
/// The suffix search runs once, on the first getter that needs it; the
/// suffix, name and subdomain are then slices of the domain.
///
/// ```
/// use utopia_domains::Domain;
///
/// let domain = Domain::new("Blog.Example.CO.UK")?;
/// assert_eq!(domain.get(), "blog.example.co.uk");
/// assert_eq!(domain.suffix(), "co.uk");
/// assert_eq!(domain.name(), "example");
/// assert_eq!(domain.sub(), "blog");
/// assert_eq!(domain.registerable(), "example.co.uk");
/// # Ok::<(), utopia_domains::Error>(())
/// ```
///
/// ```php
/// use Utopia\Domains\Domain;
///
/// $domain = new Domain('Blog.Example.CO.UK');
/// echo $domain->get(), "\n";            // blog.example.co.uk
/// echo $domain->getSuffix(), "\n";      // co.uk
/// echo $domain->getName(), "\n";        // example
/// echo $domain->getSub(), "\n";         // blog
/// echo $domain->getRegisterable(), "\n"; // example.co.uk
/// ```
#[derive(Debug, Clone)]
pub struct Domain<'l> {
    domain: String,
    list: Option<&'l PublicSuffixList>,
    parts: OnceLock<Parts>,
}

/// Whether a string is empty or `"0"`.
///
/// PHP: `empty()` of a string.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// Where `s` ends without its last `cut` characters (`cut` > 0).
///
/// PHP: the length of `mb_substr($s, 0, -$cut)`, which is empty when `cut`
/// reaches the length. The domain is UTF-8, so characters are `char`s.
fn cut_end(s: &str, cut: usize) -> usize {
    s.char_indices().rev().nth(cut - 1).map_or(0, |(at, _)| at)
}

impl Domain<'static> {
    /// Parses a domain against the process-wide list
    /// ([`PublicSuffixList::global`], read on first use).
    ///
    /// Fails for a URL (`http://`, `https://`).
    ///
    /// ```
    /// use utopia_domains::Domain;
    ///
    /// let error = Domain::new("https://example.com").unwrap_err();
    /// assert_eq!(error.to_string(), "'https://example.com' must be a valid domain or hostname");
    /// ```
    ///
    /// ```php
    /// use Utopia\Domains\Domain;
    ///
    /// try {
    ///     new Domain('https://example.com');
    /// } catch (\Exception $e) {
    ///     echo $e->getMessage(), "\n"; // 'https://example.com' must be a valid domain or hostname
    /// }
    /// ```
    pub fn new(domain: &str) -> Result<Self, Error> {
        Self::build(domain, None)
    }
}

impl<'l> Domain<'l> {
    /// Parses a domain against a given list.
    ///
    /// Fails for a URL (`http://`, `https://`).
    pub fn with_list(domain: &str, list: &'l PublicSuffixList) -> Result<Self, Error> {
        Self::build(domain, Some(list))
    }

    fn build(domain: &str, list: Option<&'l PublicSuffixList>) -> Result<Self, Error> {
        if domain.starts_with("http://") || domain.starts_with("https://") {
            return Err(Error::exception(format!("'{domain}' must be a valid domain or hostname"), 0));
        }
        let lower = mb::mb_strtolower(domain.as_bytes());
        let domain = String::from_utf8(lower).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned());
        Ok(Self { domain, list, parts: OnceLock::new() })
    }

    fn list(&self) -> &'l PublicSuffixList {
        match self.list {
            Some(list) => list,
            None => PublicSuffixList::global(),
        }
    }

    /// The suffix search: for each label from the left, an exception rule
    /// `!<rest>`, then the rule `<rest>`, then a wildcard `*.<after>`.
    /// Returns where the suffix starts, and the rule.
    fn find(&self) -> (usize, Rule) {
        let d = self.domain.as_str();
        let list = self.list();
        let mut start = 0;
        loop {
            let joined = &d[start..];
            let next_start = joined.find('.').map(|p| start + p + 1);
            let next = next_start.unwrap_or(d.len());
            if list.exceptions.contains(joined) {
                return (next, Rule::Exception(start));
            }
            if list.rules.contains_key(joined) {
                return (start, Rule::Exact(start));
            }
            if list.wildcards.contains(&d[next..]) {
                return (start, Rule::Wildcard(next));
            }
            match next_start {
                Some(n) => start = n,
                None => return (d.len(), Rule::None),
            }
        }
    }

    /// The parts, found on first use.
    fn parts(&self) -> &Parts {
        self.parts.get_or_init(|| {
            let d = self.domain.as_str();
            let (suffix, rule) = self.find();
            // `.` and the suffix, the suffix being the TLD when no rule matched.
            let counted = if empty(&d[suffix..]) { self.tld() } else { &d[suffix..] };
            let suffix_len = 1 + counted.chars().count();
            let end = cut_end(d, suffix_len);
            let name = (d[..end].rfind('.').map_or(0, |p| p + 1), end);
            let name_text = &d[name.0..name.1];
            let name_len = if empty(name_text) { 0 } else { 1 + name_text.chars().count() };
            Parts { suffix, rule, name, sub: cut_end(d, name_len + suffix_len) }
        })
    }

    /// The whole domain, lower-cased.
    ///
    /// PHP: `get()`.
    pub fn get(&self) -> &str {
        &self.domain
    }

    /// The top-level domain: the last label (`uk` in `example.co.uk`).
    ///
    /// PHP: `getTLD()`.
    pub fn tld(&self) -> &str {
        self.domain.rsplit('.').next().unwrap_or("")
    }

    /// The public suffix (`co.uk` in `example.co.uk`), `""` when no rule
    /// matches.
    ///
    /// PHP: `getSuffix()`.
    pub fn suffix(&self) -> &str {
        &self.domain[self.parts().suffix..]
    }

    /// The rule that matched (`com`, `*.ck`, `!www.ck`), `""` when none did.
    ///
    /// PHP: `getRule()`.
    pub fn rule(&self) -> Cow<'_, str> {
        match self.parts().rule {
            Rule::None => Cow::Borrowed(""),
            Rule::Exception(at) => Cow::Owned(format!("!{}", &self.domain[at..])),
            Rule::Exact(at) => Cow::Borrowed(&self.domain[at..]),
            Rule::Wildcard(at) => Cow::Owned(format!("*.{}", &self.domain[at..])),
        }
    }

    /// The registrable name: the label before the suffix (`example` in
    /// `blog.example.co.uk`).
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        let (start, end) = self.parts().name;
        &self.domain[start..end]
    }

    /// The subdomain: what comes before the name (`blog` in
    /// `blog.example.co.uk`).
    ///
    /// PHP: `getSub()`.
    pub fn sub(&self) -> &str {
        &self.domain[..self.parts().sub]
    }

    /// The apex domain, `name.suffix`, whether or not the suffix is known.
    ///
    /// PHP: `getApex()`.
    pub fn apex(&self) -> String {
        let (name, suffix) = (self.name(), self.suffix());
        let mut apex = String::with_capacity(name.len() + 1 + suffix.len());
        apex.push_str(name);
        apex.push('.');
        apex.push_str(suffix);
        apex
    }

    /// The registrable domain, `name.suffix`, when the suffix is known; else
    /// `""`.
    ///
    /// PHP: `getRegisterable()`.
    pub fn registerable(&self) -> String {
        if !self.is_known() {
            return String::new();
        }
        self.apex()
    }

    /// Whether the matching rule is on the list.
    ///
    /// PHP: `isKnown()`.
    pub fn is_known(&self) -> bool {
        self.list().rules.contains_key(&*self.rule())
    }

    /// Whether the matching rule is in the ICANN section.
    ///
    /// PHP: `isICANN()`.
    pub fn is_icann(&self) -> bool {
        self.list().section(&self.rule()) == Some(Section::Icann)
    }

    /// Whether the matching rule is in the PRIVATE section.
    ///
    /// PHP: `isPrivate()`.
    pub fn is_private(&self) -> bool {
        self.list().section(&self.rule()) == Some(Section::Private)
    }

    /// Whether the TLD is reserved for testing (`test`, `localhost`).
    ///
    /// PHP: `isTest()`.
    pub fn is_test(&self) -> bool {
        matches!(self.tld(), "test" | "localhost")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn list() -> PublicSuffixList {
        PublicSuffixList::parse("#\tit's\nICANN\tcom\nICANN\tco.uk\nICANN\t*.ck\nICANN\t!www.ck\nPRIVATE\tgithub.io\n")
            .unwrap()
    }

    #[test]
    fn parts() {
        let list = list();
        let d = Domain::with_list("Mail.Sub.Example.CO.UK", &list).unwrap();
        assert_eq!(d.get(), "mail.sub.example.co.uk");
        assert_eq!((d.suffix(), &*d.rule(), d.tld()), ("co.uk", "co.uk", "uk"));
        assert_eq!((d.name(), d.sub(), d.registerable()), ("example", "mail.sub", "example.co.uk".into()));
        assert!(d.is_known() && d.is_icann() && !d.is_private());

        let d = Domain::with_list("a.b.ck", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name()), ("b.ck", "*.ck", "a"));
        let d = Domain::with_list("www.ck", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name()), ("ck", "!www.ck", "www"));
        let d = Domain::with_list("me.github.io", &list).unwrap();
        assert!(d.is_private());

        let d = Domain::with_list("example.test", &list).unwrap();
        assert_eq!((d.suffix(), &*d.rule(), d.name(), d.sub()), ("", "", "example", ""));
        assert!(!d.is_known() && d.is_test());
        assert_eq!(d.registerable(), "");

        let d = Domain::with_list("x.0.com", &list).unwrap();
        assert_eq!((d.name(), d.sub()), ("0", "x.0"));
    }

    #[test]
    fn urls() {
        let e = Domain::new("http://example.com").unwrap_err();
        assert_eq!(e.to_string(), "'http://example.com' must be a valid domain or hostname");
        assert_eq!(e.php_class(), "Exception");
    }

    #[test]
    fn embedded() {
        let list = PublicSuffixList::embedded();
        assert!(list.len() > 9000);
        assert_eq!(list.section("com"), Some(Section::Icann));
        assert_eq!(list.section("github.io"), Some(Section::Private));
        assert!(PublicSuffixList::parse("ICANN com").is_err() && PublicSuffixList::parse("X\tcom").is_err());
    }
}
