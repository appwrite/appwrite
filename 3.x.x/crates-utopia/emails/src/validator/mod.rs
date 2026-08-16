//! Email validators implementing [`utopia_validators::Validator`].

mod email;
mod email_corporate;
mod email_domain;
mod email_local;
mod email_not_disposable;

pub use email::Email;
pub use email_corporate::EmailCorporate;
pub use email_domain::EmailDomain;
pub use email_local::EmailLocal;
pub use email_not_disposable::EmailNotDisposable;
