//! Fastly TLS subscriptions and activations.

use std::sync::Arc;

use php_std::datetime::{DateTime, TzInfo};
use php_std::types::Value;
use utopia_client::Adapter as Client;

use crate::api::{self, DefaultClient, Reply};
use crate::certificates::{Challenge, Provider, Status};
use crate::{BoxFuture, Error};

/// The source file PHP names in a `TypeError` raised inside the provider.
const SOURCE: &str = "FastlyTls.php";

/// Certificates as Fastly TLS subscriptions, issued by a certificate
/// authority Fastly works with (`certainly` by default).
///
/// With a TLS configuration ID, an issued certificate is also activated on
/// every hostname of the subscription (so the hostname stops serving
/// Fastly's shared certificate), and a renewal moves the activations to the
/// new certificate. Without one, Fastly's domain management activates it.
///
/// While the certificate authority waits for the domain owner to publish a
/// DNS record, [`Provider::certificate_status`] fails with
/// [`Error::Certificate`], carrying the records to create.
///
/// ```no_run
/// use utopia_cdn::certificates::{FastlyTls, Provider};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let tls = FastlyTls::new("api-token", "tls-configuration-id");
/// let renew_after = tls.issue_certificate("shop", "shop.example.com", None).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates\Provider\FastlyTls;
///
/// $tls = new FastlyTls('api-token', 'tls-configuration-id');
/// // $renewAfter = $tls->issueCertificate('shop', 'shop.example.com', null);
/// ```
///
/// PHP: `Utopia\Cdn\Certificates\Provider\FastlyTls`.
#[derive(Debug)]
pub struct FastlyTls<C = DefaultClient> {
    api_token: String,
    tls_configuration_id: String,
    certificate_authority: String,
    client: Arc<C>,
    api_base: String,
}

/// A subscription and the resources Fastly included with it.
struct Subscription {
    resource: Value,
    included: Vec<Value>,
}

/// An existing activation: its ID and the certificate it activates.
struct Activation {
    id: String,
    certificate: String,
}

impl FastlyTls {
    /// Fastly's ACME DNS challenge: a CNAME at `_acme-challenge.<domain>`
    /// that proves ownership without changing where the domain's traffic
    /// goes.
    ///
    /// PHP: `FastlyTls::CHALLENGE_MANAGED_DNS`.
    pub const CHALLENGE_MANAGED_DNS: &str = "managed-dns";

    /// A provider activating certificates on a TLS configuration (`""` for
    /// none), ordering from `certainly`, sending with the [`DefaultClient`]
    /// to `https://api.fastly.com`.
    ///
    /// PHP: `new FastlyTls($apiToken, $tlsConfigurationId)`.
    pub fn new(api_token: impl Into<String>, tls_configuration_id: impl Into<String>) -> Self {
        FastlyTls::sharing(
            api_token.into(),
            tls_configuration_id.into(),
            "certainly".into(),
            Arc::new(api::default_client()),
            "https://api.fastly.com".into(),
        )
    }
}

impl<C: Client> FastlyTls<C> {
    pub(crate) fn sharing(
        api_token: String,
        tls_configuration_id: String,
        certificate_authority: String,
        client: Arc<C>,
        api_base: String,
    ) -> Self {
        FastlyTls { api_token, tls_configuration_id, certificate_authority, client, api_base }
    }

    /// The TLS configuration issued certificates are activated on (`""`
    /// for none).
    pub fn tls_configuration_id(&self) -> &str {
        &self.tls_configuration_id
    }

    /// The certificate authority subscriptions order from.
    pub fn certificate_authority(&self) -> &str {
        &self.certificate_authority
    }

    /// The same provider ordering from another certificate authority.
    ///
    /// PHP: the constructor's `$certificateAuthority`.
    pub fn with_certificate_authority(mut self, certificate_authority: impl Into<String>) -> Self {
        self.certificate_authority = certificate_authority.into();
        self
    }

    /// The same provider sending with another client.
    ///
    /// PHP: the constructor's `$client`.
    pub fn with_client<D: Client>(self, client: D) -> FastlyTls<D> {
        FastlyTls {
            api_token: self.api_token,
            tls_configuration_id: self.tls_configuration_id,
            certificate_authority: self.certificate_authority,
            client: Arc::new(client),
            api_base: self.api_base,
        }
    }

    /// The same provider calling another API base URL.
    ///
    /// PHP: the constructor's `$apiBase`.
    pub fn with_api_base(mut self, api_base: impl Into<String>) -> Self {
        self.api_base = api_base.into();
        self
    }

    async fn request(&self, method: &str, path: &str, body: Option<&Value>) -> Result<Reply, Error> {
        api::send(
            &*self.client,
            method,
            &format!("{}{path}", self.api_base),
            body,
            &[
                ("User-Agent", "Utopia CDN Fastly TLS Provider"),
                ("Fastly-Key", &self.api_token),
                ("Accept", "application/vnd.api+json"),
                ("Content-Type", "application/vnd.api+json"),
            ],
        )
        .await
    }

    pub(crate) async fn issue(&self, domain: &str) -> Result<Option<String>, Error> {
        let subscription = match self.find_subscription(domain).await? {
            None => self.create_subscription(domain).await?,
            Some(subscription) if state(&subscription.resource, 43)? == Status::Failed => {
                let id = argument(api::get(&subscription.resource, "id"), "retrySubscription", "subscriptionId", 44)?;
                self.retry_subscription(&id).await?
            }
            Some(subscription) => subscription,
        };
        let renew_date = extract_renew_date(&subscription)?;
        if renew_date.is_some() {
            self.ensure_activated(&subscription, domain).await?;
        }
        Ok(renew_date)
    }

    pub(crate) async fn status(&self, domain: &str) -> Result<Status, Error> {
        let Some(subscription) = self.find_subscription(domain).await? else {
            return Ok(Status::Unknown);
        };
        let status = state(&subscription.resource, 71)?;
        if status == Status::Issued && !self.ensure_activated(&subscription, domain).await? {
            return Ok(Status::Processing);
        }
        // An issued certificate needs nothing from the domain owner,
        // whatever an authorization left over from an earlier order says.
        if status == Status::Issued || status == Status::Unknown {
            return Ok(status);
        }
        // Fastly keeps the subscription pending while an authorization is
        // blocked on a record only the domain owner can add.
        let authorizations = find_authorizations(&subscription, domain);
        if status != Status::Failed && !is_blocked(&authorizations) {
            if status == Status::Renewing && !self.ensure_activated(&subscription, domain).await? {
                return Ok(Status::Processing);
            }
            return Ok(status);
        }
        let status = if status == Status::Failed { Status::Failed } else { Status::Blocked };
        let challenges = extract_challenges(&authorizations);
        let warnings = extract_warnings(&authorizations);
        Err(Error::certificate(describe(domain, status, &challenges, &warnings), status, challenges, warnings))
    }

    pub(crate) async fn renew_required(&self, domain: &str) -> Result<bool, Error> {
        match self.find_subscription(domain).await? {
            None => Ok(true),
            Some(subscription) => Ok(state(&subscription.resource, 110)? == Status::Failed),
        }
    }

    pub(crate) async fn delete(&self, domain: &str) -> Result<(), Error> {
        let Some(subscription) = self.find_subscription(domain).await? else {
            return Ok(());
        };
        let id = api::get(&subscription.resource, "id").map(api::php_string).unwrap_or_default();
        let reply = self.request("DELETE", &format!("/tls/subscriptions/{id}?force=true"), None).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("Failed to delete Fastly TLS subscription", &reply)));
        }
        Ok(())
    }

    async fn find_subscription(&self, domain: &str) -> Result<Option<Subscription>, Error> {
        let query = api::query(&[
            ("filter[tls_domains.id]", domain),
            ("include", "tls_certificates,tls_authorizations"),
            ("page[size]", "1"),
        ]);
        let reply = self.request("GET", &format!("/tls/subscriptions?{query}"), None).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("Failed to fetch Fastly TLS subscriptions", &reply)));
        }
        let Some(body) = reply.array() else {
            return Err(Error::Runtime("Fastly TLS subscriptions response was not valid JSON.".into()));
        };
        let Some(data @ Value::Array(_)) = api::get(body, "data") else {
            return Err(Error::Runtime("Fastly TLS subscriptions response was missing its data list.".into()));
        };
        let Some(resource) = api::at(data, 0) else {
            return Ok(None);
        };
        if !matches!(resource, Value::Array(_)) {
            return Err(Error::Runtime("Fastly TLS subscription resource was malformed.".into()));
        }
        let included = match api::get(body, "included") {
            None => Vec::new(),
            Some(included @ Value::Array(_)) => arrays(included),
            Some(_) => {
                return Err(Error::Runtime(
                    "Fastly TLS subscriptions response contained malformed included resources.".into(),
                ));
            }
        };
        Ok(Some(Subscription { resource: resource.clone(), included }))
    }

    async fn create_subscription(&self, domain: &str) -> Result<Subscription, Error> {
        let reference = |kind: &str, id: &str| api::object([("type", api::text(kind)), ("id", api::text(id))]);
        let tls_domains = api::object([("data", api::list(vec![reference("tls_domain", domain)]))]);
        let relationships = if self.tls_configuration_id.is_empty() {
            api::object([("tls_domains", tls_domains)])
        } else {
            api::object([
                ("tls_domains", tls_domains),
                ("common_name", api::object([("data", reference("tls_domain", domain))])),
                (
                    "tls_configuration",
                    api::object([("data", reference("tls_configuration", &self.tls_configuration_id))]),
                ),
            ])
        };
        let body = api::object([(
            "data",
            api::object([
                ("type", api::text("tls_subscription")),
                ("attributes", api::object([("certificate_authority", api::text(&self.certificate_authority))])),
                ("relationships", relationships),
            ]),
        )]);
        let reply = self.request("POST", "/tls/subscriptions", Some(&body)).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("Failed to create Fastly TLS subscription", &reply)));
        }
        subscription(
            &reply,
            "Fastly TLS subscription response was not valid JSON.",
            "Fastly TLS subscription response was missing data.",
        )
    }

    async fn retry_subscription(&self, id: &str) -> Result<Subscription, Error> {
        // A subscription fails on a renewal while its earlier certificate is
        // still deployed (an active domain, to Fastly), and Fastly refuses to
        // edit it without `force`. A retry only asks for issuance again, so
        // the deployed certificate keeps serving meanwhile.
        let body = api::object([(
            "data",
            api::object([
                ("id", api::text(id)),
                ("type", api::text("tls_subscription")),
                ("attributes", api::object([("state", api::text("retry"))])),
            ]),
        )]);
        let reply = self.request("PATCH", &format!("/tls/subscriptions/{id}?force=true"), Some(&body)).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("Failed to retry Fastly TLS subscription", &reply)));
        }
        subscription(
            &reply,
            "Fastly TLS retry response was not valid JSON.",
            "Fastly TLS retry response was missing data.",
        )
    }

    /// Activates the issued certificate on every hostname of the
    /// subscription; `false` when Fastly reports it issued before its
    /// certificate appears.
    async fn ensure_activated(&self, subscription: &Subscription, domain: &str) -> Result<bool, Error> {
        if self.tls_configuration_id.is_empty() {
            return Ok(true);
        }
        let Some(certificate) = issued_certificate_id(subscription) else {
            return Ok(false);
        };
        // Every hostname on the subscription, so the apex also activates www
        // when one certificate covers both.
        let mut hostnames: Vec<(String, String)> = Vec::new();
        for name in references(&subscription.resource, "tls_domains").into_iter().chain([domain.to_owned()]) {
            let folded = name.to_ascii_lowercase();
            if !hostnames.iter().any(|(f, _)| *f == folded) {
                hostnames.push((folded, name));
            }
        }
        for (_, hostname) in &hostnames {
            let Some(activation) = self.find_activation(hostname).await? else {
                self.create_activation(&certificate, hostname).await?;
                continue;
            };
            if activation.certificate == certificate {
                continue;
            }
            // A renewal leaves the hostname on the subscription's previous certificate.
            if !references(&subscription.resource, "tls_certificates").contains(&activation.certificate) {
                return Err(Error::Runtime(format!("Another certificate already terminates TLS for {hostname}.")));
            }
            self.update_activation(&activation.id, &certificate, hostname).await?;
        }
        Ok(true)
    }

    async fn find_activation(&self, hostname: &str) -> Result<Option<Activation>, Error> {
        let query = api::query(&[
            ("filter[tls_configuration.id]", &self.tls_configuration_id),
            ("filter[tls_domain.id]", hostname),
            ("page[size]", "1"),
        ]);
        let reply = self.request("GET", &format!("/tls/activations?{query}"), None).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("Failed to fetch Fastly TLS activations", &reply)));
        }
        let Some(body) = reply.array() else {
            return Err(Error::Runtime("Fastly TLS activations response was not valid JSON.".into()));
        };
        let Some(data @ Value::Array(_)) = api::get(body, "data") else {
            return Err(Error::Runtime("Fastly TLS activations response was missing its data list.".into()));
        };
        let Some(activation @ Value::Array(_)) = api::at(data, 0) else {
            return Ok(None);
        };
        let Some(id) = api::string(api::get(activation, "id")) else {
            return Ok(None);
        };
        let certificate = api::string(api::path(activation, &["relationships", "tls_certificate", "data", "id"]));
        Ok(Some(Activation { id: id.to_owned(), certificate: certificate.unwrap_or_default().to_owned() }))
    }

    async fn create_activation(&self, certificate: &str, hostname: &str) -> Result<(), Error> {
        let data = |kind: &str, id: &str| {
            api::object([("data", api::object([("type", api::text(kind)), ("id", api::text(id))]))])
        };
        let body = api::object([(
            "data",
            api::object([
                ("type", api::text("tls_activation")),
                (
                    "relationships",
                    api::object([
                        ("tls_certificate", data("tls_certificate", certificate)),
                        ("tls_configuration", data("tls_configuration", &self.tls_configuration_id)),
                        ("tls_domain", data("tls_domain", hostname)),
                    ]),
                ),
            ]),
        )]);
        let reply = self.request("POST", "/tls/activations", Some(&body)).await?;
        if reply.status == 409 {
            // A conflict is our own activation, made concurrently, or another
            // certificate already holding the hostname.
            if self.find_activation(hostname).await?.is_some_and(|a| a.certificate == certificate) {
                return Ok(());
            }
            return Err(Error::Runtime(format_error(
                &format!("Another certificate already terminates TLS for {hostname}"),
                &reply,
            )));
        }
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error(
                &format!("Failed to activate Fastly TLS certificate for {hostname}"),
                &reply,
            )));
        }
        Ok(())
    }

    async fn update_activation(&self, activation: &str, certificate: &str, hostname: &str) -> Result<(), Error> {
        let body = api::object([(
            "data",
            api::object([
                ("id", api::text(activation)),
                ("type", api::text("tls_activation")),
                (
                    "relationships",
                    api::object([(
                        "tls_certificate",
                        api::object([(
                            "data",
                            api::object([("type", api::text("tls_certificate")), ("id", api::text(certificate))]),
                        )]),
                    )]),
                ),
            ]),
        )]);
        let reply = self.request("PATCH", &format!("/tls/activations/{activation}"), Some(&body)).await?;
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error(
                &format!("Failed to move the Fastly TLS activation for {hostname}"),
                &reply,
            )));
        }
        Ok(())
    }
}

/// The arrays among `value`'s elements, in order.
fn arrays(value: &Value) -> Vec<Value> {
    api::elements(Some(value)).filter(|v| matches!(v, Value::Array(_))).cloned().collect()
}

/// The subscription a create or retry answered with.
fn subscription(reply: &Reply, not_json: &str, no_data: &str) -> Result<Subscription, Error> {
    let Some(body) = reply.array() else {
        return Err(Error::Runtime(not_json.into()));
    };
    let Some(resource @ Value::Array(_)) = api::get(body, "data") else {
        return Err(Error::Runtime(no_data.into()));
    };
    let included = match api::get(body, "included") {
        Some(included @ Value::Array(_)) => arrays(included),
        _ => Vec::new(),
    };
    Ok(Subscription { resource: resource.clone(), included })
}

/// `mapStatus($resource['attributes']['state'] ?? '')`, called on `line`
/// of the PHP source.
fn state(resource: &Value, line: u32) -> Result<Status, Error> {
    let name = match api::path(resource, &["attributes", "state"]) {
        Some(Value::Str(s)) => s.as_bytes().to_ascii_lowercase(),
        Some(value @ (Value::Array(_) | Value::Object(_))) => {
            return Err(type_error("mapStatus", "state", value, line));
        }
        _ => return Ok(Status::Unknown),
    };
    Ok(match name.as_slice() {
        b"pending" => Status::Pending,
        b"processing" => Status::Processing,
        b"issued" => Status::Issued,
        b"renewing" => Status::Renewing,
        b"failed" => Status::Failed,
        _ => Status::Unknown,
    })
}

/// A value passed to a `string` parameter of a PHP method without strict
/// types: scalars become strings, anything else is a `TypeError`.
fn argument(value: Option<&Value>, method: &str, parameter: &str, line: u32) -> Result<String, Error> {
    match value {
        None => Err(type_error(method, parameter, &Value::Null, line)),
        Some(value @ (Value::Array(_) | Value::Object(_))) => Err(type_error(method, parameter, value, line)),
        Some(value) => Ok(api::php_string(value)),
    }
}

fn type_error(method: &str, parameter: &str, value: &Value, line: u32) -> Error {
    Error::Type(format!(
        "Utopia\\Cdn\\Certificates\\Provider\\FastlyTls::{method}(): Argument #1 (${parameter}) must be of type string, {} given, called in {SOURCE} on line {line}",
        value.debug_type()
    ))
}

/// The ids of a relationship's references that are strings.
fn references(resource: &Value, relationship: &str) -> Vec<String> {
    api::elements(api::path(resource, &["relationships", relationship, "data"]))
        .filter(|r| matches!(r, Value::Array(_)))
        .filter_map(|r| api::string(api::get(r, "id")))
        .map(str::to_owned)
        .collect()
}

/// Whether `value` is a string equal to one of `ids`.
fn listed(value: Option<&Value>, ids: &[String]) -> bool {
    api::string(value).is_some_and(|id| ids.iter().any(|i| i == id))
}

/// The subscription's authorizations for the domain, as Fastly included
/// them.
fn find_authorizations<'s>(subscription: &'s Subscription, domain: &str) -> Vec<&'s Value> {
    let references = api::path(&subscription.resource, &["relationships", "tls_authorizations", "data"]);
    let ids: Option<Vec<String>> = match references {
        Some(Value::Array(_)) => Some(
            api::elements(references)
                .filter(|r| matches!(r, Value::Array(_)))
                .filter_map(|r| api::string(api::get(r, "id")))
                .map(str::to_owned)
                .collect(),
        ),
        _ => None,
    };
    subscription
        .included
        .iter()
        .filter(|included| api::string(api::get(included, "type")) == Some("tls_authorization"))
        .filter(|included| ids.as_ref().is_none_or(|ids| listed(api::get(included, "id"), ids)))
        .filter(|included| {
            let authorized = api::string(api::path(included, &["relationships", "tls_domain", "data", "id"]));
            authorized.is_none_or(|d| d.eq_ignore_ascii_case(domain))
        })
        .collect()
}

fn is_blocked(authorizations: &[&Value]) -> bool {
    authorizations
        .iter()
        .any(|a| api::string(api::path(a, &["attributes", "state"])).is_some_and(|s| s.eq_ignore_ascii_case("blocked")))
}

fn extract_challenges(authorizations: &[&Value]) -> Vec<Challenge> {
    let mut challenges = Vec::new();
    for authorization in authorizations {
        for challenge in api::elements(api::path(authorization, &["attributes", "challenges"])) {
            if !matches!(challenge, Value::Array(_)) {
                continue;
            }
            let (Some(kind), Some(record_type), Some(record_name)) = (
                api::string(api::get(challenge, "type")),
                api::string(api::get(challenge, "record_type")),
                api::string(api::get(challenge, "record_name")),
            ) else {
                continue;
            };
            let Some(values @ Value::Array(_)) = api::get(challenge, "values") else {
                continue;
            };
            if record_name.is_empty() {
                continue;
            }
            let values: Vec<String> = api::elements(Some(values))
                .filter_map(|v| api::string(Some(v)))
                .filter(|v| !v.is_empty())
                .map(str::to_owned)
                .collect();
            if values.is_empty() {
                continue;
            }
            challenges.push(Challenge {
                kind: kind.to_owned(),
                record_type: record_type.to_ascii_uppercase(),
                record_name: record_name.to_owned(),
                values,
            });
        }
    }
    challenges
}

fn extract_warnings(authorizations: &[&Value]) -> Vec<String> {
    let mut warnings: Vec<String> = Vec::new();
    for authorization in authorizations {
        for warning in api::elements(api::path(authorization, &["attributes", "warnings"])) {
            let Some(instructions) = api::string(api::get(warning, "instructions")) else {
                continue;
            };
            let instructions = php_std::string::trim(instructions.as_bytes(), b" \n\r\t\x0B\0");
            let instructions = String::from_utf8_lossy(instructions).into_owned();
            if !instructions.is_empty() && !warnings.contains(&instructions) {
                warnings.push(instructions);
            }
        }
    }
    warnings
}

fn describe(domain: &str, status: Status, challenges: &[Challenge], warnings: &[String]) -> String {
    let mut message = if status == Status::Failed {
        format!("Fastly stopped trying to issue a certificate for {domain}.")
    } else {
        format!("Fastly cannot issue a certificate for {domain} until its ownership is verified.")
    };
    for warning in warnings {
        message.push(' ');
        message.push_str(warning.trim_end_matches('.'));
        message.push('.');
    }
    match challenges {
        [] => {}
        [challenge] => message.push_str(&format!(
            " Create a {} record for {} pointing to {}.",
            challenge.record_type,
            challenge.record_name,
            challenge.values.join(" or ")
        )),
        _ => {
            let records: Vec<String> = challenges
                .iter()
                .map(|c| format!("{} {} -> {}", c.record_type, c.record_name, c.values.join(" or ")))
                .collect();
            message.push_str(&format!(" Create one of these DNS records: {}.", records.join("; ")));
        }
    }
    message
}

/// `strtotime()` of a date string, in UTC (PHP's `date.timezone`).
fn timestamp(date: &str) -> Option<i64> {
    php_std::datetime::strtotime(date.as_bytes(), php_std::datetime::time(), &TzInfo::utc())
}

/// The latest-expiring certificate of the subscription, so a renewal
/// activates the new one; without an expiry to compare, the last
/// reference.
fn issued_certificate_id(subscription: &Subscription) -> Option<String> {
    let ids = references(&subscription.resource, "tls_certificates");
    let mut best: Option<(i64, String)> = None;
    for included in &subscription.included {
        if api::string(api::get(included, "type")) != Some("tls_certificate") || !listed(api::get(included, "id"), &ids)
        {
            continue;
        }
        let Some(expiry) = api::string(api::path(included, &["attributes", "not_after"])).and_then(timestamp) else {
            continue;
        };
        if best.as_ref().is_none_or(|(b, _)| expiry > *b) {
            best = api::string(api::get(included, "id")).map(|id| (expiry, id.to_owned()));
        }
    }
    best.map(|(_, id)| id).or_else(|| ids.last().cloned())
}

/// Thirty days before the latest expiry among the subscription's issued
/// certificates (`Y-m-d H:i:s.v`), when it is issued or renewing.
fn extract_renew_date(subscription: &Subscription) -> Result<Option<String>, Error> {
    let state = state(&subscription.resource, 642)?;
    if state != Status::Issued && state != Status::Renewing {
        return Ok(None);
    }
    let ids = references(&subscription.resource, "tls_certificates");
    let mut dates: Vec<(usize, &str)> = subscription
        .included
        .iter()
        .filter(|i| api::string(api::get(i, "type")) == Some("tls_certificate") && listed(api::get(i, "id"), &ids))
        .filter_map(|i| api::string(api::path(i, &["attributes", "not_after"])))
        .filter(|d| !d.is_empty())
        .enumerate()
        .collect();
    if dates.is_empty() {
        return Ok(None);
    }
    // usort by strtotime(), latest first; PHP's sort is stable.
    let times: Vec<Option<i64>> = dates.iter().map(|(_, d)| timestamp(d)).collect();
    php_std::sort::zend_sort(&mut dates, &mut |(a, _), (b, _)| match compare(times[*b], times[*a]) {
        0 => (*a as i32) - (*b as i32),
        c => c,
    });
    let latest = dates[0].1;
    let date = DateTime::new(latest.as_bytes(), None, &TzInfo::utc())
        .and_then(|d| d.modified(b"-30 days"))
        .map_err(|e| Error::Date { class: e.php_class(), message: e.message().into_owned() })?;
    Ok(Some(String::from_utf8_lossy(&date.format(b"Y-m-d H:i:s.v")).into_owned()))
}

/// `$a <=> $b` of two `strtotime()` results (`false` is `None`).
fn compare(a: Option<i64>, b: Option<i64>) -> i32 {
    let as_bool = |t: Option<i64>| t.is_some_and(|t| t != 0);
    match (a, b) {
        (Some(a), Some(b)) => a.cmp(&b) as i32,
        (a, b) => as_bool(a).cmp(&as_bool(b)) as i32,
    }
}

fn format_error(prefix: &str, reply: &Reply) -> String {
    let message = api::fastly_message(reply);
    format!("{prefix} with status {}: {}", reply.status, message.as_deref().unwrap_or("Unknown Fastly TLS error"))
}

impl<C: Client> Provider for FastlyTls<C> {
    /// Creates the subscription (or retries a failed one); once a
    /// certificate is issued, activates it and returns thirty days before
    /// it expires.
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

    /// The subscription's state; [`Status::Processing`] while an issued
    /// certificate is not activated yet; [`Error::Certificate`] when an
    /// authorization is blocked on the domain owner or the subscription
    /// failed.
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(self.status(domain))
    }

    /// Whether there is no subscription, or it failed.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.renew_required(domain))
    }

    /// Deletes the subscription (forced, so a deployed certificate goes too).
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.delete(domain))
    }
}
