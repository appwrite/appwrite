//! CloudFront's invalidation API, for multi-tenant distributions.

use std::time::Duration;

use utopia_client::{Adapter as Client, Request};

use crate::api::DefaultClient;
use crate::aws::Signature;
use crate::cache::Adapter;
use crate::xml::{self, Reply};
use crate::{BoxFuture, Error, domain};

/// Invalidates content on a CloudFront multi-tenant distribution.
///
/// A multi-tenant distribution is invalidated one distribution tenant at a
/// time, so every purge names a tenant. Domain purges find it themselves,
/// from the domain; key and zone purges carry no domain and use the tenant
/// this adapter was constructed for (without one they fail with
/// [`Error::UnsupportedOperation`]).
///
/// Cache keys are CloudFront cache tags: the values the origin lists in the
/// distribution's `CacheTagConfig` header. They travel in the same
/// invalidation batch as paths, prefixed with `#`.
///
/// ```no_run
/// use utopia_cdn::cache::{Adapter, CloudFront};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let cloudfront = CloudFront::new(
///     "access-key-id",
///     "secret-access-key",
///     "domain-",
///     Some("dt_main"),
///     None,
///     utopia_cdn::default_client(),
///     None,
///     None,
/// );
/// cloudfront.purge_domain("example.com").await?; // invalidates the tag `#domain-example.com`
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Cache\Adapter\CloudFront;
///
/// $cloudfront = new CloudFront('access-key-id', 'secret-access-key', 'domain-', 'dt_main');
/// // $cloudfront->purgeDomain('example.com');
/// ```
///
/// PHP: `Utopia\Cdn\Cache\Adapter\CloudFront`.
#[derive(Debug)]
pub struct CloudFront<C = DefaultClient> {
    signature: Signature,
    domain_key_prefix: String,
    tenant_id: Option<String>,
    client: C,
    api_base: String,
    batch_interval: u64,
}

impl CloudFront {
    /// CloudFront signs every request in us-east-1, whatever edge serves the
    /// content.
    ///
    /// PHP: `CloudFront::SIGNING_SERVICE`.
    pub const SIGNING_SERVICE: &str = "cloudfront";

    /// PHP: `CloudFront::SIGNING_REGION`.
    pub const SIGNING_REGION: &str = "us-east-1";

    /// PHP: `CloudFront::API_VERSION`.
    pub const API_VERSION: &str = "2020-05-31";

    /// Paths per invalidation. CloudFront's default quota is a rate: 150
    /// paths or tags processed per second, so a purge sends at most 150 a
    /// request, one request per batch interval.
    ///
    /// PHP: `CloudFront::PATHS_PER_PURGE`.
    pub const PATHS_PER_PURGE: usize = 150;

    /// Tags per invalidation. A tag counts as one path towards the same
    /// quota.
    ///
    /// PHP: `CloudFront::KEYS_PER_PURGE`.
    pub const KEYS_PER_PURGE: usize = 150;

    /// Times one invalidation is resent after CloudFront throttles it,
    /// waiting twice as long before each attempt, starting from the batch
    /// interval.
    ///
    /// PHP: `CloudFront::THROTTLE_RETRIES`.
    pub const THROTTLE_RETRIES: u32 = 3;

    /// CloudFront's documented ceiling for one cache tag.
    ///
    /// PHP: `CloudFront::KEY_MAX_LENGTH`.
    pub const KEY_MAX_LENGTH: usize = 256;
}

/// Error codes that mean "too much at once, try again", not "this request
/// is wrong".
const THROTTLE_CODES: [&str; 3] = ["Throttling", "TooManyInvalidationsInProgress", "TooManyRequests"];

impl<C: Client> CloudFront<C> {
    /// An adapter signing with these credentials (and `session_token`, when
    /// given), sending with `client` to `api_base`
    /// (`https://cloudfront.amazonaws.com` when `None`).
    ///
    /// `domain_key_prefix` prefixes the per-domain cache tag the origin
    /// attaches (pass `""` when the tag is the bare hostname); `tenant_id` is
    /// the distribution tenant key and zone purges invalidate (domain purges
    /// look theirs up); `batch_interval` is the milliseconds to wait between
    /// two invalidation requests of one purge (1000 when `None`), keeping it
    /// under CloudFront's per-second quota.
    ///
    /// PHP: `new CloudFront($accessKeyId, $secretAccessKey, $domainKeyPrefix,
    /// $tenantId, $sessionToken, $client, $apiBase, $batchInterval)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        access_key_id: &str,
        secret_access_key: &str,
        domain_key_prefix: &str,
        tenant_id: Option<&str>,
        session_token: Option<&str>,
        client: C,
        api_base: Option<&str>,
        batch_interval: Option<u64>,
    ) -> Self {
        CloudFront {
            signature: Signature::new(access_key_id, secret_access_key, session_token),
            domain_key_prefix: domain_key_prefix.to_owned(),
            tenant_id: tenant_id.map(str::to_owned),
            client,
            api_base: api_base.unwrap_or("https://cloudfront.amazonaws.com").to_owned(),
            batch_interval: batch_interval.unwrap_or(1000),
        }
    }

    async fn paths(&self, domain: &str, paths: &[String]) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        domain::validate_paths(paths)?;
        if paths.is_empty() {
            return Ok(());
        }
        let Some(tenant) = self.find_tenant(&domain).await? else {
            return Ok(());
        };
        let items: Vec<String> = paths.iter().map(|p| encode_path(p)).collect();
        self.invalidate(&tenant, &items, CloudFront::PATHS_PER_PURGE).await
    }

    async fn domain(&self, domain: &str) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        let tag = tag(&format!("{}{domain}", self.domain_key_prefix))?;
        let Some(tenant) = self.find_tenant(&domain).await? else {
            return Ok(());
        };
        self.invalidate(&tenant, &[tag], CloudFront::KEYS_PER_PURGE).await
    }

    async fn keys(&self, keys: &[String]) -> Result<(), Error> {
        if keys.is_empty() {
            return Ok(());
        }
        let tenant = self.require_tenant("cache key purging")?;
        let tags = keys.iter().map(|k| tag(k)).collect::<Result<Vec<_>, _>>()?;
        self.invalidate(tenant, &tags, CloudFront::KEYS_PER_PURGE).await
    }

    async fn zone(&self) -> Result<(), Error> {
        let tenant = self.require_tenant("zone purging")?;
        self.invalidate(tenant, &["/*".to_owned()], CloudFront::PATHS_PER_PURGE).await
    }

    /// Reported as an unsupported operation rather than a failure, as on
    /// Fastly without a service ID: domain purges still work, so this is a
    /// gap in configuration, not a purge that went wrong.
    fn require_tenant(&self, operation: &str) -> Result<&str, Error> {
        match self.tenant_id.as_deref() {
            Some(id) if !id.is_empty() => Ok(id),
            _ => Err(Error::UnsupportedOperation(format!(
                "CloudFront distribution tenant ID is required for {operation}."
            ))),
        }
    }

    /// The tenant serving the domain, or `None` when no tenant does: a
    /// domain that is not on CloudFront has nothing cached there.
    async fn find_tenant(&self, domain: &str) -> Result<Option<String>, Error> {
        let path = format!("/distribution-tenant?domain={}", php_std::encoding::rawurlencode(domain.as_bytes()));
        let reply = self.request("GET", &path, None).await?;
        if reply.status == 404 && error_code(&reply).as_deref() == Some("EntityNotFound") {
            return Ok(None);
        }
        if !reply.is_2xx() {
            return Err(Error::Runtime(format_error("tenant lookup", &reply)));
        }
        let id = reply.response.xml().and_then(|x| x.child("Id")).map(|e| e.text.clone()).unwrap_or_default();
        if id.is_empty() {
            return Err(Error::Runtime(format!("CloudFront tenant lookup for {domain} returned no tenant ID.")));
        }
        Ok(Some(id))
    }

    /// Sends the items in batches, pausing between them so a large purge
    /// spreads over as many seconds as CloudFront's quota needs.
    async fn invalidate(&self, tenant: &str, items: &[String], batch_size: usize) -> Result<(), Error> {
        let path = format!("/distribution-tenant/{}/invalidation", php_std::encoding::rawurlencode(tenant.as_bytes()));
        for (index, batch) in items.chunks(batch_size).enumerate() {
            if index > 0 {
                pause(self.batch_interval).await;
            }
            // Built once, so a retried batch keeps its caller reference.
            let body = invalidation_batch(batch);
            let mut reply = self.request("POST", &path, Some(&body)).await?;
            let mut retry = 0;
            while retry < CloudFront::THROTTLE_RETRIES && is_throttled(&reply) {
                pause(self.batch_interval * 2u64.pow(retry)).await;
                reply = self.request("POST", &path, Some(&body)).await?;
                retry += 1;
            }
            if !reply.is_2xx() {
                return Err(Error::Runtime(format_error("invalidation", &reply)));
            }
        }
        Ok(())
    }

    async fn request(&self, method: &str, path: &str, body: Option<&str>) -> Result<Reply, Error> {
        let url = format!("{}/{}{path}", self.api_base, CloudFront::API_VERSION);
        let mut request = Request::new(method, &url)?;
        if let Some(body) = body {
            request = request.body(body.to_owned()).header("Content-Type", "application/xml")?;
        }
        let request =
            request.header("User-Agent", "Utopia CDN CloudFront Adapter")?.header("Accept", "application/xml")?;
        xml::send(&self.client, &self.signature, request, true).await
    }
}

async fn pause(milliseconds: u64) {
    if milliseconds > 0 {
        utopia_async::sleep(Duration::from_millis(milliseconds)).await;
    }
}

/// `Error/Code` of an XML error body, untrimmed; `None` when empty.
fn error_code(reply: &Reply) -> Option<String> {
    let code = &reply.response.xml()?.child("Error")?.child("Code")?.text;
    (!code.is_empty()).then(|| code.clone())
}

fn is_throttled(reply: &Reply) -> bool {
    reply.status == 429 || error_code(reply).is_some_and(|code| THROTTLE_CODES.contains(&code.as_str()))
}

fn invalidation_batch(items: &[String]) -> String {
    let flags = php_std::encoding::HtmlFlags::from_php(16 | 3); // ENT_XML1 | ENT_QUOTES
    let paths: String = items
        .iter()
        .map(|item| {
            let escaped = php_std::encoding::htmlspecialchars(item.as_bytes(), flags, true);
            format!("<Path>{}</Path>", String::from_utf8_lossy(&escaped))
        })
        .collect();
    let mut reference = [0u8; 16];
    let _ = getrandom::fill(&mut reference);
    format!(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?><InvalidationBatch xmlns=\"http://cloudfront.amazonaws.com/doc/{}/\"><CallerReference>{}</CallerReference><Paths><Items>{paths}</Items><Quantity>{}</Quantity></Paths></InvalidationBatch>",
        CloudFront::API_VERSION,
        php_std::encoding::bin2hex(&reference),
        items.len()
    )
}

/// A cache tag is an invalidation path starting with `#`. Tags CloudFront
/// would never have stored are refused before sending, rather than failing
/// the whole batch they travel in.
fn tag(key: &str) -> Result<String, Error> {
    if key.is_empty() || key.len() > CloudFront::KEY_MAX_LENGTH {
        return Err(Error::InvalidArgument(format!(
            "CloudFront cache tags must be 1 to {} characters long.",
            CloudFront::KEY_MAX_LENGTH
        )));
    }
    if key.bytes().any(|b| !(33..=126).contains(&b) || b == b',') {
        return Err(Error::InvalidArgument(
            "CloudFront cache tags may contain only visible ASCII characters other than \",\".".into(),
        ));
    }
    Ok(format!("#{key}"))
}

/// CloudFront expects unsafe characters in an invalidation path to be
/// percent-encoded. `*` stays literal, because a trailing `*` is a
/// wildcard, and so does a `%` that already starts an escape; any other
/// `%` is a literal percent sign and is encoded.
fn encode_path(path: &str) -> String {
    let bytes = path.as_bytes();
    let mut encoded = String::with_capacity(bytes.len());
    for (i, &byte) in bytes.iter().enumerate() {
        if byte == b'%' {
            let escape = bytes.get(i + 1..i + 3);
            let kept = escape.is_some_and(|e| e.iter().all(u8::is_ascii_hexdigit));
            encoded.push_str(if kept { "%" } else { "%25" });
            continue;
        }
        if byte.is_ascii_alphanumeric() || b"-._~/?=&:+*".contains(&byte) {
            encoded.push(byte as char);
        } else {
            encoded.push_str(&php_std::encoding::rawurlencode(&[byte]));
        }
    }
    encoded
}

fn format_error(operation: &str, reply: &Reply) -> String {
    let mut message = reply.error.clone();
    if let Some(response) = reply.response.xml() {
        let error = response.child("Error");
        let detail = error.and_then(|e| e.child("Message")).map(|m| m.text.clone()).unwrap_or_default();
        let code = error_code(reply);
        if (!detail.is_empty() || code.is_some()) && message.is_none() {
            let joined = format!("{}: {detail}", code.as_deref().unwrap_or(""));
            message = Some(joined.trim_matches([':', ' ']).to_owned());
        }
    }
    format!(
        "CloudFront {operation} failed with status {}: {}",
        reply.status,
        message.as_deref().unwrap_or("Unknown purge error")
    )
}

impl<C: Client> Adapter for CloudFront<C> {
    /// Invalidates the paths on the tenant serving the domain. A tenant's
    /// cache is shared by every domain on it, so the same paths are
    /// invalidated for the tenant's other domains too.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.paths(domain, paths))
    }

    /// Invalidates the domain's cache tag on the tenant serving it, leaving
    /// the tenant's other domains cached.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.domain(domain))
    }

    /// Invalidates these cache tags on the configured tenant, 150 a request.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.keys(keys))
    }

    /// Invalidates every object on the configured tenant with a single `/*`
    /// wildcard path.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.zone())
    }
}
