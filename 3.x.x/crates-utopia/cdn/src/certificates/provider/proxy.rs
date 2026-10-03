//! PHP `Utopia\Cdn\Certificates\Provider\Proxy`.

use super::Provider;
use crate::{CdnError, Configuration, Domain, Status};

/// Routes certificate calls by domain type / application hostname.
pub struct Proxy {
    app_domain: String,
    app_domain_provider: Box<dyn Provider>,
    network_provider: Box<dyn Provider>,
    custom_domain_providers: Vec<Box<dyn Provider>>,
}

impl std::fmt::Debug for Proxy {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Proxy")
            .field("app_domain", &self.app_domain)
            .field(
                "custom_domain_providers",
                &self.custom_domain_providers.len(),
            )
            .finish_non_exhaustive()
    }
}

impl Proxy {
    pub fn new(
        app_domain: impl Into<String>,
        app_domain_provider: impl Provider + 'static,
        network_provider: impl Provider + 'static,
        custom_domain_providers: Vec<Box<dyn Provider>>,
    ) -> Result<Self, CdnError> {
        Ok(Self {
            app_domain: Domain::validate(&app_domain.into())?,
            app_domain_provider: Box::new(app_domain_provider),
            network_provider: Box::new(network_provider),
            custom_domain_providers,
        })
    }

    fn select(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<Vec<&dyn Provider>, CdnError> {
        let domain = Domain::validate(domain)?;
        if matches!(domain_type, Some("site" | "network" | "redirect")) {
            return Ok(vec![self.network_provider.as_ref()]);
        }
        if domain == self.app_domain {
            return Ok(vec![self.app_domain_provider.as_ref()]);
        }
        if self.custom_domain_providers.is_empty() {
            return Err(Configuration(
                "No certificate providers are configured for custom domains.".into(),
            )
            .into());
        }
        Ok(self
            .custom_domain_providers
            .iter()
            .map(|provider| provider.as_ref())
            .collect())
    }
}

impl Provider for Proxy {
    fn issue_certificate(
        &self,
        cert_name: &str,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<Option<String>, CdnError> {
        let mut renew_date = None;
        for provider in self.select(domain, domain_type)? {
            let candidate = provider.issue_certificate(cert_name, domain, domain_type)?;
            // PHP `$renewDate = $candidate ?? $renewDate` - keep the last Some.
            if candidate.is_some() {
                renew_date = candidate;
            }
        }
        Ok(renew_date)
    }

    fn is_instant_generation(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<bool, CdnError> {
        for provider in self.select(domain, domain_type)? {
            if !provider.is_instant_generation(domain, domain_type)? {
                return Ok(false);
            }
        }
        Ok(true)
    }

    fn get_certificate_status(
        &self,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<String, CdnError> {
        for provider in self.select(domain, domain_type)? {
            if provider.is_instant_generation(domain, domain_type)? {
                continue;
            }
            let status = provider.get_certificate_status(domain, domain_type)?;
            if status != Status::ISSUED {
                return Ok(status);
            }
        }
        Ok(Status::ISSUED.to_owned())
    }

    fn is_renew_required(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, CdnError> {
        for provider in self.select(domain, domain_type)? {
            if provider.is_renew_required(domain, domain_type)? {
                return Ok(true);
            }
        }
        Ok(false)
    }

    fn delete_certificate(&self, domain: &str, domain_type: Option<&str>) -> Result<(), CdnError> {
        for provider in self.select(domain, domain_type)? {
            provider.delete_certificate(domain, domain_type)?;
        }
        Ok(())
    }
}
