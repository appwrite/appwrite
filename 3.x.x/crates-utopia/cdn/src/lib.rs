//! CDN cache purge and TLS certificate providers for Utopia.
//!
//! Rust port of [`utopia-php/cdn`](https://github.com/utopia-php/cdn).

pub mod cache;
pub mod certificates;
pub mod extend;
pub mod exception {
    pub use crate::error::{CdnError, Configuration, Purge, UnsupportedOperation};
}

mod domain;
mod error;
mod http;

pub use cache::adapter::{Adapter, Balancer, Cloudflare as CloudflareCache, Fastly as FastlyCache};
pub use cache::Cache;
pub use certificates::provider::{
    Cloudflare as CloudflareCertificates, FastlyTls, Provider, Proxy,
};
pub use certificates::{Certificates, Status};
pub use domain::Domain;
pub use error::{CdnError, Configuration, Purge, UnsupportedOperation};
pub use extend::{CdnOption, OptionBalancer, OptionKind, UntypedOption};
pub use http::{default_client, HttpClient};

pub mod prelude {
    pub use crate::{
        Adapter, Balancer, Cache, CdnError, CdnOption, Certificates, CloudflareCache,
        CloudflareCertificates, Domain, FastlyCache, FastlyTls, OptionBalancer, Provider, Proxy,
        Status,
    };
}
