//! Domain parsing, public-suffix matching, and registrar adapters for Utopia.
//!
//! Rust port of [`utopia-php/domains`](https://github.com/utopia-php/domains).

mod cache;
mod domain;
mod error;
mod psl;
pub mod sync;

pub mod registrar;
pub mod validator;

pub use cache::{Cache, CacheStore, MemoryCache, NoneCache};
pub use domain::Domain;
pub use error::DomainsError;
pub use psl::{psl_list, SuffixKind};

pub use registrar::{
    Adapter, Contact, Contacts, NameserverUpdate, Price, Registrar, RegistrarDomain, Renewal,
    SuggestItem, SuggestQuery, TransferStatus, TransferStatusEnum, UpdateDetails,
};

pub use validator::{ApexDomain, PublicDomain};
