//! The free and disposable domain lists (`data/free-domains.php`,
//! `data/disposable-domains.php`).

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::OnceLock;

use php_std::value;
use serde_json::Value;

use crate::literal::{self, Literal};

/// A list of domains, matched like PHP's `in_array($domain, $list)`: loose
/// comparison, so numeric strings compare as numbers (`"1e3" == "1000"`).
#[derive(Debug, Default)]
pub struct DomainList {
    exact: HashSet<Box<str>>,
    /// Entries that are numeric strings, which a numeric needle can equal
    /// without being byte-identical.
    numeric: Vec<Box<str>>,
}

impl DomainList {
    /// `in_array($domain, $list)`.
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

    pub fn len(&self) -> usize {
        self.exact.len()
    }

    pub fn is_empty(&self) -> bool {
        self.exact.is_empty()
    }
}

impl<S: Into<Box<str>>> FromIterator<S> for DomainList {
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

/// Both lists [`crate::Email`] classifies domains with.
#[derive(Debug, Default)]
pub struct Lists {
    pub free: DomainList,
    pub disposable: DomainList,
}

static GLOBAL: OnceLock<Lists> = OnceLock::new();

impl Lists {
    /// The lists as PHP includes them: `data/` of the PHP package in this repository.
    pub fn default_dir() -> PathBuf {
        Path::new(env!("CARGO_MANIFEST_DIR")).join("../../../packages/emails/data")
    }

    /// Parses one data file: `<?php return ['domain', ...];`.
    pub fn parse(source: &str) -> Result<DomainList, String> {
        match literal::parse(source)? {
            Literal::Array(entries) => {
                Ok(entries.into_iter().filter_map(|(_, v)| v.as_str().map(str::to_owned)).collect())
            }
            Literal::Str(_) => Err("the data file must return an array".into()),
        }
    }

    /// Reads `free-domains.php` and `disposable-domains.php` from `dir`.
    pub fn load(dir: &Path) -> Result<Self, String> {
        let read = |name: &str| -> Result<DomainList, String> {
            let path = dir.join(name);
            let source = std::fs::read_to_string(&path).map_err(|e| format!("{}: {e}", path.display()))?;
            Self::parse(&source).map_err(|e| format!("{}: {e}", path.display()))
        };
        Ok(Self { free: read("free-domains.php")?, disposable: read("disposable-domains.php")? })
    }

    /// Installs the process-wide lists. The first lists installed (or
    /// loaded) win: returns whether these were installed.
    pub fn install(self) -> bool {
        GLOBAL.set(self).is_ok()
    }

    /// The process-wide lists: the installed ones, else those in
    /// [`Self::default_dir`] (empty when they cannot be read).
    pub fn global() -> &'static Self {
        GLOBAL.get_or_init(|| Self::load(&Self::default_dir()).unwrap_or_default())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse() {
        let list = Lists::parse(
            "<?php\n/** header */\nreturn [\n    'gmail.com',\n    'it\\'s.com', // note\n    '1000',\n];\n",
        )
        .unwrap();
        assert_eq!(list.len(), 3);
        assert!(list.contains("gmail.com") && list.contains("it's.com"));
        // in_array() compares numeric strings as numbers.
        assert!(list.contains("1e3") && list.contains(" 1000") && !list.contains("1e4"));
        assert!(!list.contains("GMAIL.COM"));
        assert!(Lists::parse("<?php return 'x';").is_err());
    }

    #[test]
    fn data_files() {
        let lists = Lists::load(&Lists::default_dir()).unwrap();
        assert!(lists.free.contains("gmail.com") && lists.disposable.contains("mailinator.com"));
        assert!(lists.disposable.len() > 10_000);
    }
}
