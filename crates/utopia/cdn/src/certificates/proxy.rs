//! Routing certificate calls by domain.

use std::fmt;
use std::sync::Arc;

use crate::certificates::{Provider, Status};
use crate::{BoxFuture, Error, domain};

/// Routes each certificate call to the providers responsible for the
/// domain: the network provider for `site`, `network` and `redirect`
/// domains, the app domain's provider for the app domain itself, and every
/// custom-domain provider for anything else (fails with
/// [`Error::Configuration`] when there are none).
///
/// Over several providers, a certificate is instant only when every one is,
/// needs renewing when any does, and is issued when every provider that is
/// not instant reports it issued (else the first other status).
///
/// ```
/// use std::sync::Arc;
///
/// use utopia_cdn::certificates::{Cloudflare, FastlyTls, Proxy};
///
/// let app = Arc::new(FastlyTls::new("token", "app-configuration"));
/// let network = Arc::new(FastlyTls::new("token", "edge-configuration"));
/// let custom = vec![Arc::new(Cloudflare::new("zone", "token")) as _, Arc::new(FastlyTls::new("token", "")) as _];
/// let proxy = Proxy::new("App.Example.com", app, network, custom)?;
/// assert_eq!(proxy.app_domain(), "app.example.com");
/// # Ok::<(), utopia_cdn::Error>(())
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates\Provider\Cloudflare;
/// use Utopia\Cdn\Certificates\Provider\FastlyTls;
/// use Utopia\Cdn\Certificates\Provider\Proxy;
///
/// $proxy = new Proxy(
///     'App.Example.com',
///     new FastlyTls('token', 'app-configuration'),
///     new FastlyTls('token', 'edge-configuration'),
///     [new Cloudflare('zone', 'token'), new FastlyTls('token', '')],
/// );
/// ```
///
/// PHP: `Utopia\Cdn\Certificates\Provider\Proxy`.
#[derive(Clone)]
pub struct Proxy {
    app_domain: String,
    app: Arc<dyn Provider>,
    network: Arc<dyn Provider>,
    custom: Vec<Arc<dyn Provider>>,
}

impl Proxy {
    /// A proxy for an app domain (lower-cased; [`Error::InvalidArgument`]
    /// unless it is a hostname).
    ///
    /// PHP: `new Proxy($appDomain, $appDomainProvider, $networkProvider, $customDomainProviders)`.
    pub fn new(
        app_domain: &str,
        app: Arc<dyn Provider>,
        network: Arc<dyn Provider>,
        custom: Vec<Arc<dyn Provider>>,
    ) -> Result<Self, Error> {
        Ok(Proxy { app_domain: domain::validate(app_domain)?.into_owned(), app, network, custom })
    }

    /// The app domain, lower-cased.
    pub fn app_domain(&self) -> &str {
        &self.app_domain
    }

    /// The providers responsible for an already normalised domain.
    fn select(&self, domain: &str, domain_type: Option<&str>) -> Result<&[Arc<dyn Provider>], Error> {
        if matches!(domain_type, Some("site" | "network" | "redirect")) {
            return Ok(std::slice::from_ref(&self.network));
        }
        if domain == self.app_domain {
            return Ok(std::slice::from_ref(&self.app));
        }
        if self.custom.is_empty() {
            return Err(Error::Configuration("No certificate providers are configured for custom domains.".into()));
        }
        Ok(&self.custom)
    }

    async fn issue(&self, cert_name: &str, domain: &str, domain_type: Option<&str>) -> Result<Option<String>, Error> {
        let domain = domain::validate(domain)?;
        let mut renew_date = None;
        for provider in self.select(&domain, domain_type)? {
            if let Some(date) = provider.issue_certificate(cert_name, &domain, domain_type).await? {
                renew_date = Some(date);
            }
        }
        Ok(renew_date)
    }

    async fn instant(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, Error> {
        let domain = domain::validate(domain)?;
        for provider in self.select(&domain, domain_type)? {
            if !provider.is_instant_generation(&domain, domain_type).await? {
                return Ok(false);
            }
        }
        Ok(true)
    }

    async fn status(&self, domain: &str, domain_type: Option<&str>) -> Result<Status, Error> {
        let domain = domain::validate(domain)?;
        for provider in self.select(&domain, domain_type)? {
            if provider.is_instant_generation(&domain, domain_type).await? {
                continue;
            }
            let status = provider.certificate_status(&domain, domain_type).await?;
            if status != Status::Issued {
                return Ok(status);
            }
        }
        Ok(Status::Issued)
    }

    async fn renew(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, Error> {
        let domain = domain::validate(domain)?;
        for provider in self.select(&domain, domain_type)? {
            if provider.is_renew_required(&domain, domain_type).await? {
                return Ok(true);
            }
        }
        Ok(false)
    }

    async fn delete(&self, domain: &str, domain_type: Option<&str>) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        for provider in self.select(&domain, domain_type)? {
            provider.delete_certificate(&domain, domain_type).await?;
        }
        Ok(())
    }
}

impl fmt::Debug for Proxy {
    /// Writes the app domain and the number of custom-domain providers.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Proxy")
            .field("app_domain", &self.app_domain)
            .field("custom", &self.custom.len())
            .finish_non_exhaustive()
    }
}

impl Provider for Proxy {
    /// Issues through every responsible provider; the last renewal date any
    /// of them returned.
    fn issue_certificate<'a>(
        &'a self,
        cert_name: &'a str,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        Box::pin(self.issue(cert_name, domain, domain_type))
    }

    /// Whether every responsible provider is instant.
    fn is_instant_generation<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.instant(domain, domain_type))
    }

    /// The first status other than issued among the responsible providers
    /// that are not instant; issued when there is none.
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(self.status(domain, domain_type))
    }

    /// Whether any responsible provider needs a renewal.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.renew(domain, domain_type))
    }

    /// Deletes through every responsible provider.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.delete(domain, domain_type))
    }
}
