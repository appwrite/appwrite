//! PHP `Utopia\Cdn\Certificates\Provider`.

mod cloudflare;
mod fastly_tls;
mod proxy;

pub use cloudflare::Cloudflare;
pub use fastly_tls::FastlyTls;
pub use proxy::Proxy;

use crate::CdnError;

/// CDN-managed certificate operations.
pub trait Provider: Send + Sync {
    fn issue_certificate(
        &self,
        cert_name: &str,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError>;

    fn is_instant_generation(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<bool, CdnError>;

    fn get_certificate_status(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<String, CdnError>;

    fn is_renew_required(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, CdnError>;

    fn delete_certificate(&self, domain: &str, domain_type: Option<&str>) -> Result<(), CdnError>;
}
