//! PHP `Utopia\Cdn\Certificates`.

pub mod provider;

use provider::Provider;

use crate::CdnError;

/// PHP `Utopia\Cdn\Certificates\Status`.
#[derive(Debug, Clone, Copy)]
pub struct Status;

impl Status {
    pub const PENDING: &'static str = "pending";
    pub const PROCESSING: &'static str = "processing";
    pub const ISSUED: &'static str = "issued";
    pub const RENEWING: &'static str = "renewing";
    pub const FAILED: &'static str = "failed";
    pub const UNKNOWN: &'static str = "unknown";
}

/// Facade that forwards certificate operations to a [`Provider`].
pub struct Certificates {
    provider: Box<dyn Provider>,
}

impl std::fmt::Debug for Certificates {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Certificates").finish_non_exhaustive()
    }
}

impl Certificates {
    #[must_use]
    pub fn new(provider: impl Provider + 'static) -> Self {
        Self {
            provider: Box::new(provider),
        }
    }

    pub fn issue_certificate(
        &self,
        cert_name: &str,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError> {
        self.provider
            .issue_certificate(cert_name, domain, domain_type)
    }

    pub fn is_instant_generation(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        self.provider.is_instant_generation(domain, domain_type)
    }

    pub fn get_certificate_status(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<String, CdnError> {
        self.provider.get_certificate_status(domain, domain_type)
    }

    pub fn is_renew_required(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        self.provider.is_renew_required(domain, domain_type)
    }

    pub fn delete_certificate(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<(), CdnError> {
        self.provider.delete_certificate(domain, domain_type)
    }
}
