use crate::error::DomainsError;
use crate::psl::{psl_list, SuffixKind};

/// Parsed hostname with Public Suffix List matching (PHP `Utopia\Domains\Domain`).
#[derive(Debug, Clone)]
pub struct Domain {
    domain: String,
    parts: Vec<String>,
    tld: std::cell::OnceCell<String>,
    suffix: std::cell::OnceCell<String>,
    name: std::cell::OnceCell<String>,
    rule: std::cell::OnceCell<String>,
}

impl Domain {
    /// Parse a domain or hostname.
    ///
    /// Rejects values that start with `http://` or `https://` (checked before
    /// lowercasing, matching PHP `strpos === 0`). The stored domain is
    /// Unicode-lowercased (`mb_strtolower`).
    pub fn new(domain: impl AsRef<str>) -> Result<Self, DomainsError> {
        let original = domain.as_ref();
        if original.starts_with("http://") || original.starts_with("https://") {
            return Err(DomainsError::InvalidDomain {
                domain: original.to_string(),
            });
        }
        let domain = original.to_lowercase();
        let parts = php_explode(&domain, '.');
        Ok(Self {
            domain,
            parts,
            tld: std::cell::OnceCell::new(),
            suffix: std::cell::OnceCell::new(),
            name: std::cell::OnceCell::new(),
            rule: std::cell::OnceCell::new(),
        })
    }

    /// Full domain string (`get()`).
    pub fn get(&self) -> &str {
        &self.domain
    }

    /// Apex domain: `{name}.{suffix}` (`getApex()`).
    pub fn get_apex(&self) -> String {
        format!("{}.{}", self.get_name(), self.get_suffix())
    }

    /// Right-most label (`getTLD()`).
    pub fn get_tld(&self) -> &str {
        self.tld
            .get_or_init(|| self.parts.last().cloned().unwrap_or_default())
    }

    /// Public suffix (`getSuffix()`).
    ///
    /// Matching order per label from the left, identical to PHP:
    /// 1. exception `!joined`
    /// 2. exact `joined`
    /// 3. wildcard `*.next`
    pub fn get_suffix(&self) -> &str {
        self.suffix.get_or_init(|| {
            let list = psl_list();
            for i in 0..self.parts.len() {
                let joined = self.parts[i..].join(".");
                let next = if i + 1 < self.parts.len() {
                    self.parts[i + 1..].join(".")
                } else {
                    String::new()
                };
                let exception = format!("!{joined}");
                let wildcard = format!("*.{next}");

                if list.contains_key(&exception) {
                    self.rule.get_or_init(|| exception);
                    return next;
                }
                if list.contains_key(&joined) {
                    self.rule.get_or_init(|| joined.clone());
                    return joined;
                }
                if list.contains_key(&wildcard) {
                    self.rule.get_or_init(|| wildcard);
                    return joined;
                }
            }
            String::new()
        })
    }

    /// PSL rule that matched (`getRule()`), including `!` / `*.` prefixes.
    pub fn get_rule(&self) -> &str {
        if self.rule.get().is_none() {
            let _ = self.get_suffix();
        }
        self.rule.get().map_or("", String::as_str)
    }

    /// Registrable domain (`getRegisterable()`): empty when the suffix is unknown.
    pub fn get_registerable(&self) -> String {
        if !self.is_known() {
            return String::new();
        }
        format!("{}.{}", self.get_name(), self.get_suffix())
    }

    /// Registrable label (`getName()`).
    pub fn get_name(&self) -> &str {
        self.name.get_or_init(|| {
            let suffix = self.get_suffix();
            let suffix = if suffix.is_empty() {
                format!(".{}", self.get_tld())
            } else {
                format!(".{suffix}")
            };
            let trimmed = php_mb_substr_omit_end(&self.domain, suffix.chars().count());
            php_explode(&trimmed, '.').pop().unwrap_or_default()
        })
    }

    /// Subdomain labels (`getSub()`).
    pub fn get_sub(&self) -> String {
        let name = self.get_name();
        let name = if name.is_empty() {
            String::new()
        } else {
            format!(".{name}")
        };
        let suffix = self.get_suffix();
        let suffix = if suffix.is_empty() {
            format!(".{}", self.get_tld())
        } else {
            format!(".{suffix}")
        };
        let domain = format!("{name}{suffix}");
        let trimmed = php_mb_substr_omit_end(&self.domain, domain.chars().count());
        php_explode(&trimmed, '.').join(".")
    }

    /// Whether a PSL rule matched (`isKnown()`).
    pub fn is_known(&self) -> bool {
        psl_list().contains_key(self.get_rule())
    }

    /// Whether the matching rule is in the ICANN section (`isICANN()`).
    pub fn is_icann(&self) -> bool {
        psl_list()
            .get(self.get_rule())
            .is_some_and(|kind| *kind == SuffixKind::Icann)
    }

    /// Whether the matching rule is in the PRIVATE section (`isPrivate()`).
    pub fn is_private(&self) -> bool {
        psl_list()
            .get(self.get_rule())
            .is_some_and(|kind| *kind == SuffixKind::Private)
    }

    /// Whether the TLD is reserved for testing (`isTest()`): `test` or `localhost`.
    pub fn is_test(&self) -> bool {
        matches!(self.get_tld(), "test" | "localhost")
    }
}

/// PHP `explode('.', $s)` - keeps empty segments, including for `""`.
fn php_explode(s: &str, sep: char) -> Vec<String> {
    s.split(sep).map(str::to_string).collect()
}

/// PHP `mb_substr($s, 0, -omit)` (character based).
fn php_mb_substr_omit_end(s: &str, omit: usize) -> String {
    let count = s.chars().count();
    if omit >= count {
        String::new()
    } else {
        s.chars().take(count - omit).collect()
    }
}
