//! Fastly domains together with their TLS subscriptions.

use std::sync::Arc;
use std::time::Duration;

use php_std::encoding::rawurlencode;
use php_std::types::Value;
use utopia_client::Adapter as Client;

use crate::api::{self, DefaultClient, Reply};
use crate::certificates::{FastlyTls, Provider, Status};
use crate::{BoxFuture, Error, domain};

/// Certificates for domains a Fastly service serves: the domain is added to
/// the service through Fastly's domain management, then certified by a
/// [`FastlyTls`] subscription sharing this provider's client.
///
/// A domain on another service moves to this one only once its certificate
/// is issued, so a failure never moves a live hostname. A domain on a
/// classic service version is left alone when issuing; deleting it clones
/// and activates a version without it, polls until that version is
/// deployed, then deletes its subscription.
///
/// ```no_run
/// use utopia_cdn::certificates::{Fastly, Provider};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let fastly = Fastly::new("api-token", "service-id").with_tls_configuration_id("tls-configuration-id");
/// fastly.issue_certificate("shop", "shop.example.com", None).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates\Provider\Fastly;
///
/// $fastly = new Fastly('api-token', 'service-id', tlsConfigurationId: 'tls-configuration-id');
/// // $fastly->issueCertificate('shop', 'shop.example.com', null);
/// ```
///
/// PHP: `Utopia\Cdn\Certificates\Provider\Fastly`.
#[derive(Debug)]
pub struct Fastly<C = DefaultClient> {
    api_token: String,
    service_id: String,
    client: Arc<C>,
    api_base: String,
    poll_attempts: i64,
    poll_interval: i64,
    tls: FastlyTls<C>,
}

impl Fastly {
    /// A provider for a service, ordering from `certainly` without a TLS
    /// configuration, polling a deployment 10 times 5 seconds apart, sending
    /// with the [`DefaultClient`] to `https://api.fastly.com`.
    ///
    /// PHP: `new Fastly($apiToken, $serviceId)`.
    pub fn new(api_token: impl Into<String>, service_id: impl Into<String>) -> Self {
        Fastly::with(
            api_token.into(),
            service_id.into(),
            Arc::new(api::default_client()),
            "https://api.fastly.com".into(),
        )
    }
}

impl<C: Client> Fastly<C> {
    fn with(api_token: String, service_id: String, client: Arc<C>, api_base: String) -> Self {
        let tls =
            FastlyTls::sharing(api_token.clone(), String::new(), "certainly".into(), client.clone(), api_base.clone());
        Fastly { api_token, service_id, client, api_base, poll_attempts: 10, poll_interval: 5000, tls }
    }

    /// The same provider ordering from another certificate authority.
    ///
    /// PHP: the constructor's `$certificateAuthority`.
    pub fn with_certificate_authority(mut self, certificate_authority: impl Into<String>) -> Self {
        self.tls = self.tls.with_certificate_authority(certificate_authority);
        self
    }

    /// The same provider activating issued certificates on a TLS
    /// configuration (see [`FastlyTls`]).
    ///
    /// PHP: the constructor's `$tlsConfigurationId`.
    pub fn with_tls_configuration_id(mut self, tls_configuration_id: impl Into<String>) -> Self {
        self.tls = FastlyTls::sharing(
            self.api_token.clone(),
            tls_configuration_id.into(),
            self.tls_authority(),
            self.client.clone(),
            self.api_base.clone(),
        );
        self
    }

    /// How often, and how many milliseconds apart, a classic service
    /// version is checked for deployment after a domain is removed from it.
    ///
    /// Fails with [`Error::InvalidArgument`] for fewer than one attempt or a
    /// negative interval.
    ///
    /// PHP: the constructor's `$deploymentPollAttempts` and
    /// `$deploymentPollIntervalMilliseconds`.
    pub fn with_deployment_polling(mut self, attempts: i64, interval_milliseconds: i64) -> Result<Self, Error> {
        if attempts < 1 {
            return Err(Error::InvalidArgument("Deployment poll attempts must be at least one.".into()));
        }
        if interval_milliseconds < 0 {
            return Err(Error::InvalidArgument("Deployment poll interval cannot be negative.".into()));
        }
        self.poll_attempts = attempts;
        self.poll_interval = interval_milliseconds;
        Ok(self)
    }

    /// The same provider sending with another client (its subscriptions
    /// too).
    ///
    /// PHP: the constructor's `$client`.
    pub fn with_client<D: Client>(self, client: D) -> Fastly<D> {
        let authority = self.tls_authority();
        let configuration = self.tls.tls_configuration_id().to_owned();
        let mut fastly = Fastly::with(self.api_token, self.service_id, Arc::new(client), self.api_base);
        fastly.poll_attempts = self.poll_attempts;
        fastly.poll_interval = self.poll_interval;
        fastly.with_tls_configuration_id(configuration).with_certificate_authority(authority)
    }

    /// The same provider calling another API base URL (its subscriptions
    /// too).
    ///
    /// PHP: the constructor's `$apiBase`.
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self.tls = self.tls.with_api_base(self.api_base.clone());
        self
    }

    fn tls_authority(&self) -> String {
        self.tls.certificate_authority().to_owned()
    }

    async fn request(&self, method: &str, path: &str, body: Option<&Value>) -> Result<Reply, Error> {
        api::send(
            &*self.client,
            method,
            &format!("{}{path}", self.api_base),
            body,
            &[
                ("User-Agent", "Utopia CDN Fastly Certificates Provider"),
                ("Fastly-Key", &self.api_token),
                ("Accept", "application/json"),
            ],
        )
        .await
    }

    async fn find_domain(&self, domain: &str) -> Result<Option<Value>, Error> {
        let query = api::query(&[("fqdn", domain), ("limit", "100")]);
        let reply = self.request("GET", &format!("/domain-management/v1/domains?{query}"), None).await?;
        assert_success("fetch Fastly domains", &reply, None)?;
        let Some(body) = reply.array() else {
            return Err(Error::Runtime("Fastly domains response was not valid JSON.".into()));
        };
        let Some(domains @ Value::Array(_)) = api::get(body, "data") else {
            return Err(Error::Runtime("Fastly domains response was missing its data list.".into()));
        };
        Ok(api::elements(Some(domains))
            .find(|c| matches!(c, Value::Array(_)) && api::string(api::get(c, "fqdn")) == Some(domain))
            .cloned())
    }

    async fn issue(&self, domain: &str) -> Result<Option<String>, Error> {
        let domain = domain::validate(domain)?;
        let info = self.find_domain(&domain).await?;
        if let Some(info) = &info {
            let Some(existing) = api::string(api::get(info, "service_id")).filter(|s| !s.is_empty()) else {
                // A classic domain cannot move without activating a new
                // service version, so its certificate is left untouched.
                return Ok(None);
            };
            if existing != self.service_id {
                let Some(id) = api::string(api::get(info, "id")).filter(|s| !s.is_empty()) else {
                    return Err(Error::Runtime("Fastly domain response was missing its ID.".into()));
                };
                // Finish the certificate while ownership is unchanged: a TLS
                // failure must not move a live hostname.
                let renew_date = self.tls.issue(&domain).await?;
                let status = match self.tls.status(&domain).await {
                    Ok(status) => status,
                    // Waiting on the domain owner: nothing is issued yet, so
                    // the hostname stays on its current service.
                    Err(Error::Certificate(blocked)) if blocked.is_blocked() => return Ok(renew_date),
                    Err(e) => return Err(e),
                };
                if status != Status::Issued && status != Status::Renewing {
                    return Ok(renew_date);
                }
                let body = api::object([("service_id", api::text(&self.service_id))]);
                let path = format!("/domain-management/v1/domains/{}", rawurlencode(id.as_bytes()));
                let reply = self.request("PATCH", &path, Some(&body)).await?;
                assert_success("reassign Fastly domain", &reply, None)?;
                return Ok(renew_date);
            }
        }
        if info.is_none() {
            let body = api::object([("fqdn", api::text(&domain)), ("service_id", api::text(&self.service_id))]);
            let reply = self.request("POST", "/domain-management/v1/domains", Some(&body)).await?;
            assert_success("add Fastly domain", &reply, Some(&[201]))?;
        }
        self.tls.issue(&domain).await
    }

    async fn renew_required(&self, domain: &str) -> Result<bool, Error> {
        let domain = domain::validate(domain)?;
        let Some(info) = self.find_domain(&domain).await? else {
            return Ok(true);
        };
        match api::string(api::get(&info, "service_id")) {
            Some(service) if !service.is_empty() => {
                if service != self.service_id {
                    return Ok(true);
                }
                self.tls.renew_required(&domain).await
            }
            _ => Ok(false),
        }
    }

    async fn delete(&self, domain: &str) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        let Some(info) = self.find_domain(&domain).await? else {
            return self.tls.delete(&domain).await;
        };
        match api::string(api::get(&info, "service_id")) {
            Some(service) if !service.is_empty() => {
                if service != self.service_id {
                    return Ok(());
                }
                self.delete_versionless(&info).await
            }
            _ => self.delete_classic(&domain).await,
        }
    }

    async fn delete_versionless(&self, info: &Value) -> Result<(), Error> {
        let id = api::string(api::get(info, "id")).filter(|s| !s.is_empty());
        let fqdn = api::string(api::get(info, "fqdn")).filter(|s| !s.is_empty());
        let (Some(id), Some(fqdn)) = (id, fqdn) else {
            return Err(Error::Runtime("Fastly domain response was missing its ID or FQDN.".into()));
        };
        let path = format!("/domain-management/v1/domains/{}", rawurlencode(id.as_bytes()));
        let reply = self.request("DELETE", &path, None).await?;
        assert_success("delete Fastly domain", &reply, Some(&[204]))?;
        self.tls.delete(fqdn).await
    }

    async fn delete_classic(&self, domain: &str) -> Result<(), Error> {
        let service = rawurlencode(self.service_id.as_bytes());
        let reply = self.request("GET", &format!("/service/{service}/details"), None).await?;
        assert_success("fetch Fastly service details", &reply, None)?;
        let Some(body) = reply.array() else {
            return Err(Error::Runtime("Fastly service details response was not valid JSON.".into()));
        };
        let Some(active @ Value::Array(_)) = api::get(body, "active_version") else {
            return Err(Error::Runtime("Fastly service details response was missing its active version.".into()));
        };
        let contains = api::elements(api::get(active, "domains"))
            .any(|c| matches!(c, Value::Array(_)) && api::string(api::get(c, "name")) == Some(domain));
        if !contains {
            // Classic domain records do not name their service: a match
            // missing from this service may belong to another, whose TLS
            // must stay.
            return Ok(());
        }
        let Some(Value::Int(current)) = api::get(active, "number") else {
            return Err(Error::Runtime("Fastly active service version was missing its number.".into()));
        };
        let versions = format!("/service/{service}/version/");
        let reply = self.request("PUT", &format!("{versions}{current}/clone"), None).await?;
        assert_success("clone Fastly service version", &reply, None)?;
        let Some(Value::Int(version)) = reply.array().and_then(|b| api::get(b, "number")) else {
            return Err(Error::Runtime("Fastly cloned service version was missing its number.".into()));
        };
        let version = *version;
        let path = format!("{versions}{version}/domain/{}", rawurlencode(domain.as_bytes()));
        let reply = self.request("DELETE", &path, None).await?;
        assert_success("remove classic Fastly domain", &reply, Some(&[200]))?;
        let reply = self.request("PUT", &format!("{versions}{version}/activate"), None).await?;
        assert_success("activate Fastly service version", &reply, None)?;
        self.wait_for_deployment(&service, version).await?;
        self.tls.delete(domain).await
    }

    async fn wait_for_deployment(&self, service: &str, version: i64) -> Result<(), Error> {
        let path = format!("/service/{service}/version/{version}");
        for attempt in 1..=self.poll_attempts {
            let reply = self.request("GET", &path, None).await?;
            assert_success("fetch Fastly service version", &reply, None)?;
            if reply.array().and_then(|b| api::get(b, "active")) == Some(&Value::Bool(true)) {
                return Ok(());
            }
            if attempt < self.poll_attempts && self.poll_interval > 0 {
                tokio::time::sleep(Duration::from_millis(self.poll_interval as u64)).await;
            }
        }
        Err(Error::Runtime(format!("Fastly service version was not deployed after {} attempts.", self.poll_attempts)))
    }
}

fn assert_success(operation: &str, reply: &Reply, expected: Option<&[i64]>) -> Result<(), Error> {
    let success = match expected {
        Some(statuses) => statuses.contains(&reply.status),
        None => reply.is_2xx(),
    };
    if success {
        return Ok(());
    }
    let message = api::fastly_message(reply);
    Err(Error::Runtime(format!(
        "Failed to {operation} with status {}: {}",
        reply.status,
        message.as_deref().unwrap_or("Unknown Fastly error")
    )))
}

impl<C: Client> Provider for Fastly<C> {
    /// Adds the domain to the service (or moves it from another service
    /// once its certificate is issued), then issues through [`FastlyTls`].
    fn issue_certificate<'a>(
        &'a self,
        _cert_name: &'a str,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        Box::pin(self.issue(domain))
    }

    /// Never: Fastly issues asynchronously.
    fn is_instant_generation<'a>(
        &'a self,
        _domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async { Ok(false) })
    }

    /// The subscription's status (see [`FastlyTls`]).
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(async move { self.tls.status(&domain::validate(domain)?).await })
    }

    /// Whether the domain is missing or on another service, or its
    /// subscription needs issuing; never for a classic domain.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.renew_required(domain))
    }

    /// Removes the domain from this service and deletes its subscription;
    /// leaves a domain of another service alone.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.delete(domain))
    }
}
