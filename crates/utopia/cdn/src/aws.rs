//! Signing requests to an AWS API.

use hmac::{Hmac, Mac};
use php_std::datetime::{DateTime, TzInfo};
use sha2::{Digest, Sha256};
use utopia_client::{Body, Request};

use crate::Error;

/// AWS Signature Version 4 for requests to an AWS API.
///
/// Signs a request in place of the AWS SDK, which this crate does not
/// depend on. The signed headers are `host`, `content-type` when present,
/// and every `x-amz-*` header, which is what the AWS JSON and XML APIs
/// require; the payload hash is computed from the body rather than sent as
/// a header, because only S3 asks for `x-amz-content-sha256`. Paths are
/// encoded twice, as SigV4 specifies for every service except S3, so this
/// signer is not for S3.
///
/// ```
/// use utopia_cdn::aws::Signature;
/// use utopia_client::Request;
///
/// let request = Request::new("GET", "https://example.amazonaws.com/")?;
/// let signed = Signature::new("AKIDEXAMPLE", "wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY", None)
///     .sign(request, "service", "us-east-1", Some(1_440_938_160))?;
/// assert!(signed.headers().line("Authorization").ends_with(
///     "Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31"
/// ));
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cdn\Aws\Signature;
/// use Utopia\Psr7\Request\Factory;
///
/// $request = new Factory()->createRequest('GET', 'https://example.amazonaws.com/');
/// $signed = new Signature('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY')
///     ->sign($request, 'service', 'us-east-1', new \DateTimeImmutable('@1440938160'));
/// echo $signed->getHeaderLine('Authorization'), "\n";
/// ```
///
/// PHP: `Utopia\Cdn\Aws\Signature`.
#[derive(Debug, Clone)]
pub struct Signature {
    access_key_id: String,
    secret_access_key: String,
    session_token: Option<String>,
}

impl Signature {
    /// PHP: `Signature::ALGORITHM`.
    pub const ALGORITHM: &str = "AWS4-HMAC-SHA256";

    /// A signer for these credentials; a session token, when given and not
    /// empty, is sent as `X-Amz-Security-Token`.
    ///
    /// PHP: `new Signature($accessKeyId, $secretAccessKey, $sessionToken)`.
    pub fn new(access_key_id: &str, secret_access_key: &str, session_token: Option<&str>) -> Self {
        Signature {
            access_key_id: access_key_id.to_owned(),
            secret_access_key: secret_access_key.to_owned(),
            session_token: session_token.map(str::to_owned),
        }
    }

    /// The request with `X-Amz-Date`, the session token when there is one,
    /// and an `Authorization` header replacing any it had, signed for
    /// `service` (its signing name, e.g. `cloudfront`) in `region` (global
    /// services such as CloudFront sign with `us-east-1`) at `time` (Unix
    /// seconds; now when `None`).
    ///
    /// PHP: `sign($request, $service, $region, $time)`, `$time` being a
    /// `DateTimeImmutable` (converted to UTC).
    pub fn sign(&self, request: Request, service: &str, region: &str, time: Option<i64>) -> Result<Request, Error> {
        let time = time.unwrap_or_else(php_std::datetime::time);
        let at = DateTime::new(format!("@{time}").as_bytes(), None, &TzInfo::utc())
            .map_err(|e| Error::Date { class: e.php_class(), message: e.message().into_owned() })?;
        let amz_date = String::from_utf8_lossy(&at.format(b"Ymd\\THis\\Z")).into_owned();
        let date = String::from_utf8_lossy(&at.format(b"Ymd")).into_owned();

        let mut request = request;
        request.headers_mut().remove("Authorization");
        let mut request = request.header("X-Amz-Date", &amz_date)?;
        if let Some(token) = self.session_token.as_deref().filter(|t| !t.is_empty()) {
            request = request.header("X-Amz-Security-Token", token)?;
        }

        let headers = canonical_headers(&request);
        let signed_headers = headers.iter().map(|(name, _)| name.as_str()).collect::<Vec<_>>().join(";");
        let payload: &[u8] = match request.get_body() {
            Body::Bytes(bytes) => bytes,
            _ => b"",
        };
        let canonical_request = [
            request.method().to_ascii_uppercase(),
            canonical_path(request.uri().path()),
            canonical_query(request.uri().query()),
            headers.iter().map(|(name, value)| format!("{name}:{value}\n")).collect(),
            signed_headers.clone(),
            hex(&Sha256::digest(payload)),
        ]
        .join("\n");

        let scope = format!("{date}/{region}/{service}/aws4_request");
        let string_to_sign = [
            Signature::ALGORITHM.to_owned(),
            amz_date,
            scope.clone(),
            hex(&Sha256::digest(canonical_request.as_bytes())),
        ]
        .join("\n");

        let key = hmac(format!("AWS4{}", self.secret_access_key).as_bytes(), date.as_bytes());
        let key = hmac(&key, region.as_bytes());
        let key = hmac(&key, service.as_bytes());
        let key = hmac(&key, b"aws4_request");
        let signature = hex(&hmac(&key, string_to_sign.as_bytes()));

        Ok(request.header(
            "Authorization",
            &format!(
                "{} Credential={}/{scope}, SignedHeaders={signed_headers}, Signature={signature}",
                Signature::ALGORITHM,
                self.access_key_id
            ),
        )?)
    }
}

fn hmac(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = <Hmac<Sha256> as Mac>::new_from_slice(key).expect("HMAC takes keys of any length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn hex(bytes: &[u8]) -> String {
    php_std::encoding::bin2hex(bytes)
}

/// Lowercase header names, sorted, with their trimmed values: `host` (the
/// URI's host and any port that is not the scheme's default), then
/// `content-type` and every `x-amz-*` header the request carries.
fn canonical_headers(request: &Request) -> Vec<(String, String)> {
    let uri = request.uri();
    let host = match uri.port() {
        Some(port) => format!("{}:{port}", uri.host()),
        None => uri.host().to_owned(),
    };
    let mut headers = vec![("host".to_owned(), collapse_spaces(&host))];
    for (name, values) in request.headers().iter() {
        let name = name.to_ascii_lowercase();
        if name != "content-type" && !name.starts_with("x-amz-") {
            continue;
        }
        let value = values.iter().map(|v| collapse_spaces(v)).collect::<Vec<_>>().join(",");
        match headers.iter_mut().find(|(n, _)| *n == name) {
            Some(entry) => entry.1 = value,
            None => headers.push((name, value)),
        }
    }
    headers.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()));
    headers
}

/// Trims the value and folds every run of whitespace inside it to one
/// space.
fn collapse_spaces(value: &str) -> String {
    let trimmed = value.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
    trimmed.replace(['\t', '\n', '\r'], " ").split(' ').filter(|w| !w.is_empty()).collect::<Vec<_>>().join(" ")
}

/// The URI's path is already percent-encoded once; SigV4 encodes each
/// segment again.
fn canonical_path(path: &str) -> String {
    if path.is_empty() {
        return "/".into();
    }
    path.split('/').map(|segment| php_std::encoding::rawurlencode(segment.as_bytes())).collect::<Vec<_>>().join("/")
}

fn canonical_query(query: &str) -> String {
    if query.is_empty() {
        return String::new();
    }
    let encode = |s: &str| php_std::encoding::rawurlencode(&php_std::encoding::rawurldecode(s.as_bytes()));
    let mut pairs: Vec<(String, String)> = query
        .split('&')
        .map(|pair| {
            let (name, value) = pair.split_once('=').unwrap_or((pair, ""));
            (encode(name), encode(value))
        })
        .collect();
    // Byte order, as strcmp() compares.
    pairs.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()).then_with(|| a.1.as_bytes().cmp(b.1.as_bytes())));
    pairs.into_iter().map(|(name, value)| format!("{name}={value}")).collect::<Vec<_>>().join("&")
}
