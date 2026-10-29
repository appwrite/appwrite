//! Certificates for domains on a CloudFront multi-tenant distribution.

use php_std::datetime::{DateTime, TzInfo};
use utopia_client::{Adapter as Client, Request};

use crate::api::DefaultClient;
use crate::aws::Signature;
use crate::certificates::{Provider, Status, ValidationToken};
use crate::xml::{self, Element, Reply};
use crate::{BoxFuture, Error, domain};

const NAMESPACE: &str = "http://cloudfront.amazonaws.com/doc/2020-05-31/";

const API_VERSION: &str = "2020-05-31";

/// Tenant names are 3 to 128 characters of letters, digits, `-` and `.`.
const NAME_LENGTH: usize = 128;

/// Certificates for domains served by a CloudFront multi-tenant
/// distribution.
///
/// Every domain gets its own distribution tenant, and the tenant asks
/// CloudFront for an AWS-managed certificate. AWS issues the certificate
/// and renews it for as long as the domain points at CloudFront, so there
/// is no key, no ACME account and nothing to import.
///
/// ```no_run
/// use utopia_cdn::certificates::{CloudFront, Provider};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let cloudfront = CloudFront::new(
///     "access-key-id",
///     "secret-access-key",
///     "EDFDVBD6EXAMPLE",
///     None,
///     None,
///     &[],
///     None,
///     utopia_cdn::default_client(),
///     None,
/// )?;
/// cloudfront.issue_certificate("cert", "example.com", None).await?;
/// for token in cloudfront.validation_tokens("example.com").await? {
///     println!("redirect {} to {}", token.from, token.to);
/// }
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Certificates\Provider\CloudFront;
///
/// $cloudfront = new CloudFront('access-key-id', 'secret-access-key', 'EDFDVBD6EXAMPLE');
/// // $cloudfront->issueCertificate('cert', 'example.com', null);
/// // $cloudfront->getValidationTokens('example.com');
/// ```
///
/// PHP: `Utopia\Cdn\Certificates\Provider\CloudFront`.
#[derive(Debug)]
pub struct CloudFront<C = DefaultClient> {
    signature: Signature,
    distribution_id: String,
    connection_group_id: Option<String>,
    validation_token_host: String,
    parameters: Vec<(String, String)>,
    client: C,
    api_base: String,
}

impl CloudFront {
    /// CloudFront serves the validation token itself, which only works once
    /// the domain already points at CloudFront.
    ///
    /// PHP: `CloudFront::VALIDATION_TOKEN_HOST_CLOUDFRONT`.
    pub const VALIDATION_TOKEN_HOST_CLOUDFRONT: &str = "cloudfront";

    /// Whatever serves the domain today answers the validation request, so
    /// the certificate is issued before the domain's traffic moves to
    /// CloudFront. See [`CloudFront::validation_tokens`].
    ///
    /// PHP: `CloudFront::VALIDATION_TOKEN_HOST_SELF_HOSTED`.
    pub const VALIDATION_TOKEN_HOST_SELF_HOSTED: &str = "self-hosted";

    /// AWS renews managed certificates without being asked, so an issued
    /// certificate has no renew date. Callers that schedule one get a date
    /// to check the certificate again instead.
    ///
    /// PHP: `CloudFront::RECHECK_INTERVAL`.
    pub const RECHECK_INTERVAL: &str = "+30 days";
}

/// A distribution tenant as CloudFront describes it.
struct Tenant {
    id: String,
    etag: String,
    name: String,
    distribution_id: String,
    connection_group_id: Option<String>,
    enabled: bool,
    domains: Vec<String>,
    parameters: Vec<(String, String)>,
    customizations: Option<Element>,
}

/// A tenant's managed certificate: its status (lower-cased) and validation
/// tokens.
struct Certificate {
    status: String,
    tokens: Vec<ValidationToken>,
}

impl<C: Client> CloudFront<C> {
    /// A provider creating tenants under `distribution_id`, signing with
    /// these credentials (and `session_token`, when given), sending with
    /// `client` to `api_base` (`https://cloudfront.amazonaws.com` when
    /// `None`).
    ///
    /// `connection_group_id` is the connection group for new tenants
    /// (CloudFront uses the account's default group when `None`);
    /// `validation_token_host` is one of the `VALIDATION_TOKEN_HOST_*`
    /// constants (`self-hosted` when `None`); `parameters` are the tenant
    /// parameters, required when the distribution declares parameters
    /// without defaults.
    ///
    /// Fails with [`Error::InvalidArgument`] for another validation token
    /// host.
    ///
    /// PHP: `new CloudFront($accessKeyId, $secretAccessKey, $distributionId,
    /// $connectionGroupId, $validationTokenHost, $parameters, $sessionToken,
    /// $client, $apiBase)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        access_key_id: &str,
        secret_access_key: &str,
        distribution_id: &str,
        connection_group_id: Option<&str>,
        validation_token_host: Option<&str>,
        parameters: &[(&str, &str)],
        session_token: Option<&str>,
        client: C,
        api_base: Option<&str>,
    ) -> Result<Self, Error> {
        let validation_token_host = validation_token_host.unwrap_or(CloudFront::VALIDATION_TOKEN_HOST_SELF_HOSTED);
        if validation_token_host != CloudFront::VALIDATION_TOKEN_HOST_CLOUDFRONT
            && validation_token_host != CloudFront::VALIDATION_TOKEN_HOST_SELF_HOSTED
        {
            return Err(Error::InvalidArgument(
                "Validation token host must be \"cloudfront\" or \"self-hosted\".".into(),
            ));
        }
        let mut owned: Vec<(String, String)> = Vec::new();
        for (name, value) in parameters {
            match owned.iter_mut().find(|(n, _)| n == name) {
                Some(entry) => entry.1 = (*value).to_owned(),
                None => owned.push(((*name).to_owned(), (*value).to_owned())),
            }
        }
        Ok(CloudFront {
            signature: Signature::new(access_key_id, secret_access_key, session_token),
            distribution_id: distribution_id.to_owned(),
            connection_group_id: connection_group_id.map(str::to_owned),
            validation_token_host: validation_token_host.to_owned(),
            parameters: owned,
            client,
            api_base: api_base.unwrap_or("https://cloudfront.amazonaws.com").to_owned(),
        })
    }

    /// What the certificate authority requests to validate the domain,
    /// while the certificate waits on validation. Empty once it is issued,
    /// or when the domain has no tenant.
    ///
    /// PHP: `getValidationTokens($domain)`.
    pub async fn validation_tokens(&self, domain: &str) -> Result<Vec<ValidationToken>, Error> {
        let Some(tenant) = self.find_tenant(&domain::validate(domain)?).await? else {
            return Ok(Vec::new());
        };
        match self.find_certificate(&tenant.id).await? {
            Some(certificate) if certificate.status == "pending-validation" => Ok(certificate.tokens),
            _ => Ok(Vec::new()),
        }
    }

    async fn issue(&self, domain: &str) -> Result<Option<String>, Error> {
        let domain = domain::validate(domain)?;
        let Some(tenant) = self.find_tenant(&domain).await? else {
            self.create_tenant(&domain).await?;
            return Ok(None);
        };
        // A failed certificate is not retried by AWS, and a tenant without
        // one serves the distribution's default certificate, so both get a
        // fresh request.
        let certificate = self.find_certificate(&tenant.id).await?;
        let Some(certificate) = certificate.filter(|c| map_status(&c.status) != Status::Failed) else {
            self.update_tenant(&tenant, true, true).await?;
            return Ok(None);
        };
        if !tenant.enabled {
            self.update_tenant(&tenant, true, false).await?;
        }
        if map_status(&certificate.status) != Status::Issued {
            return Ok(None);
        }
        let date = DateTime::new(b"now", None, &TzInfo::utc())
            .and_then(|d| d.modified(CloudFront::RECHECK_INTERVAL.as_bytes()))
            .map_err(|e| Error::Date { class: e.php_class(), message: e.message().into_owned() })?;
        Ok(Some(String::from_utf8_lossy(&date.format(b"Y-m-d H:i:s.v")).into_owned()))
    }

    async fn status(&self, domain: &str) -> Result<Status, Error> {
        let domain = domain::validate(domain)?;
        let Some(tenant) = self.find_tenant(&domain).await? else {
            return Ok(Status::Unknown);
        };
        let Some(certificate) = self.find_certificate(&tenant.id).await? else {
            return Ok(Status::Unknown);
        };
        let status = map_status(&certificate.status);
        if status == Status::Failed {
            return Err(Error::certificate(
                format!(
                    "CloudFront stopped trying to issue a certificate for {domain}: the certificate is {}.",
                    certificate.status
                ),
                Status::Failed,
                Vec::new(),
                Vec::new(),
            ));
        }
        Ok(status)
    }

    async fn renew_required(&self, domain: &str) -> Result<bool, Error> {
        let Some(tenant) = self.find_tenant(&domain::validate(domain)?).await? else {
            return Ok(true);
        };
        let certificate = self.find_certificate(&tenant.id).await?;
        Ok(certificate.is_none_or(|c| map_status(&c.status) == Status::Failed))
    }

    async fn delete(&self, domain: &str) -> Result<(), Error> {
        let Some(mut tenant) = self.find_tenant(&domain::validate(domain)?).await? else {
            return Ok(());
        };
        // CloudFront refuses to delete a tenant that still serves traffic.
        if tenant.enabled {
            tenant = self.update_tenant(&tenant, false, false).await?;
        }
        let path = format!("/distribution-tenant/{}", php_std::encoding::rawurlencode(tenant.id.as_bytes()));
        let reply = self.request("DELETE", &path, None, Some(&tenant.etag)).await?;
        if reply.status == 404 {
            return Ok(());
        }
        if reply.status == 409 && reply.error_code().as_deref() == Some("ResourceNotDisabled") {
            return Err(Error::Runtime(format!(
                "CloudFront distribution tenant {} for {} is disabled but not yet deployed; delete it again once the change has propagated.",
                tenant.id, tenant.name
            )));
        }
        assert_success("delete CloudFront distribution tenant", &reply, None)
    }

    /// The tenant this provider created for the domain: on its distribution
    /// and holding that domain alone. A tenant whose wildcard covers the
    /// domain, one that shares it with others, or one on another
    /// distribution is left alone.
    async fn find_tenant(&self, domain: &str) -> Result<Option<Tenant>, Error> {
        let path = format!("/distribution-tenant?{}", crate::api::query(&[("domain", domain)]));
        let reply = self.request("GET", &path, None, None).await?;
        if reply.status == 404 {
            return Ok(None);
        }
        assert_success("fetch CloudFront distribution tenant", &reply, None)?;
        let tenant = self.parse_tenant(&reply)?;
        if tenant.distribution_id != self.distribution_id
            || tenant.domains.len() != 1
            || tenant.domains[0].to_ascii_lowercase() != domain
        {
            return Ok(None);
        }
        Ok(Some(tenant))
    }

    async fn create_tenant(&self, domain: &str) -> Result<Tenant, Error> {
        let mut body = Element::new("CreateDistributionTenantRequest");
        body.add(Element::leaf("DistributionId", &self.distribution_id));
        body.add(Element::leaf("Name", &tenant_name(domain)));
        body.add(Element::new("Domains")).add(Element::new("DomainItem")).add(Element::leaf("Domain", domain));
        append_parameters(&mut body, &self.parameters);
        if let Some(group) = &self.connection_group_id {
            body.add(Element::leaf("ConnectionGroupId", group));
        }
        self.append_certificate_request(&mut body, domain);
        body.add(Element::leaf("Enabled", "true"));

        let reply = self.request("POST", "/distribution-tenant", Some(&body), None).await?;
        // A concurrent issue created it first.
        if reply.status == 409
            && reply.error_code().as_deref() == Some("EntityAlreadyExists")
            && let Some(tenant) = self.find_tenant(domain).await?
        {
            return Ok(tenant);
        }
        assert_success("create CloudFront distribution tenant", &reply, Some(&[201]))?;
        self.parse_tenant(&reply)
    }

    /// Sends the tenant back as it is, enabled or not, and with a fresh
    /// certificate request when one is asked for. Every field is sent
    /// because the update replaces the tenant's configuration.
    async fn update_tenant(&self, tenant: &Tenant, enabled: bool, request_certificate: bool) -> Result<Tenant, Error> {
        let mut body = Element::new("UpdateDistributionTenantRequest");
        body.add(Element::leaf("DistributionId", &tenant.distribution_id));
        let domains = body.add(Element::new("Domains"));
        for domain in &tenant.domains {
            domains.add(Element::new("DomainItem")).add(Element::leaf("Domain", domain));
        }
        // The customizations are copied from the tenant's own XML, so an
        // override set elsewhere survives. A certificate request replaces
        // the certificate the tenant had, so that one is left out.
        if let Some(customizations) = &tenant.customizations {
            let copy = body.add(Element::new("Customizations"));
            for customization in &customizations.children {
                if request_certificate && customization.name == "Certificate" {
                    continue;
                }
                copy.add(copied(customization));
            }
        }
        append_parameters(&mut body, &tenant.parameters);
        if let Some(group) = &tenant.connection_group_id {
            body.add(Element::leaf("ConnectionGroupId", group));
        }
        if request_certificate {
            self.append_certificate_request(&mut body, tenant.domains.first().map_or("", String::as_str));
        }
        body.add(Element::leaf("Enabled", if enabled { "true" } else { "false" }));

        let path = format!("/distribution-tenant/{}", php_std::encoding::rawurlencode(tenant.id.as_bytes()));
        let reply = self.request("PUT", &path, Some(&body), Some(&tenant.etag)).await?;
        assert_success("update CloudFront distribution tenant", &reply, None)?;
        self.parse_tenant(&reply)
    }

    async fn find_certificate(&self, tenant_id: &str) -> Result<Option<Certificate>, Error> {
        let path = format!("/managed-certificate/{}", php_std::encoding::rawurlencode(tenant_id.as_bytes()));
        let reply = self.request("GET", &path, None, None).await?;
        if reply.status == 404 {
            return Ok(None);
        }
        assert_success("fetch CloudFront managed certificate", &reply, None)?;
        let Some(details) = reply.response.xml() else {
            return Err(Error::Runtime("CloudFront managed certificate response was not valid XML.".into()));
        };
        let Some(status) = details.text_of("CertificateStatus") else {
            return Ok(None);
        };
        let mut tokens = Vec::new();
        for detail in details.list("ValidationTokenDetails", "ValidationTokenDetail") {
            let (Some(domain), Some(from), Some(to)) =
                (detail.text_of("Domain"), detail.text_of("RedirectFrom"), detail.text_of("RedirectTo"))
            else {
                continue;
            };
            tokens.push(ValidationToken { domain: domain.to_ascii_lowercase(), from, to });
        }
        Ok(Some(Certificate { status: status.to_ascii_lowercase(), tokens }))
    }

    fn append_certificate_request(&self, body: &mut Element, domain: &str) {
        let request = body.add(Element::new("ManagedCertificateRequest"));
        request.add(Element::leaf("ValidationTokenHost", &self.validation_token_host));
        request.add(Element::leaf("PrimaryDomainName", domain));
        request.add(Element::leaf("CertificateTransparencyLoggingPreference", "enabled"));
    }

    fn parse_tenant(&self, reply: &Reply) -> Result<Tenant, Error> {
        let Some(tenant) = reply.response.xml() else {
            return Err(Error::Runtime("CloudFront distribution tenant response was not valid XML.".into()));
        };
        let Some(id) = tenant.text_of("Id") else {
            return Err(Error::Runtime("CloudFront distribution tenant response was missing its ID.".into()));
        };
        let Some(etag) = reply.etag.clone() else {
            return Err(Error::Runtime("CloudFront distribution tenant response was missing its ETag.".into()));
        };
        let domains = tenant.list("Domains", "DomainResult").filter_map(|d| d.text_of("Domain")).collect();
        let mut parameters: Vec<(String, String)> = Vec::new();
        for parameter in tenant.list("Parameters", "Parameter") {
            let Some(name) = parameter.text_of("Name") else {
                continue;
            };
            let value = parameter.text_of("Value").unwrap_or_default();
            match parameters.iter_mut().find(|(n, _)| *n == name) {
                Some(entry) => entry.1 = value,
                None => parameters.push((name, value)),
            }
        }
        Ok(Tenant {
            name: tenant.text_of("Name").unwrap_or_else(|| id.clone()),
            id,
            etag,
            distribution_id: tenant.text_of("DistributionId").unwrap_or_else(|| self.distribution_id.clone()),
            connection_group_id: tenant.text_of("ConnectionGroupId"),
            enabled: tenant.text_of("Enabled").unwrap_or_else(|| "true".into()).eq_ignore_ascii_case("true"),
            domains,
            parameters,
            customizations: tenant.child("Customizations").cloned(),
        })
    }

    async fn request(
        &self,
        method: &str,
        path: &str,
        body: Option<&Element>,
        etag: Option<&str>,
    ) -> Result<Reply, Error> {
        let url = format!("{}/{API_VERSION}{path}", self.api_base);
        let mut request =
            Request::new(method, &url)?.header("User-Agent", "Utopia CDN CloudFront Certificates Provider")?;
        if let Some(body) = body {
            request = request.header("Content-Type", "application/xml")?.body(body.document(NAMESPACE));
        }
        if let Some(etag) = etag {
            request = request.header("If-Match", etag)?;
        }
        xml::send(&self.client, &self.signature, request, false).await
    }
}

fn append_parameters(body: &mut Element, parameters: &[(String, String)]) {
    if parameters.is_empty() {
        return;
    }
    let list = body.add(Element::new("Parameters"));
    for (name, value) in parameters {
        let parameter = list.add(Element::new("Parameter"));
        parameter.add(Element::leaf("Name", name));
        parameter.add(Element::leaf("Value", value));
    }
}

/// The element with its leaves' text and its structure, but not its own
/// text when it has children (nor any attribute).
fn copied(source: &Element) -> Element {
    if source.children.is_empty() {
        return Element::leaf(&source.name, &source.text);
    }
    Element { name: source.name.clone(), text: String::new(), children: source.children.iter().map(copied).collect() }
}

/// The domain itself when it fits, since tenant names are unique per
/// account; longer domains keep a prefix and a hash of the whole name.
fn tenant_name(domain: &str) -> String {
    if domain.len() <= NAME_LENGTH {
        return domain.to_owned();
    }
    let hash = php_std::string::hash(b"sha256", domain.as_bytes()).unwrap_or_default();
    let prefix = &domain[..NAME_LENGTH - hash.len() - 1];
    format!("{}-{hash}", prefix.trim_end_matches(['.', '-']))
}

fn map_status(status: &str) -> Status {
    match status {
        "pending-validation" => Status::Pending,
        "issued" => Status::Issued,
        "validation-timed-out" | "failed" | "expired" | "revoked" => Status::Failed,
        _ => Status::Unknown,
    }
}

/// PHP's truthiness of a string: neither empty nor `"0"`.
fn truthy(s: &str) -> bool {
    !s.is_empty() && s != "0"
}

fn assert_success(operation: &str, reply: &Reply, expected: Option<&[i64]>) -> Result<(), Error> {
    let success = match expected {
        None => reply.is_2xx(),
        Some(statuses) => statuses.contains(&reply.status),
    };
    if success {
        return Ok(());
    }
    let mut message = reply.error.clone();
    if message.is_none()
        && let Some(error) = reply.response.xml().and_then(|x| x.child("Error"))
    {
        let parts: Vec<String> =
            [error.text_of("Code"), error.text_of("Message")].into_iter().flatten().filter(|p| truthy(p)).collect();
        message = Some(parts.join(": "));
    }
    let message = message.filter(|m| truthy(m)).unwrap_or_else(|| "Unknown CloudFront error".into());
    Err(Error::Runtime(format!("Failed to {operation} with status {}: {message}", reply.status)))
}

impl<C: Client> Provider for CloudFront<C> {
    /// Creates the domain's tenant with a managed certificate request, or
    /// requests a certificate again when the last one failed; the date to
    /// check the certificate again once it is issued.
    fn issue_certificate<'a>(
        &'a self,
        _cert_name: &'a str,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        Box::pin(self.issue(domain))
    }

    /// Never: AWS validates the domain first.
    fn is_instant_generation<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async move {
            domain::validate(domain)?;
            Ok(false)
        })
    }

    /// The managed certificate's status. Fails with [`Error::Certificate`]
    /// when AWS has stopped trying to issue it, or it expired or was
    /// revoked.
    fn certificate_status<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(self.status(domain))
    }

    /// Whether the domain has no tenant, no certificate, or a failed one.
    fn is_renew_required<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.renew_required(domain))
    }

    /// Disables the domain's tenant, then deletes it.
    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.delete(domain))
    }
}
