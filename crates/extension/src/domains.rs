//! `Utopia\Domains\Domain` over [`utopia_domains`]. PHP fills its protected properties
//! as getters run (`getTLD()` sets `$TLD`, a matching `getSuffix()` sets `$suffix` and
//! `$rule`, ...), so the binding records which ran and shows the same properties:
//! `==`, `var_dump()` and `(array)` see what they see in PHP.

use std::cell::Cell;

use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::zend::ce;

use crate::string::{Bytes, PhpString};

const TLD: u8 = 1;
const SUFFIX: u8 = 2;
const NAME: u8 = 4;
const SUB: u8 = 8;

/// `Utopia\Domains\Domain`: the lowered domain, its parts once found, and which of the
/// PHP class's lazily filled properties are set.
#[php_class]
#[php(name = "Utopia\\Domains\\Domain")]
#[derive(Clone)]
pub struct Domain {
    domain: utopia_domains::Domain<'static>,
    set: Cell<u8>,
}

impl Domain {
    fn mark(&self, property: u8) {
        self.set.set(self.set.get() | property);
    }

    fn shown(&self, property: u8, value: &str) -> String {
        if self.set.get() & property == 0 { String::new() } else { value.to_owned() }
    }

    /// `getTLD()`'s effect: `$TLD` is the last label.
    fn tld(&self) -> &str {
        self.mark(TLD);
        self.domain.tld()
    }

    /// `getSuffix()`'s effect: `$suffix` and `$rule` are set when a rule matches.
    fn suffix(&self) -> &str {
        let suffix = self.domain.suffix();
        if !self.domain.rule().is_empty() {
            self.mark(SUFFIX);
        }
        suffix
    }

    /// The suffix `getName()` and `getSub()` cut off: the TLD when the suffix is empty.
    fn counted(&self) {
        let suffix = self.suffix();
        if suffix.is_empty() || suffix == "0" {
            self.tld();
        }
    }

    fn name(&self) -> &str {
        self.counted();
        self.mark(NAME);
        self.domain.name()
    }
}

#[php_impl]
impl Domain {
    /// The protected properties of `Domain.php`, in its declaration order.
    #[php(getter, name = "domain", vis = "protected")]
    pub fn domain_property(&self) -> String {
        self.domain.get().to_owned()
    }

    #[php(getter, name = "TLD", vis = "protected")]
    pub fn tld_property(&self) -> String {
        self.shown(TLD, self.domain.tld())
    }

    #[php(getter, name = "suffix", vis = "protected")]
    pub fn suffix_property(&self) -> String {
        self.shown(SUFFIX, self.domain.suffix())
    }

    #[php(getter, name = "name", vis = "protected")]
    pub fn name_property(&self) -> String {
        self.shown(NAME, self.domain.name())
    }

    #[php(getter, name = "sub", vis = "protected")]
    pub fn sub_property(&self) -> String {
        self.shown(SUB, self.domain.sub())
    }

    #[php(getter, name = "rule", vis = "protected")]
    pub fn rule_property(&self) -> String {
        self.shown(SUFFIX, &self.domain.rule())
    }

    #[php(getter, name = "parts", vis = "protected")]
    pub fn parts_property(&self) -> Vec<String> {
        self.domain.get().split('.').map(str::to_owned).collect()
    }

    pub fn __construct(domain: PhpString<'_>) -> PhpResult<Self> {
        let domain = utopia_domains::Domain::new(domain.bytes())
            .map_err(|error| PhpException::new(error.to_string(), 0, ce::exception()))?;
        Ok(Self { domain, set: Cell::new(0) })
    }

    pub fn get(&self) -> Bytes<'_> {
        Bytes(self.domain.get().as_bytes())
    }

    #[php(name = "getApex")]
    pub fn get_apex(&self) -> String {
        let name = self.name();
        let suffix = self.suffix();
        format!("{name}.{suffix}")
    }

    #[php(name = "getTLD")]
    pub fn get_tld(&self) -> Bytes<'_> {
        Bytes(self.tld().as_bytes())
    }

    #[php(name = "getSuffix")]
    pub fn get_suffix(&self) -> Bytes<'_> {
        Bytes(self.suffix().as_bytes())
    }

    #[php(name = "getRule")]
    pub fn get_rule(&self) -> String {
        self.suffix();
        self.domain.rule().into_owned()
    }

    #[php(name = "getRegisterable")]
    pub fn get_registerable(&self) -> String {
        if !self.is_known() {
            return String::new();
        }
        self.get_apex()
    }

    #[php(name = "getName")]
    pub fn get_name(&self) -> Bytes<'_> {
        Bytes(self.name().as_bytes())
    }

    #[php(name = "getSub")]
    pub fn get_sub(&self) -> Bytes<'_> {
        self.name();
        self.counted();
        self.mark(SUB);
        Bytes(self.domain.sub().as_bytes())
    }

    #[php(name = "isKnown")]
    pub fn is_known(&self) -> bool {
        self.suffix();
        self.domain.is_known()
    }

    #[php(name = "isICANN")]
    pub fn is_icann(&self) -> bool {
        self.suffix();
        self.domain.is_icann()
    }

    #[php(name = "isPrivate")]
    pub fn is_private(&self) -> bool {
        self.suffix();
        self.domain.is_private()
    }

    #[php(name = "isTest")]
    pub fn is_test(&self) -> bool {
        self.tld();
        self.domain.is_test()
    }
}
