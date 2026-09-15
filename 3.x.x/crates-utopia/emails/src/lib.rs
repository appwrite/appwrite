//! Email parsing, classification, and canonicalization for Utopia.
//!
//! Rust port of [`utopia-php/emails`](https://github.com/utopia-php/emails).

mod canonicals;
mod email;
mod error;
mod filter;
mod lists;
pub mod sync;
pub mod validator;

pub use canonicals::{
    Canonical, Fastmail, Generic, Gmail, Icloud, Outlook, Protonmail, Provider, Walla, Yahoo,
    Yandex,
};
pub use email::Email;
pub use error::EmailError;
pub use lists::{disposable_domains, disposable_domains_manual, free_domains, free_domains_manual};

pub use validator::{
    Email as EmailValidator, EmailCorporate, EmailDomain, EmailLocal, EmailNotDisposable,
};

/// PHP `Email::FORMAT_FULL`.
pub const FORMAT_FULL: &str = Email::FORMAT_FULL;
/// PHP `Email::FORMAT_LOCAL`.
pub const FORMAT_LOCAL: &str = Email::FORMAT_LOCAL;
/// PHP `Email::FORMAT_DOMAIN`.
pub const FORMAT_DOMAIN: &str = Email::FORMAT_DOMAIN;
/// PHP `Email::FORMAT_PROVIDER`.
pub const FORMAT_PROVIDER: &str = Email::FORMAT_PROVIDER;
/// PHP `Email::FORMAT_SUBDOMAIN`.
pub const FORMAT_SUBDOMAIN: &str = Email::FORMAT_SUBDOMAIN;

pub mod prelude {
    pub use crate::{
        Canonical, Email, EmailCorporate, EmailDomain, EmailError, EmailLocal, EmailNotDisposable,
        EmailValidator, Fastmail, Generic, Gmail, Icloud, Outlook, Protonmail, Provider, Walla,
        Yahoo, Yandex, FORMAT_DOMAIN, FORMAT_FULL, FORMAT_LOCAL, FORMAT_PROVIDER, FORMAT_SUBDOMAIN,
    };
}
