//! TLS certificates for custom domains: the [`Provider`] every vendor
//! implements, the [`Certificates`] front, and the providers
//! ([`Cloudflare`], [`Fastly`], [`FastlyTls`], and [`Proxy`], which routes
//! between them).

mod cloudflare;
mod fastly;
mod fastly_tls;
mod proxy;

use std::fmt;
use std::sync::Arc;

pub use cloudflare::Cloudflare;
pub use fastly::Fastly;
pub use fastly_tls::FastlyTls;
pub use proxy::Proxy;

use crate::{BoxFuture, Error};

/// Where a certificate's issuance stands.
///
/// PHP: the `Utopia\Cdn\Certificates\Status` constants, which are strings
/// ([`Status::as_str`]); a provider here can only report one of them.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Status {
    /// Requested, not started. PHP: `Status::PENDING` (`pending`).
    Pending,
    /// Being issued. PHP: `Status::PROCESSING` (`processing`).
    Processing,
    /// Issued and serving. PHP: `Status::ISSUED` (`issued`).
    Issued,
    /// Issued, a renewal under way. PHP: `Status::RENEWING` (`renewing`).
    Renewing,
    /// The provider stopped trying. PHP: `Status::FAILED` (`failed`).
    Failed,
    /// Waiting on the domain owner, typically for a DNS record proving
    /// ownership. Reported through [`Error::Certificate`] rather than
    /// returned, so a caller polling for completion cannot mistake it for
    /// progress. PHP: `Status::BLOCKED` (`blocked`).
    Blocked,
    /// Nothing known. PHP: `Status::UNKNOWN` (`unknown`).
    Unknown,
}

impl Status {
    /// Every status, in the order PHP declares them.
    pub const ALL: [Status; 7] = [
        Status::Pending,
        Status::Processing,
        Status::Issued,
        Status::Renewing,
        Status::Failed,
        Status::Blocked,
        Status::Unknown,
    ];

    /// The name PHP uses (`issued`, `blocked`, ...).
    pub fn as_str(self) -> &'static str {
        match self {
            Status::Pending => "pending",
            Status::Processing => "processing",
            Status::Issued => "issued",
            Status::Renewing => "renewing",
            Status::Failed => "failed",
            Status::Blocked => "blocked",
            Status::Unknown => "unknown",
        }
    }

    /// The status of that name (exact, lower case), if any.
    pub fn parse(name: &str) -> Option<Status> {
        Status::ALL.into_iter().find(|s| s.as_str() == name)
    }
}

impl fmt::Display for Status {
    /// Writes [`Status::as_str`].
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

/// A DNS record the domain owner has to publish before the certificate
/// authority accepts that they control the domain.
///
/// PHP: `Utopia\Cdn\Certificates\Challenge` (its public properties).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Challenge {
    /// Provider-specific challenge type, for example Fastly's `managed-dns`.
    pub kind: String,
    /// DNS record type to create, for example `CNAME`.
    pub record_type: String,
    /// Fully qualified name of the record to create.
    pub record_name: String,
    /// Values the record has to hold; several values are alternatives.
    pub values: Vec<String>,
}

/// Issues, checks, renews and deletes the certificate of a domain.
///
/// `domain_type` is the caller's kind of domain; [`Proxy`] routes `site`,
/// `network` and `redirect` domains to its network provider. Implement it
/// to add a provider.
///
/// PHP: `Utopia\Cdn\Certificates\Provider`.
pub trait Provider: Send + Sync {
    /// Starts or continues issuance; the date after which the certificate
    /// should be renewed (`Y-m-d H:i:s.v`), when one is issued.
    ///
    /// PHP: `issueCertificate($certName, $domain, $domainType)`.
    fn issue_certificate<'a>(
        &'a self,
        cert_name: &'a str,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>>;

    /// Whether a certificate is ready as soon as it is requested.
    ///
    /// PHP: `isInstantGeneration($domain, $domainType)`.
    fn is_instant_generation<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>>;

    /// Where issuance stands. Fails with [`Error::Certificate`] when it
    /// waits on the domain owner or the provider stopped trying.
    ///
    /// PHP: `getCertificateStatus($domain, $domainType)`.
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>>;

    /// Whether the certificate has to be issued (again).
    ///
    /// PHP: `isRenewRequired($domain, $domainType)`.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>>;

    /// Removes the certificate (and what the provider keeps for the domain).
    ///
    /// PHP: `deleteCertificate($domain, $domainType)`.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>>;
}

impl<T: Provider + ?Sized> Provider for Arc<T> {
    /// Issues through the shared provider.
    fn issue_certificate<'a>(
        &'a self,
        cert_name: &'a str,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        (**self).issue_certificate(cert_name, domain, domain_type)
    }

    /// Asks the shared provider.
    fn is_instant_generation<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).is_instant_generation(domain, domain_type)
    }

    /// Asks the shared provider.
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        (**self).certificate_status(domain, domain_type)
    }

    /// Asks the shared provider.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        (**self).is_renew_required(domain, domain_type)
    }

    /// Deletes through the shared provider.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        (**self).delete_certificate(domain, domain_type)
    }
}

/// Manages certificates through a provider.
///
/// ```no_run
/// use utopia_cdn::certificates::{Certificates, FastlyTls, Status};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let certificates = Certificates::new(FastlyTls::new("api-token", "tls-configuration-id"));
/// if certificates.is_renew_required("shop.example.com", None).await? {
///     certificates.issue_certificate("shop", "shop.example.com", None).await?;
/// }
/// match certificates.certificate_status("shop.example.com", None).await {
///     Ok(Status::Issued) => println!("serving"),
///     Ok(status) => println!("{status}"),
///     Err(utopia_cdn::Error::Certificate(blocked)) => {
///         for challenge in &blocked.challenges {
///             println!("{} {} -> {}", challenge.record_type, challenge.record_name, challenge.values.join(" or "));
///         }
///     }
///     Err(e) => return Err(e),
/// }
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates;
/// use Utopia\Cdn\Certificates\Provider\FastlyTls;
///
/// $certificates = new Certificates(new FastlyTls('api-token', 'tls-configuration-id'));
/// // if ($certificates->isRenewRequired('shop.example.com', null)) {
/// //     $certificates->issueCertificate('shop', 'shop.example.com', null);
/// // }
/// ```
///
/// PHP: `Utopia\Cdn\Certificates`.
#[derive(Debug, Clone)]
pub struct Certificates<P> {
    provider: P,
}

impl<P: Provider> Certificates<P> {
    /// Certificates managed by `provider`.
    ///
    /// PHP: `new Certificates($provider)`.
    pub fn new(provider: P) -> Self {
        Certificates { provider }
    }

    /// The provider.
    pub fn provider(&self) -> &P {
        &self.provider
    }

    /// See [`Provider::issue_certificate`].
    ///
    /// PHP: `issueCertificate($certName, $domain, $domainType)`.
    pub async fn issue_certificate(
        &self,
        cert_name: &str,
        domain: &str,
        domain_type: Option<&str>,
    ) -> Result<Option<String>, Error> {
        self.provider.issue_certificate(cert_name, domain, domain_type).await
    }

    /// See [`Provider::is_instant_generation`].
    ///
    /// PHP: `isInstantGeneration($domain, $domainType)`.
    pub async fn is_instant_generation(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, Error> {
        self.provider.is_instant_generation(domain, domain_type).await
    }

    /// See [`Provider::certificate_status`].
    ///
    /// PHP: `getCertificateStatus($domain, $domainType)`.
    pub async fn certificate_status(&self, domain: &str, domain_type: Option<&str>) -> Result<Status, Error> {
        self.provider.certificate_status(domain, domain_type).await
    }

    /// See [`Provider::is_renew_required`].
    ///
    /// PHP: `isRenewRequired($domain, $domainType)`.
    pub async fn is_renew_required(&self, domain: &str, domain_type: Option<&str>) -> Result<bool, Error> {
        self.provider.is_renew_required(domain, domain_type).await
    }

    /// See [`Provider::delete_certificate`].
    ///
    /// PHP: `deleteCertificate($domain, $domainType)`.
    pub async fn delete_certificate(&self, domain: &str, domain_type: Option<&str>) -> Result<(), Error> {
        self.provider.delete_certificate(domain, domain_type).await
    }
}
