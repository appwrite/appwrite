//! Canonical forms of addresses per mail provider.
//!
//! PHP: `Utopia\Emails\Canonicals\Providers\*`.

use php_std::string;

use crate::Error;

/// The canonical form of an address: its local part and domain with a
/// provider's aliases removed.
///
/// PHP: the array `['local' => ..., 'domain' => ...]` of `getCanonical()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Canonical {
    /// The part before the `@`.
    pub local: Vec<u8>,
    /// The part after the `@`.
    pub domain: Vec<u8>,
}

impl Canonical {
    /// The address, `local@domain`.
    pub fn address(&self) -> Vec<u8> {
        [self.local.as_slice(), b"@", self.domain.as_slice()].concat()
    }
}

/// A mail provider's aliasing rules.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Provider {
    /// The domain of its canonical addresses (`gmail.com`), empty for
    /// [`Provider::GENERIC`].
    ///
    /// PHP: `getCanonicalDomain()`.
    pub canonical_domain: &'static str,
    /// The domains that belong to it.
    ///
    /// PHP: `getSupportedDomains()`.
    pub domains: &'static [&'static str],
    /// Drops a `+tag`.
    plus: bool,
    /// Drops every dot.
    dots: bool,
    /// Refuses a local part left empty (or `"0"`).
    required: bool,
    /// Keeps the address's own domain, when it is one of [`Provider::domains`],
    /// instead of the canonical one.
    own_domain: bool,
}

impl Provider {
    /// Gmail: drops `+tags` and all dots; `googlemail.com` is `gmail.com`.
    pub const GMAIL: Provider = Provider {
        canonical_domain: "gmail.com",
        domains: &["gmail.com", "googlemail.com"],
        plus: true,
        dots: true,
        required: true,
        own_domain: false,
    };

    /// Outlook, Hotmail, Live, MSN and Passport: drops `+tags`, keeps dots.
    pub const OUTLOOK: Provider = Provider {
        canonical_domain: "outlook.com",
        domains: &[
            "outlook.com",
            "outlook.at",
            "outlook.be",
            "outlook.cl",
            "outlook.co.il",
            "outlook.co.nz",
            "outlook.co.th",
            "outlook.co.uk",
            "outlook.com.ar",
            "outlook.com.au",
            "outlook.com.br",
            "outlook.com.gr",
            "outlook.com.pe",
            "outlook.com.tr",
            "outlook.com.vn",
            "outlook.cz",
            "outlook.de",
            "outlook.dk",
            "outlook.es",
            "outlook.fr",
            "outlook.hu",
            "outlook.id",
            "outlook.ie",
            "outlook.in",
            "outlook.it",
            "outlook.jp",
            "outlook.kr",
            "outlook.lv",
            "outlook.my",
            "outlook.ph",
            "outlook.pt",
            "outlook.sa",
            "outlook.sg",
            "outlook.sk",
            "hotmail.com",
            "hotmail.at",
            "hotmail.be",
            "hotmail.ca",
            "hotmail.cl",
            "hotmail.co.il",
            "hotmail.co.nz",
            "hotmail.co.th",
            "hotmail.co.uk",
            "hotmail.com.ar",
            "hotmail.com.au",
            "hotmail.com.br",
            "hotmail.com.gr",
            "hotmail.com.mx",
            "hotmail.com.pe",
            "hotmail.com.tr",
            "hotmail.com.vn",
            "hotmail.cz",
            "hotmail.de",
            "hotmail.dk",
            "hotmail.es",
            "hotmail.fr",
            "hotmail.hu",
            "hotmail.id",
            "hotmail.ie",
            "hotmail.in",
            "hotmail.it",
            "hotmail.jp",
            "hotmail.kr",
            "hotmail.lv",
            "hotmail.my",
            "hotmail.ph",
            "hotmail.pt",
            "hotmail.sa",
            "hotmail.sg",
            "hotmail.sk",
            "live.com",
            "live.be",
            "live.co.uk",
            "live.com.ar",
            "live.com.mx",
            "live.de",
            "live.es",
            "live.eu",
            "live.fr",
            "live.it",
            "live.nl",
            "msn.com",
            "passport.com",
        ],
        plus: true,
        dots: false,
        required: true,
        own_domain: false,
    };

    /// Yahoo, Ymail and Rocketmail: keeps the local part (a `-keyword` is a
    /// separate address, not an alias).
    pub const YAHOO: Provider = Provider {
        canonical_domain: "yahoo.com",
        domains: &[
            "yahoo.com",
            "yahoo.co.uk",
            "yahoo.ca",
            "yahoo.de",
            "yahoo.fr",
            "yahoo.in",
            "yahoo.it",
            "ymail.com",
            "rocketmail.com",
        ],
        plus: false,
        dots: false,
        required: false,
        own_domain: false,
    };

    /// iCloud, me.com and mac.com: drops `+tags`, keeps dots.
    pub const ICLOUD: Provider = Provider {
        canonical_domain: "icloud.com",
        domains: &["icloud.com", "me.com", "mac.com"],
        plus: true,
        dots: false,
        required: true,
        own_domain: false,
    };

    /// Proton Mail: drops `+tags`, keeps dots and the domain (each Proton
    /// domain is a separate address).
    pub const PROTONMAIL: Provider = Provider {
        canonical_domain: "protonmail.com",
        domains: &["protonmail.com", "proton.me", "pm.me", "protonmail.ch"],
        plus: true,
        dots: false,
        required: false,
        own_domain: true,
    };

    /// Fastmail: keeps the local part.
    pub const FASTMAIL: Provider = Provider {
        canonical_domain: "fastmail.com",
        domains: &["fastmail.com", "fastmail.fm"],
        plus: false,
        dots: false,
        required: false,
        own_domain: false,
    };

    /// Walla: keeps the local part; `walla.com` is `walla.co.il`.
    pub const WALLA: Provider = Provider {
        canonical_domain: "walla.co.il",
        domains: &["walla.co.il", "walla.com"],
        plus: false,
        dots: false,
        required: false,
        own_domain: false,
    };

    /// Yandex: keeps the local part; every Yandex domain is `yandex.ru`. Not
    /// in [`PROVIDERS`].
    pub const YANDEX: Provider = Provider {
        canonical_domain: "yandex.ru",
        domains: &["yandex.ru", "yandex.ua", "yandex.kz", "yandex.com", "yandex.by", "ya.ru"],
        plus: false,
        dots: false,
        required: false,
        own_domain: false,
    };

    /// Any other domain: only lower-cases the local part.
    pub const GENERIC: Provider =
        Provider { canonical_domain: "", domains: &[], plus: false, dots: false, required: false, own_domain: true };

    /// The provider of [`PROVIDERS`] the domain belongs to, else
    /// [`Provider::GENERIC`].
    ///
    /// PHP: `getProviderForDomain()`.
    pub fn of(domain: &[u8]) -> &'static Provider {
        PROVIDERS.iter().find(|provider| provider.supports(domain)).unwrap_or(&Provider::GENERIC)
    }

    /// Whether the domain belongs to this provider; any domain belongs to
    /// [`Provider::GENERIC`].
    ///
    /// PHP: `supports($domain)`.
    pub fn supports(&self, domain: &[u8]) -> bool {
        self.domains.is_empty() || self.domains.iter().any(|own| own.as_bytes() == domain)
    }

    /// The address with this provider's aliases removed.
    ///
    /// # Errors
    ///
    /// [`Error::EmptyLocal`] when Gmail, Outlook or iCloud's rules leave
    /// nothing (or `"0"`) of the local part.
    ///
    /// PHP: `getCanonical($local, $domain)`.
    pub fn canonical(&self, local: &[u8], domain: &[u8]) -> Result<Canonical, Error> {
        let mut local = string::strtolower(local).into_owned();
        if self.plus
            && let Some(plus) = local.iter().position(|&byte| byte == b'+')
            && plus > 0
        {
            local.truncate(plus);
        }
        if self.dots {
            local.retain(|&byte| byte != b'.');
        }
        if self.required && (local.is_empty() || local == b"0") {
            return Err(Error::EmptyLocal);
        }
        // Proton Mail keeps one of its own domains and falls back to its canonical one.
        let domain = if self.own_domain && self.supports(domain) {
            domain.to_vec()
        } else {
            self.canonical_domain.as_bytes().to_vec()
        };
        Ok(Canonical { local, domain })
    }
}

/// The providers `Email` consults, in order.
///
/// PHP: `Email::initializeProviders()`; `Yandex` is not among them.
pub static PROVIDERS: &[Provider] = &[
    Provider::GMAIL,
    Provider::OUTLOOK,
    Provider::YAHOO,
    Provider::ICLOUD,
    Provider::PROTONMAIL,
    Provider::FASTMAIL,
    Provider::WALLA,
];

#[cfg(test)]
mod tests {
    use super::*;

    fn canonical(local: &str, domain: &str) -> Result<String, Error> {
        Provider::of(domain.as_bytes())
            .canonical(local.as_bytes(), domain.as_bytes())
            .map(|canonical| String::from_utf8(canonical.address()).unwrap())
    }

    #[test]
    fn providers() {
        assert_eq!(canonical("John.Doe+tag", "googlemail.com").unwrap(), "johndoe@gmail.com");
        assert_eq!(canonical("a.b+c", "hotmail.co.uk").unwrap(), "a.b@outlook.com");
        assert_eq!(canonical("a.b+c", "pm.me").unwrap(), "a.b@pm.me");
        assert_eq!(canonical("a-b+c", "ymail.com").unwrap(), "a-b+c@yahoo.com");
        assert_eq!(canonical("+a", "me.com").unwrap(), "+a@icloud.com");
        assert_eq!(canonical("A+b", "yandex.ru").unwrap(), "a+b@yandex.ru");
        assert_eq!(canonical("0+x", "gmail.com"), Err(Error::EmptyLocal));
        assert_eq!(canonical("..", "gmail.com"), Err(Error::EmptyLocal));
        assert_eq!(canonical("0", "yahoo.com").unwrap(), "0@yahoo.com");
        assert_eq!(Provider::of(b"me.com").canonical_domain, "icloud.com");
        let proton = Provider::PROTONMAIL.canonical(b"A+b", b"example.com").unwrap();
        assert_eq!(proton.address(), b"a@protonmail.com");
        assert_eq!(Provider::YANDEX.canonical(b"A.b+c", b"ya.ru").unwrap().address(), b"a.b+c@yandex.ru");
        assert_eq!(Provider::of(b"GMAIL.com"), &Provider::GENERIC);
    }
}
