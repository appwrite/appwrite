//! Cloudflare for SaaS custom hostnames.

use php_std::types::Value;
use utopia_client::Adapter as Client;

use crate::api::{self, DefaultClient, Reply};
use crate::certificates::{Provider, Status};
use crate::{BoxFuture, Error, domain};

/// Certificates as Cloudflare for SaaS custom hostnames: issuing creates
/// the hostname with an HTTP-validated DV certificate, which Cloudflare
/// manages from then on; a hostname that already exists is left as it is.
///
/// Cloudflare reports no status ([`Provider::certificate_status`] fails
/// with [`Error::UnsupportedOperation`]); a renewal is required when the
/// hostname is gone.
///
/// ```no_run
/// use utopia_cdn::certificates::{Cloudflare, Provider};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let cloudflare = Cloudflare::new("zone-id", "api-token");
/// cloudflare.issue_certificate("shop", "shop.example.com", None).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates\Provider\Cloudflare;
///
/// $cloudflare = new Cloudflare('zone-id', 'api-token');
/// // $cloudflare->issueCertificate('shop', 'shop.example.com', null);
/// ```
///
/// PHP: `Utopia\Cdn\Certificates\Provider\Cloudflare`.
#[derive(Debug)]
pub struct Cloudflare<C = DefaultClient> {
    zone_id: String,
    api_token: String,
    client: C,
    api_base: String,
}

impl Cloudflare {
    /// A provider for a zone, sending with the [`DefaultClient`] to
    /// `https://api.cloudflare.com/client/v4`.
    ///
    /// PHP: `new Cloudflare($zoneId, $apiToken)`.
    pub fn new(zone_id: impl Into<String>, api_token: impl Into<String>) -> Self {
        Cloudflare {
            zone_id: zone_id.into(),
            api_token: api_token.into(),
            client: api::default_client(),
            api_base: "https://api.cloudflare.com/client/v4".into(),
        }
    }
}

impl<C: Client> Cloudflare<C> {
    /// The same provider sending with another client.
    ///
    /// PHP: the constructor's `$client`.
    pub fn with_client<D: Client>(self, client: D) -> Cloudflare<D> {
        Cloudflare { zone_id: self.zone_id, api_token: self.api_token, client, api_base: self.api_base }
    }

    /// The same provider calling another API base URL.
    ///
    /// PHP: the constructor's `$apiBase`.
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self
    }

    fn hostnames(&self) -> String {
        format!("{}/zones/{}/custom_hostnames", self.api_base, self.zone_id)
    }

    async fn request(&self, method: &str, url: &str, body: Option<&Value>) -> Result<Reply, Error> {
        let authorization = format!("Bearer {}", self.api_token);
        api::send(
            &self.client,
            method,
            url,
            body,
            &[
                ("User-Agent", "Utopia CDN Cloudflare Certificates Provider"),
                ("Authorization", &authorization),
                ("Content-Type", "application/json"),
            ],
        )
        .await
    }

    async fn issue(&self, domain: &str) -> Result<Option<String>, Error> {
        let domain = domain::validate(domain)?;
        let body = api::object([
            ("hostname", api::text(&domain)),
            (
                "ssl",
                api::object([
                    ("method", api::text("http")),
                    ("type", api::text("dv")),
                    ("wildcard", Value::Bool(false)),
                ]),
            ),
        ]);
        let reply = self.request("POST", &self.hostnames(), Some(&body)).await?;
        if is_duplicate(&reply) {
            return Ok(None);
        }
        assert_success("create Cloudflare custom hostname", &reply, Some(&[201]))?;
        Ok(None)
    }

    async fn find(&self, domain: &str) -> Result<Option<Value>, Error> {
        let url = format!("{}?{}", self.hostnames(), api::query(&[("hostname", domain)]));
        let reply = self.request("GET", &url, None).await?;
        assert_success("fetch Cloudflare custom hostnames", &reply, None)?;
        let Some(body) = reply.array() else {
            return Err(Error::Runtime("Cloudflare custom hostname response was not valid JSON.".into()));
        };
        let Some(hostnames @ Value::Array(_)) = api::get(body, "result") else {
            return Err(Error::Runtime("Cloudflare custom hostname response was missing its result list.".into()));
        };
        Ok(api::elements(Some(hostnames))
            .find(|h| matches!(h, Value::Array(_)) && api::string(api::get(h, "hostname")) == Some(domain))
            .cloned())
    }

    async fn delete(&self, domain: &str) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        let Some(hostname) = self.find(&domain).await? else {
            return Ok(());
        };
        let id = match api::string(api::get(&hostname, "id")) {
            Some(id) if !id.is_empty() => php_std::encoding::rawurlencode(id.as_bytes()),
            _ => return Err(Error::Runtime("Cloudflare custom hostname response was missing an ID.".into())),
        };
        let reply = self.request("DELETE", &format!("{}/{id}", self.hostnames()), None).await?;
        assert_success("delete Cloudflare custom hostname", &reply, None)
    }
}

fn is_duplicate(reply: &Reply) -> bool {
    reply.array().is_some_and(|body| {
        let code = api::get(body, "errors").and_then(|e| api::at(e, 0)).and_then(|e| api::get(e, "code"));
        code == Some(&Value::Int(1406))
    })
}

fn assert_success(operation: &str, reply: &Reply, expected: Option<&[i64]>) -> Result<(), Error> {
    let http = match expected {
        Some(statuses) => statuses.contains(&reply.status),
        None => reply.is_2xx(),
    };
    let envelope = match reply.array() {
        Some(Value::Array(body)) => body.get_str("success").is_none_or(|s| *s == Value::Bool(true)),
        _ => true,
    };
    if http && envelope {
        return Ok(());
    }
    let message = reply.error.clone().or_else(|| {
        let body = reply.array()?;
        let first = api::get(body, "errors").and_then(|e| api::at(e, 0));
        first.and_then(|e| api::get(e, "message")).map(api::php_string)
    });
    Err(Error::Runtime(format!(
        "Failed to {operation} with status {}: {}",
        reply.status,
        message.as_deref().unwrap_or("Unknown Cloudflare error")
    )))
}

impl<C: Client> Provider for Cloudflare<C> {
    /// Creates the custom hostname; returns no renewal date, since
    /// Cloudflare renews on its own.
    fn issue_certificate<'a>(
        &'a self,
        _cert_name: &'a str,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        Box::pin(self.issue(domain))
    }

    /// Always: Cloudflare serves the hostname as soon as it exists (once
    /// the domain is checked).
    fn is_instant_generation<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async move { domain::validate(domain).map(|_| true) })
    }

    /// Unsupported: fails with [`Error::UnsupportedOperation`].
    fn certificate_status<'a>(
        &'a self,
        _domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(async {
            Err(Error::UnsupportedOperation(
                "Certificate status retrieval is not supported by the Cloudflare provider.".into(),
            ))
        })
    }

    /// Whether the custom hostname is missing.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async move {
            let domain = domain::validate(domain)?;
            Ok(self.find(&domain).await?.is_none())
        })
    }

    /// Deletes the custom hostname, if there is one.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.delete(domain))
    }
}
