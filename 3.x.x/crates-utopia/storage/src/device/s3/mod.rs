use std::collections::{BTreeMap, HashMap};
use std::fmt;
use std::io::{Cursor, Read, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::mpsc;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use base64::Engine;
use bytes::Bytes;
use hmac::{Hmac, Mac};
use http::header::HeaderMap;
use http::{Method, Request};
use md5::{Digest as Md5Digest, Md5};
use quick_xml::events::Event;
use quick_xml::Reader;
use sha2::Sha256;
use time::format_description::well_known::Rfc3339;
use time::macros::format_description;
use time::OffsetDateTime;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

use super::{
    absolute_path, copy_between, Device, ParallelUploadOptions, PartValue, ReadSeek,
    UploadMetadata, COPY_CHUNK_SIZE, MIN_MULTIPART_PART_SIZE,
};
use crate::acl::Acl;
use crate::device_type::DeviceType;
use crate::error::{NotFound, StorageError, UploadError};
use crate::file_info::{FileInfo, FileList};

const MAX_PAGE_SIZE: usize = 1000;
const MAX_COPY_OBJECT_SIZE: u64 = 5_368_709_120;
const ERROR_BODY_BUFFER_SIZE: usize = 64 * 1024;
const TRANSIENT_ERROR_CODES: &[&str] = &[
    "SlowDown",
    "ServiceUnavailable",
    "Throttling",
    "RequestThrottled",
];
const TRANSIENT_STATUS_CODES: &[u16] = &[429, 503];

type HmacSha256 = Hmac<Sha256>;

/// Decoded XML response value.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum XmlValue {
    Text(String),
    Map(BTreeMap<String, XmlValue>),
    List(Vec<XmlValue>),
}

impl XmlValue {
    fn get(&self, key: &str) -> Option<&Self> {
        match self {
            Self::Map(values) => values.get(key),
            _ => None,
        }
    }

    fn as_str(&self) -> Option<&str> {
        match self {
            Self::Text(value) => Some(value),
            _ => None,
        }
    }

    fn entries(&self) -> Vec<&Self> {
        match self {
            Self::List(values) => values.iter().collect(),
            Self::Map(_) => vec![self],
            Self::Text(_) => Vec::new(),
        }
    }
}

/// Raw or XML-decoded S3 response body.
///
/// Object payloads use opaque bytes (never lossy UTF-8 conversion).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum S3Body {
    Bytes(Vec<u8>),
    Xml(XmlValue),
}

/// Response returned by internal S3 calls.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct S3Response {
    pub code: u16,
    pub headers: HashMap<String, String>,
    pub body: S3Body,
}

/// Retry strategy for transient S3 throttling responses.
#[derive(Clone)]
pub struct RetryStrategy {
    retries: u32,
    delay: Duration,
    max_delay: Duration,
    randomizer: fn() -> f64,
}

impl fmt::Debug for RetryStrategy {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("RetryStrategy")
            .field("retries", &self.retries)
            .field("delay", &self.delay)
            .field("max_delay", &self.max_delay)
            .finish_non_exhaustive()
    }
}

impl Default for RetryStrategy {
    fn default() -> Self {
        Self::new(3, Duration::from_millis(500), Duration::from_secs(20))
    }
}

impl RetryStrategy {
    pub fn new(retries: u32, delay: Duration, max_delay: Duration) -> Self {
        Self {
            retries,
            delay,
            max_delay,
            randomizer: random_unit,
        }
    }

    pub fn with_randomizer(mut self, randomizer: fn() -> f64) -> Self {
        self.randomizer = randomizer;
        self
    }

    pub fn delay(&self, attempt: u32, status: u16, body: &[u8]) -> Option<Duration> {
        if attempt > self.retries || !Self::is_transient(status, body) {
            return None;
        }

        let factor = 2_f64.powi(i32::try_from(attempt.saturating_sub(1)).unwrap_or(0));
        let base = self.delay.as_secs_f64() * factor;
        let window = base.min(self.max_delay.as_secs_f64());
        Some(Duration::from_secs_f64((self.randomizer)() * window))
    }

    pub fn is_transient(status: u16, body: &[u8]) -> bool {
        let body = String::from_utf8_lossy(body);
        let trimmed = body.trim_start();
        if (trimmed.starts_with("<?xml") || trimmed.starts_with("<Error"))
            && parse_xml(&body)
                .ok()
                .and_then(|xml| {
                    xml.get("Code")
                        .and_then(XmlValue::as_str)
                        .map(str::to_string)
                })
                .is_some_and(|code| {
                    TRANSIENT_ERROR_CODES.contains(&code.as_str())
                        || !code.is_empty()
                            && !TRANSIENT_ERROR_CODES.contains(&code.as_str())
                            && return_false()
                })
        {
            return true;
        }

        if trimmed.starts_with("<?xml") || trimmed.starts_with("<Error") {
            if let Ok(xml) = parse_xml(&body) {
                if let Some(code) = xml.get("Code").and_then(XmlValue::as_str) {
                    if !code.is_empty() {
                        return TRANSIENT_ERROR_CODES.contains(&code);
                    }
                }
            }
        }

        TRANSIENT_STATUS_CODES.contains(&status)
    }
}

fn return_false() -> bool {
    false
}

fn random_unit() -> f64 {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |duration| duration.subsec_nanos());
    f64::from(nanos % 1_000_000) / 1_000_000.0
}

/// S3-compatible storage adapter.
#[derive(Clone)]
pub struct S3 {
    root: PathBuf,
    access_key: String,
    secret_key: String,
    fqdn: String,
    host: String,
    endpoint_path: String,
    region: String,
    acl: Acl,
    bucket: Option<String>,
    client: Client<curl::Client>,
    retry_strategy: RetryStrategy,
}

impl fmt::Debug for S3 {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("S3")
            .field("root", &self.root)
            .field("access_key", &self.access_key)
            .field("fqdn", &self.fqdn)
            .field("host", &self.host)
            .field("endpoint_path", &self.endpoint_path)
            .field("region", &self.region)
            .field("acl", &self.acl)
            .field("bucket", &self.bucket)
            .field("retry_strategy", &self.retry_strategy)
            .finish_non_exhaustive()
    }
}

impl S3 {
    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        host: impl AsRef<str>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        Self::with_options(root, access_key, secret_key, host, region, acl, None, None)
    }

    pub fn with_bucket(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        host: impl AsRef<str>,
        region: impl Into<String>,
        acl: Acl,
        bucket: impl Into<String>,
    ) -> Result<Self, StorageError> {
        Self::with_options(
            root,
            access_key,
            secret_key,
            host,
            region,
            acl,
            Some(bucket.into()),
            None,
        )
    }

    pub fn with_client(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        host: impl AsRef<str>,
        region: impl Into<String>,
        acl: Acl,
        client: Client<curl::Client>,
    ) -> Result<Self, StorageError> {
        Self::with_options(
            root,
            access_key,
            secret_key,
            host,
            region,
            acl,
            None,
            Some(client),
        )
    }

    fn with_options(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        host: impl AsRef<str>,
        region: impl Into<String>,
        acl: Acl,
        bucket: Option<String>,
        client: Option<Client<curl::Client>>,
    ) -> Result<Self, StorageError> {
        let mut endpoint = host.as_ref().trim_end_matches('/').to_string();
        if !endpoint.starts_with("http://") && !endpoint.starts_with("https://") {
            endpoint = format!("https://{endpoint}");
        }
        let url = url::Url::parse(&endpoint)
            .map_err(|error| StorageError::message(format!("invalid S3 endpoint: {error}")))?;
        let host_name = url
            .host_str()
            .ok_or_else(|| StorageError::message("invalid S3 endpoint host"))?;
        let authority = if let Some(port) = url.port() {
            format!("{host_name}:{port}")
        } else {
            host_name.to_string()
        };

        Ok(Self {
            root: root.into(),
            access_key: access_key.into(),
            secret_key: secret_key.into(),
            fqdn: format!("{}://{}", url.scheme(), authority),
            host: authority,
            endpoint_path: url.path().trim_end_matches('/').to_string(),
            region: region.into(),
            acl,
            bucket,
            client: client.unwrap_or_else(default_s3_client),
            retry_strategy: RetryStrategy::default(),
        })
    }

    pub fn with_retry_strategy(mut self, retry_strategy: RetryStrategy) -> Self {
        self.retry_strategy = retry_strategy;
        self
    }

    pub fn host(&self) -> &str {
        &self.host
    }

    pub fn endpoint(&self) -> &str {
        &self.fqdn
    }

    pub fn region(&self) -> &str {
        &self.region
    }

    pub fn bucket(&self) -> Option<&str> {
        self.bucket.as_deref()
    }

    fn create_multipart_upload(
        &self,
        path: &Path,
        content_type: &str,
    ) -> Result<String, StorageError> {
        let response = self.call(
            "POST",
            &uri_for_write(path),
            &[],
            &[("uploads", "")],
            &[("content-type", content_type)],
            &[("x-amz-acl", self.acl.as_str())],
            true,
        )?;
        response
            .body
            .xml()
            .and_then(|xml| xml.get("UploadId"))
            .and_then(XmlValue::as_str)
            .map(str::to_string)
            .ok_or_else(|| {
                StorageError::remote(
                    200,
                    None,
                    "Missing upload ID in S3 response",
                    HashMap::new(),
                )
            })
    }

    fn upload_part(
        &self,
        data: &[u8],
        path: &Path,
        content_type: &str,
        chunk: u32,
        upload_id: &str,
    ) -> Result<String, StorageError> {
        let chunk = chunk.to_string();
        let response = self.call(
            "PUT",
            &uri_for_write(path),
            data,
            &[("partNumber", chunk.as_str()), ("uploadId", upload_id)],
            &[("content-type", content_type)],
            &[],
            true,
        )?;
        response.headers.get("etag").cloned().ok_or_else(|| {
            StorageError::remote(200, None, "Missing ETag in S3 response", HashMap::new())
        })
    }

    fn complete_multipart_upload(
        &self,
        path: &Path,
        upload_id: &str,
        parts: &HashMap<u32, PartValue>,
    ) -> Result<bool, StorageError> {
        let mut sorted = parts.iter().collect::<Vec<_>>();
        sorted.sort_by_key(|(part, _)| **part);

        let mut body = String::from("<CompleteMultipartUpload>");
        for (part, value) in sorted {
            let PartValue::Etag(etag) = value else {
                return Err(UploadError(format!("Missing ETag for part {part}")).into());
            };
            body.push_str("<Part><ETag>");
            body.push_str(&xml_escape(etag));
            body.push_str("</ETag><PartNumber>");
            body.push_str(&part.to_string());
            body.push_str("</PartNumber></Part>");
        }
        body.push_str("</CompleteMultipartUpload>");

        self.call(
            "POST",
            &uri_for_write(path),
            body.as_bytes(),
            &[("uploadId", upload_id)],
            &[("content-type", "application/xml")],
            &[],
            true,
        )?;
        Ok(true)
    }

    fn get_info(&self, path: &Path) -> Result<HashMap<String, String>, StorageError> {
        Ok(self
            .call("HEAD", &uri_for_read(path), &[], &[], &[], &[], false)?
            .headers)
    }

    fn object_exists(&self, path: &Path) -> Result<bool, StorageError> {
        match self.get_info(path) {
            Ok(_) => Ok(true),
            Err(StorageError::NotFound(_)) => Ok(false),
            Err(error) => Err(error),
        }
    }

    fn list_objects(
        &self,
        prefix: &Path,
        max_keys: usize,
        continuation_token: Option<&str>,
    ) -> Result<XmlValue, StorageError> {
        if max_keys > MAX_PAGE_SIZE {
            return Err(StorageError::message(format!(
                "Cannot list more than {MAX_PAGE_SIZE} objects"
            )));
        }

        let prefix = path_to_key(prefix).trim_start_matches('/').to_string();
        let max_keys = max_keys.to_string();
        let mut params = vec![
            ("list-type", "2"),
            ("prefix", prefix.as_str()),
            ("max-keys", max_keys.as_str()),
        ];
        if let Some(token) = continuation_token.filter(|token| !token.is_empty() && *token != "0") {
            params.push(("continuation-token", token));
        }

        self.call(
            "GET",
            "/",
            &[],
            &params,
            &[("content-type", "text/plain")],
            &[],
            true,
        )?
        .body
        .xml()
        .cloned()
        .ok_or_else(|| {
            StorageError::remote(200, None, "Unexpected S3 list response", HashMap::new())
        })
    }

    fn copy_object(&self, source: &Path, target: &Path) -> Result<(), StorageError> {
        let info = self.get_info(source)?;
        let size = info
            .get("content-length")
            .and_then(|value| value.parse::<u64>().ok())
            .unwrap_or(0);
        let bucket = self
            .bucket
            .as_deref()
            .ok_or_else(|| StorageError::message("bucket is required for server-side copy"))?;
        let copy_source = format!(
            "/{}/{}",
            bucket,
            percent_encode(&path_to_key(source))
                .replace("%2F", "/")
                .trim_start_matches('/')
        );

        if size <= MAX_COPY_OBJECT_SIZE {
            let response = self.call(
                "PUT",
                &uri_for_write(target),
                &[],
                &[],
                &[],
                &[
                    ("x-amz-copy-source", copy_source.as_str()),
                    ("x-amz-metadata-directive", "COPY"),
                    ("x-amz-acl", self.acl.as_str()),
                ],
                true,
            )?;
            if response
                .body
                .xml()
                .and_then(|xml| xml.get("ETag"))
                .and_then(XmlValue::as_str)
                .is_none()
            {
                return Err(StorageError::remote(
                    response.code,
                    None,
                    "Unexpected S3 copy response",
                    HashMap::new(),
                ));
            }
            return Ok(());
        }

        let content_type = info.get("content-type").map_or("", String::as_str);
        let upload_id = self.create_multipart_upload(target, content_type)?;
        let result = (|| {
            let total_parts = size.div_ceil(MAX_COPY_OBJECT_SIZE);
            let mut parts = HashMap::new();
            for part in 1..=total_parts {
                let start = (part - 1) * MAX_COPY_OBJECT_SIZE;
                let end = size.min(start + MAX_COPY_OBJECT_SIZE) - 1;
                let part_string = part.to_string();
                let range = format!("bytes={start}-{end}");
                let response = self.call(
                    "PUT",
                    &uri_for_write(target),
                    &[],
                    &[
                        ("partNumber", part_string.as_str()),
                        ("uploadId", upload_id.as_str()),
                    ],
                    &[],
                    &[
                        ("x-amz-copy-source", copy_source.as_str()),
                        ("x-amz-copy-source-range", range.as_str()),
                    ],
                    true,
                )?;
                let etag = response
                    .body
                    .xml()
                    .and_then(|xml| xml.get("ETag"))
                    .and_then(XmlValue::as_str)
                    .ok_or_else(|| {
                        StorageError::remote(
                            response.code,
                            None,
                            "Missing ETag in S3 response",
                            HashMap::new(),
                        )
                    })?;
                parts.insert(part as u32, PartValue::Etag(etag.to_string()));
            }
            self.complete_multipart_upload(target, &upload_id, &parts)?;
            Ok(())
        })();

        if result.is_err() {
            let _ = self.abort(target, &upload_id);
        }
        result
    }

    fn call(
        &self,
        method: &str,
        uri: &str,
        data: &[u8],
        parameters: &[(&str, &str)],
        headers: &[(&str, &str)],
        amz_headers: &[(&str, &str)],
        decode: bool,
    ) -> Result<S3Response, StorageError> {
        self.call_with_sink(
            method,
            uri,
            data,
            parameters,
            headers,
            amz_headers,
            decode,
            None,
        )
    }

    /// Signed S3 request. When `sink` is set, a successful response body is streamed
    /// into it and never fully buffered (error responses are still buffered).
    #[allow(clippy::too_many_arguments)]
    fn call_with_sink(
        &self,
        method: &str,
        uri: &str,
        data: &[u8],
        parameters: &[(&str, &str)],
        headers: &[(&str, &str)],
        amz_headers: &[(&str, &str)],
        decode: bool,
        mut sink: Option<&mut dyn Write>,
    ) -> Result<S3Response, StorageError> {
        let uri = format!(
            "{}{}",
            self.endpoint_path,
            path_to_string(&absolute_path(uri))
        );
        let query = build_query(parameters);
        let url = if query.is_empty() {
            format!("{}{}", self.fqdn, uri)
        } else {
            format!("{}{}?{}", self.fqdn, uri, query)
        };

        let (md5, sha256) = hash_body(data);
        // `Bytes` keeps retries from reallocating a fresh owned copy each attempt.
        let request_body = Bytes::copy_from_slice(data);
        let now = OffsetDateTime::now_utc();
        let date = now
            .format(&format_description!(
                "[weekday repr:short], [day padding:zero] [month repr:short] [year] [hour]:[minute]:[second] GMT"
            ))
            .map_err(|error| StorageError::message(format!("failed to format date: {error}")))?;
        let amz_date = now
            .format(&format_description!(
                "[year][month padding:zero][day padding:zero]T[hour][minute][second]Z"
            ))
            .map_err(|error| {
                StorageError::message(format!("failed to format amz date: {error}"))
            })?;

        let mut regular = BTreeMap::new();
        for (key, value) in headers
            .iter()
            .copied()
            .filter(|(_, value)| !value.is_empty())
        {
            regular.insert(key.to_ascii_lowercase(), value.trim().to_string());
        }
        regular.insert("host".to_string(), self.host.clone());
        regular.insert("date".to_string(), date);
        regular.insert("content-md5".to_string(), md5);

        let mut amz = BTreeMap::new();
        for (key, value) in amz_headers
            .iter()
            .copied()
            .filter(|(_, value)| !value.is_empty())
        {
            amz.insert(key.to_ascii_lowercase(), value.trim().to_string());
        }
        amz.insert("x-amz-date".to_string(), amz_date);
        amz.insert("x-amz-content-sha256".to_string(), sha256);

        let authorization = self.signature_v4(method, &uri, parameters, &regular, &amz);
        let method = method
            .parse::<Method>()
            .map_err(|error| StorageError::message(format!("invalid HTTP method: {error}")))?;

        let mut attempt = 0_u32;
        loop {
            let mut builder = Request::builder().method(method.clone()).uri(&url);
            for (key, value) in amz.iter().chain(regular.iter()) {
                builder = builder.header(key.as_str(), value.as_str());
            }
            builder = builder
                .header("authorization", authorization.as_str())
                .header("user-agent", "utopia-php/storage");
            let request = builder
                .body(request_body.clone())
                .map_err(|error| StorageError::message(format!("invalid S3 request: {error}")))?;

            let (status, response_headers, body) = if sink.is_some() {
                let mut head = Vec::new();
                let mut write_error: Option<std::io::Error> = None;
                let response = self
                    .client
                    .stream(request, &mut |chunk| {
                        if head.len() < ERROR_BODY_BUFFER_SIZE {
                            let take = (ERROR_BODY_BUFFER_SIZE - head.len()).min(chunk.len());
                            head.extend_from_slice(&chunk[..take]);
                        }
                        if let Some(sink) = sink.as_deref_mut() {
                            if write_error.is_none() {
                                if let Err(error) = sink.write_all(chunk) {
                                    write_error = Some(error);
                                }
                            }
                        }
                    })
                    .map_err(|error| {
                        StorageError::transport_with_source(error.to_string(), error)
                    })?;
                if let Some(error) = write_error {
                    return Err(StorageError::transport(error.to_string()));
                }
                (
                    response.status().as_u16(),
                    lower_headers(response.headers()),
                    head,
                )
            } else {
                let response = self.client.send_request(request).map_err(|error| {
                    StorageError::transport_with_source(error.to_string(), error)
                })?;
                (
                    response.status().as_u16(),
                    lower_headers(response.headers()),
                    response.body().to_vec(),
                )
            };

            if status >= 400 {
                attempt += 1;
                if let Some(delay) = self.retry_strategy.delay(attempt, status, &body) {
                    thread::sleep(delay);
                    continue;
                }
                return Err(parse_s3_error(&body, status, &response_headers));
            }

            if sink.is_some() {
                return Ok(S3Response {
                    code: status,
                    headers: response_headers,
                    body: S3Body::Bytes(Vec::new()),
                });
            }

            attempt += 1;
            if let Some(delay) = self.retry_strategy.delay(attempt, status, &body) {
                thread::sleep(delay);
                continue;
            }

            let content_type = response_headers
                .get("content-type")
                .map_or("", String::as_str);
            let looks_like_xml = content_type == "application/xml"
                || (body.starts_with(b"<?xml") && content_type != "image/svg+xml")
                || body.first() == Some(&b'<');
            let body = if decode && looks_like_xml && !body.is_empty() {
                let text = String::from_utf8_lossy(&body);
                S3Body::Xml(parse_xml(&text)?)
            } else {
                S3Body::Bytes(body)
            };

            return Ok(S3Response {
                code: status,
                headers: response_headers,
                body,
            });
        }
    }

    fn signature_v4(
        &self,
        method: &str,
        uri: &str,
        parameters: &[(&str, &str)],
        headers: &BTreeMap<String, String>,
        amz_headers: &BTreeMap<String, String>,
    ) -> String {
        let algorithm = "AWS4-HMAC-SHA256";
        let amz_date = amz_headers.get("x-amz-date").map_or("", String::as_str);
        let date_stamp = &amz_date[..8.min(amz_date.len())];

        let mut combined = BTreeMap::new();
        for (key, value) in headers.iter().chain(amz_headers.iter()) {
            combined.insert(key.to_string(), value.trim().to_string());
        }
        let signed_headers = combined.keys().cloned().collect::<Vec<_>>().join(";");
        let query = build_query(parameters);
        let path = uri.split_once('?').map_or(uri, |(path, _)| path);

        let mut canonical = vec![method.to_string(), path.to_string(), query];
        for (key, value) in &combined {
            canonical.push(format!("{key}:{value}"));
        }
        canonical.push(String::new());
        canonical.push(signed_headers.clone());
        canonical.push(
            amz_headers
                .get("x-amz-content-sha256")
                .cloned()
                .unwrap_or_default(),
        );
        let canonical = canonical.join("\n");

        let credential_scope = format!("{date_stamp}/{}/s3/aws4_request", self.region);
        let string_to_sign = [
            algorithm.to_string(),
            amz_date.to_string(),
            credential_scope.clone(),
            sha256_hex(canonical.as_bytes()),
        ]
        .join("\n");

        let k_date = hmac_bytes(
            format!("AWS4{}", self.secret_key).as_bytes(),
            date_stamp.as_bytes(),
        );
        let k_region = hmac_bytes(&k_date, self.region.as_bytes());
        let k_service = hmac_bytes(&k_region, b"s3");
        let k_signing = hmac_bytes(&k_service, b"aws4_request");
        let signature = hex::encode(hmac_bytes(&k_signing, string_to_sign.as_bytes()));

        format!(
            "{algorithm} Credential={}/{credential_scope},SignedHeaders={signed_headers},Signature={signature}",
            self.access_key
        )
    }

    fn upload_parallel_inner(
        &self,
        source: &mut dyn ReadSeek,
        path: &Path,
        content_type: &str,
        options: ParallelUploadOptions,
    ) -> Result<(), StorageError> {
        if options.part_size == 0 {
            return Err(StorageError::message("part size must be greater than zero"));
        }
        if options.concurrency == 0 {
            return Err(StorageError::message(
                "upload concurrency must be greater than zero",
            ));
        }

        let size = source
            .seek(SeekFrom::End(0))
            .map_err(|error| StorageError::message(format!("failed to size upload: {error}")))?;
        source
            .rewind()
            .map_err(|error| StorageError::message(format!("failed to rewind upload: {error}")))?;

        if size <= options.part_size as u64 {
            let mut data = Vec::with_capacity(size as usize);
            source.read_to_end(&mut data).map_err(|error| {
                StorageError::message(format!("failed to read upload: {error}"))
            })?;
            self.call(
                "PUT",
                &uri_for_write(path),
                &data,
                &[],
                &[("content-type", content_type)],
                &[("x-amz-acl", self.acl.as_str())],
                true,
            )?;
            return Ok(());
        }

        let part_size = options.part_size.max(MIN_MULTIPART_PART_SIZE);
        let total_parts = size.div_ceil(part_size as u64) as u32;
        let concurrency = options.concurrency.min(total_parts as usize).max(1);
        let upload_id = self.create_multipart_upload(path, content_type)?;

        let result = (|| {
            let (job_tx, job_rx) = mpsc::sync_channel::<(u32, Bytes)>(concurrency);
            let job_rx = Arc::new(Mutex::new(job_rx));
            let (result_tx, result_rx) = mpsc::channel::<Result<(u32, String), StorageError>>();
            let in_flight = Arc::new(AtomicUsize::new(0));

            thread::scope(|scope| -> Result<(), StorageError> {
                for _ in 0..concurrency {
                    let job_rx = Arc::clone(&job_rx);
                    let result_tx = result_tx.clone();
                    let in_flight = Arc::clone(&in_flight);
                    let upload_id = upload_id.as_str();
                    scope.spawn(move || loop {
                        let job = {
                            let guard = job_rx
                                .lock()
                                .unwrap_or_else(std::sync::PoisonError::into_inner);
                            guard.recv()
                        };
                        let Ok((part, data)) = job else {
                            break;
                        };
                        in_flight.fetch_add(1, Ordering::Relaxed);
                        let outcome = self
                            .upload_part(&data, path, content_type, part, upload_id)
                            .map(|etag| (part, etag));
                        in_flight.fetch_sub(1, Ordering::Relaxed);
                        if result_tx.send(outcome).is_err() {
                            break;
                        }
                    });
                }
                drop(result_tx);

                let mut offset = 0_u64;
                for part in 1..=total_parts {
                    let window = ((size - offset) as usize).min(part_size);
                    let mut buf = vec![0_u8; window];
                    source
                        .seek(SeekFrom::Start(offset))
                        .map_err(|error| StorageError::message(format!("seek failed: {error}")))?;
                    source.read_exact(&mut buf).map_err(|error| {
                        StorageError::message(format!("read part {part} failed: {error}"))
                    })?;
                    offset += window as u64;
                    job_tx.send((part, Bytes::from(buf))).map_err(|_| {
                        StorageError::message("parallel upload worker channel closed")
                    })?;
                }
                drop(job_tx);

                let mut parts = HashMap::new();
                for _ in 0..total_parts {
                    let (part, etag) = result_rx.recv().map_err(|_| {
                        StorageError::message("parallel upload result channel closed")
                    })??;
                    parts.insert(part, PartValue::Etag(etag));
                }
                debug_assert_eq!(in_flight.load(Ordering::Relaxed), 0);
                self.complete_multipart_upload(path, &upload_id, &parts)?;
                Ok(())
            })?;
            Ok(())
        })();

        if let Err(error) = result {
            let _ = self.abort(path, &upload_id);
            return Err(error);
        }
        Ok(())
    }
}

impl Device for S3 {
    fn get_type(&self) -> DeviceType {
        DeviceType::S3
    }

    fn get_root(&self) -> &Path {
        &self.root
    }

    fn get_path(&self, filename: &str) -> PathBuf {
        PathBuf::from(format!(
            "{}/{}",
            self.root.to_string_lossy().trim_end_matches('/'),
            filename.trim_start_matches('/')
        ))
    }

    fn prepare(
        &self,
        path: &Path,
        content_type: &str,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<(), StorageError> {
        metadata
            .content_type
            .get_or_insert_with(|| content_type.to_string());
        if chunks == 1
            || metadata
                .upload_id
                .as_ref()
                .is_some_and(|id| !id.is_empty() && id != "0")
        {
            return Ok(());
        }

        metadata.upload_id = Some(self.create_multipart_upload(path, content_type)?);
        Ok(())
    }

    fn upload_chunk(
        &self,
        data: &[u8],
        path: &Path,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        let content_type = metadata.content_type.clone().unwrap_or_default();
        if chunk == 1 && chunks == 1 {
            self.write(path, data, &content_type)?;
            metadata.parts.insert(chunk, PartValue::Done);
            metadata.chunks = 1;
            return Ok(1);
        }

        let upload_id = metadata
            .upload_id
            .clone()
            .filter(|id| !id.is_empty())
            .ok_or_else(|| UploadError("Missing multipart upload ID".to_string()))?;
        let etag = self.upload_part(data, path, &content_type, chunk, &upload_id)?;
        if !metadata.parts.contains_key(&chunk) {
            metadata.chunks += 1;
        }
        metadata.parts.insert(chunk, PartValue::Etag(etag));
        Ok(metadata.chunks)
    }

    fn finalize(
        &self,
        path: &Path,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<bool, StorageError> {
        if self.object_exists(path)? {
            return Ok(true);
        }
        if chunks == 1 {
            return Ok(false);
        }
        let upload_id = metadata
            .upload_id
            .as_deref()
            .filter(|id| !id.is_empty())
            .ok_or_else(|| UploadError("Missing multipart upload ID".to_string()))?;

        for part in 1..=chunks {
            if !metadata.parts.contains_key(&part) {
                return Err(UploadError(format!("Missing chunk {part}")).into());
            }
        }

        self.complete_multipart_upload(path, upload_id, &metadata.parts)
    }

    fn abort(&self, path: &Path, upload_id: &str) -> Result<bool, StorageError> {
        self.call(
            "DELETE",
            &uri_for_write(path),
            &[],
            &[("uploadId", upload_id)],
            &[],
            &[],
            true,
        )?;
        Ok(true)
    }

    fn read(&self, path: &Path, offset: u64, length: Option<u64>) -> Result<Vec<u8>, StorageError> {
        let mut buffer = Vec::new();
        self.read_into(path, &mut buffer, offset, length)?;
        Ok(buffer)
    }

    fn read_into(
        &self,
        path: &Path,
        writer: &mut dyn Write,
        offset: u64,
        length: Option<u64>,
    ) -> Result<u64, StorageError> {
        if length == Some(0) {
            return Ok(0);
        }

        let range = if let Some(length) = length {
            Some(format!("bytes={offset}-{}", offset + length - 1))
        } else if offset > 0 {
            Some(format!("bytes={offset}-"))
        } else {
            None
        };
        let headers = range
            .as_deref()
            .map_or_else(Vec::new, |range| vec![("range", range)]);

        let mut counter = CountingWriter {
            inner: writer,
            count: 0,
        };
        self.call_with_sink(
            "GET",
            &uri_for_read(path),
            &[],
            &[],
            &headers,
            &[],
            false,
            Some(&mut counter),
        )?;
        Ok(counter.count)
    }

    fn write(&self, path: &Path, data: &[u8], content_type: &str) -> Result<(), StorageError> {
        if data.len() > MIN_MULTIPART_PART_SIZE {
            let mut cursor = Cursor::new(data);
            return self.upload_parallel(
                &mut cursor,
                path,
                content_type,
                ParallelUploadOptions::default(),
            );
        }
        self.call(
            "PUT",
            &uri_for_write(path),
            data,
            &[],
            &[("content-type", content_type)],
            &[("x-amz-acl", self.acl.as_str())],
            true,
        )?;
        Ok(())
    }

    fn write_from(
        &self,
        path: &Path,
        reader: &mut dyn ReadSeek,
        content_type: &str,
    ) -> Result<(), StorageError> {
        let size = reader
            .seek(SeekFrom::End(0))
            .map_err(|error| StorageError::message(format!("failed to size upload: {error}")))?;
        reader
            .rewind()
            .map_err(|error| StorageError::message(format!("failed to rewind upload: {error}")))?;

        // Large objects use multipart so we never hold the full payload.
        if size > MIN_MULTIPART_PART_SIZE as u64 {
            return self.upload_parallel(
                reader,
                path,
                content_type,
                ParallelUploadOptions::default(),
            );
        }

        let mut data = Vec::with_capacity(size as usize);
        reader
            .read_to_end(&mut data)
            .map_err(|error| StorageError::message(format!("failed to read upload: {error}")))?;
        self.call(
            "PUT",
            &uri_for_write(path),
            &data,
            &[],
            &[("content-type", content_type)],
            &[("x-amz-acl", self.acl.as_str())],
            true,
        )?;
        Ok(())
    }

    fn upload_from(
        &self,
        reader: &mut dyn Read,
        path: &Path,
        content_type: &str,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        // Part bodies are bounded by the caller/part size; still avoid an extra copy
        // when the source is already a Cursor over a slice.
        let mut data = Vec::new();
        reader.read_to_end(&mut data).map_err(|error| {
            StorageError::message(format!("failed to read upload part: {error}"))
        })?;
        self.upload(&data, path, content_type, chunk, chunks, metadata)
    }

    fn upload_parallel(
        &self,
        source: &mut dyn ReadSeek,
        path: &Path,
        content_type: &str,
        options: ParallelUploadOptions,
    ) -> Result<(), StorageError> {
        self.upload_parallel_inner(source, path, content_type, options)
    }

    fn copy(
        &self,
        source: &Path,
        target: &Path,
        to: Option<&dyn Device>,
        chunk_size: usize,
    ) -> Result<(), StorageError>
    where
        Self: Sized,
    {
        if to.is_none() && self.bucket.is_some() {
            return self.copy_object(source, target);
        }
        if chunk_size == 0 {
            return Err(StorageError::message(
                "chunk size must be greater than zero",
            ));
        }
        if let Some(destination) = to {
            return copy_between(self, destination, source, target, chunk_size);
        }
        copy_between(self, self, source, target, chunk_size)
    }

    fn r#move(&self, source: &Path, target: &Path) -> Result<bool, StorageError> {
        if source == target {
            return Ok(false);
        }
        self.copy(source, target, None, COPY_CHUNK_SIZE)?;
        self.delete(source, false)
    }

    fn delete(&self, path: &Path, _recursive: bool) -> Result<bool, StorageError> {
        self.call("DELETE", &uri_for_read(path), &[], &[], &[], &[], true)?;
        Ok(true)
    }

    fn delete_path(&self, path: &str) -> Result<bool, StorageError> {
        let prefix = self.get_path(path);
        let mut continuation_token = None;
        loop {
            let objects =
                self.list_objects(&prefix, MAX_PAGE_SIZE, continuation_token.as_deref())?;
            continuation_token = objects
                .get("NextContinuationToken")
                .and_then(XmlValue::as_str)
                .map(str::to_string);

            let keys = objects
                .get("Contents")
                .map(XmlValue::entries)
                .unwrap_or_default()
                .into_iter()
                .filter_map(|object| object.get("Key").and_then(XmlValue::as_str))
                .map(str::to_string)
                .collect::<Vec<_>>();
            if keys.is_empty() {
                break;
            }

            let mut body =
                String::from("<Delete xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">");
            for key in keys {
                body.push_str("<Object><Key>");
                body.push_str(&xml_escape(&key));
                body.push_str("</Key></Object>");
            }
            body.push_str("<Quiet>true</Quiet></Delete>");
            self.call(
                "POST",
                "/",
                body.as_bytes(),
                &[("delete", "")],
                &[("content-type", "application/xml")],
                &[],
                true,
            )?;

            if continuation_token.is_none() {
                break;
            }
        }
        Ok(true)
    }

    fn exists(&self, path: &Path) -> bool {
        self.object_exists(path).unwrap_or(false)
    }

    fn list_files(
        &self,
        prefix: &Path,
        max: usize,
        cursor: Option<&str>,
    ) -> Result<FileList, StorageError> {
        let data = self.list_objects(prefix, max, cursor)?;
        let files = data
            .get("Contents")
            .map(XmlValue::entries)
            .unwrap_or_default()
            .into_iter()
            .filter_map(|object| {
                let key = object.get("Key")?.as_str()?;
                let size = object
                    .get("Size")
                    .and_then(XmlValue::as_str)
                    .and_then(|size| size.parse::<u64>().ok())
                    .unwrap_or(0);
                let modified_at = object
                    .get("LastModified")
                    .and_then(XmlValue::as_str)
                    .and_then(parse_s3_time);
                let etag = object.get("ETag").and_then(XmlValue::as_str).map(trim_etag);
                Some(FileInfo::new(key, size, modified_at, etag))
            })
            .collect::<Vec<_>>();

        let cursor = if data.get("IsTruncated").and_then(XmlValue::as_str) == Some("true") {
            data.get("NextContinuationToken")
                .and_then(XmlValue::as_str)
                .map(str::to_string)
        } else {
            None
        };

        Ok(FileList::new(files, cursor))
    }

    fn get_file_size(&self, path: &Path) -> Result<u64, StorageError> {
        Ok(self
            .get_info(path)?
            .get("content-length")
            .and_then(|value| value.parse::<u64>().ok())
            .unwrap_or(0))
    }

    fn get_file_mime_type(&self, path: &Path) -> Result<String, StorageError> {
        Ok(self
            .get_info(path)?
            .get("content-type")
            .cloned()
            .unwrap_or_default())
    }

    fn get_file_hash(&self, path: &Path) -> Result<String, StorageError> {
        Ok(self
            .get_info(path)?
            .get("etag")
            .map_or_else(String::new, |etag| trim_etag(etag)))
    }

    fn create_directory(&self, _path: &Path) -> Result<bool, StorageError> {
        Ok(true)
    }
}

macro_rules! provider {
    ($name:ident, $device_type:expr) => {
        #[derive(Debug, Clone)]
        pub struct $name {
            inner: S3,
        }

        impl $name {
            pub fn inner(&self) -> &S3 {
                &self.inner
            }

            pub fn into_inner(self) -> S3 {
                self.inner
            }
        }

        impl Device for $name {
            fn get_type(&self) -> DeviceType {
                $device_type
            }

            fn get_root(&self) -> &Path {
                self.inner.get_root()
            }

            fn get_path(&self, filename: &str) -> PathBuf {
                self.inner.get_path(filename)
            }

            fn prepare(
                &self,
                path: &Path,
                content_type: &str,
                chunks: u32,
                metadata: &mut UploadMetadata,
            ) -> Result<(), StorageError> {
                self.inner.prepare(path, content_type, chunks, metadata)
            }

            fn upload_chunk(
                &self,
                data: &[u8],
                path: &Path,
                chunk: u32,
                chunks: u32,
                metadata: &mut UploadMetadata,
            ) -> Result<u32, StorageError> {
                self.inner.upload_chunk(data, path, chunk, chunks, metadata)
            }

            fn finalize(
                &self,
                path: &Path,
                chunks: u32,
                metadata: &mut UploadMetadata,
            ) -> Result<bool, StorageError> {
                self.inner.finalize(path, chunks, metadata)
            }

            fn abort(&self, path: &Path, upload_id: &str) -> Result<bool, StorageError> {
                self.inner.abort(path, upload_id)
            }

            fn read(
                &self,
                path: &Path,
                offset: u64,
                length: Option<u64>,
            ) -> Result<Vec<u8>, StorageError> {
                self.inner.read(path, offset, length)
            }

            fn read_into(
                &self,
                path: &Path,
                writer: &mut dyn Write,
                offset: u64,
                length: Option<u64>,
            ) -> Result<u64, StorageError> {
                self.inner.read_into(path, writer, offset, length)
            }

            fn write(
                &self,
                path: &Path,
                data: &[u8],
                content_type: &str,
            ) -> Result<(), StorageError> {
                self.inner.write(path, data, content_type)
            }

            fn write_from(
                &self,
                path: &Path,
                reader: &mut dyn ReadSeek,
                content_type: &str,
            ) -> Result<(), StorageError> {
                self.inner.write_from(path, reader, content_type)
            }

            fn upload_from(
                &self,
                reader: &mut dyn Read,
                path: &Path,
                content_type: &str,
                chunk: u32,
                chunks: u32,
                metadata: &mut UploadMetadata,
            ) -> Result<u32, StorageError> {
                self.inner
                    .upload_from(reader, path, content_type, chunk, chunks, metadata)
            }

            fn upload_parallel(
                &self,
                source: &mut dyn ReadSeek,
                path: &Path,
                content_type: &str,
                options: ParallelUploadOptions,
            ) -> Result<(), StorageError> {
                self.inner
                    .upload_parallel(source, path, content_type, options)
            }

            fn copy(
                &self,
                source: &Path,
                target: &Path,
                to: Option<&dyn Device>,
                chunk_size: usize,
            ) -> Result<(), StorageError>
            where
                Self: Sized,
            {
                self.inner.copy(source, target, to, chunk_size)
            }

            fn r#move(&self, source: &Path, target: &Path) -> Result<bool, StorageError> {
                self.inner.r#move(source, target)
            }

            fn delete(&self, path: &Path, recursive: bool) -> Result<bool, StorageError> {
                self.inner.delete(path, recursive)
            }

            fn delete_path(&self, path: &str) -> Result<bool, StorageError> {
                self.inner.delete_path(path)
            }

            fn exists(&self, path: &Path) -> bool {
                self.inner.exists(path)
            }

            fn list_files(
                &self,
                prefix: &Path,
                max: usize,
                cursor: Option<&str>,
            ) -> Result<FileList, StorageError> {
                self.inner.list_files(prefix, max, cursor)
            }

            fn get_file_size(&self, path: &Path) -> Result<u64, StorageError> {
                self.inner.get_file_size(path)
            }

            fn get_file_mime_type(&self, path: &Path) -> Result<String, StorageError> {
                self.inner.get_file_mime_type(path)
            }

            fn get_file_hash(&self, path: &Path) -> Result<String, StorageError> {
                self.inner.get_file_hash(path)
            }

            fn create_directory(&self, path: &Path) -> Result<bool, StorageError> {
                self.inner.create_directory(path)
            }
        }
    };
}

provider!(AwsS3, DeviceType::AwsS3);
provider!(DoSpaces, DeviceType::DoSpaces);
provider!(Linode, DeviceType::Linode);
provider!(Backblaze, DeviceType::Backblaze);
provider!(Wasabi, DeviceType::Wasabi);

impl AwsS3 {
    pub const US_EAST_1: &'static str = "us-east-1";
    pub const US_EAST_2: &'static str = "us-east-2";
    pub const US_WEST_1: &'static str = "us-west-1";
    pub const US_WEST_2: &'static str = "us-west-2";
    pub const AF_SOUTH_1: &'static str = "af-south-1";
    pub const AP_EAST_1: &'static str = "ap-east-1";
    pub const AP_SOUTH_1: &'static str = "ap-south-1";
    pub const AP_NORTHEAST_3: &'static str = "ap-northeast-3";
    pub const AP_NORTHEAST_2: &'static str = "ap-northeast-2";
    pub const AP_NORTHEAST_1: &'static str = "ap-northeast-1";
    pub const AP_SOUTHEAST_1: &'static str = "ap-southeast-1";
    pub const AP_SOUTHEAST_2: &'static str = "ap-southeast-2";
    pub const CA_CENTRAL_1: &'static str = "ca-central-1";
    pub const EU_CENTRAL_1: &'static str = "eu-central-1";
    pub const EU_WEST_1: &'static str = "eu-west-1";
    pub const EU_SOUTH_1: &'static str = "eu-south-1";
    pub const EU_WEST_2: &'static str = "eu-west-2";
    pub const EU_WEST_3: &'static str = "eu-west-3";
    pub const EU_NORTH_1: &'static str = "eu-north-1";
    pub const SA_EAST_1: &'static str = "eu-north-1";
    pub const CN_NORTH_1: &'static str = "cn-north-1";
    pub const CN_NORTH_4: &'static str = "cn-north-4";
    pub const CN_NORTHWEST_1: &'static str = "cn-northwest-1";
    pub const ME_SOUTH_1: &'static str = "me-south-1";
    pub const US_GOV_EAST_1: &'static str = "us-gov-east-1";
    pub const US_GOV_WEST_1: &'static str = "us-gov-west-1";

    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        let bucket = bucket.into();
        let region = region.into();
        let host = if matches!(
            region.as_str(),
            Self::CN_NORTH_1 | Self::CN_NORTH_4 | Self::CN_NORTHWEST_1
        ) {
            format!("{bucket}.s3.{region}.amazonaws.cn")
        } else {
            format!("{bucket}.s3.{region}.amazonaws.com")
        };
        Ok(Self {
            inner: S3::with_bucket(root, access_key, secret_key, host, region, acl, bucket)?,
        })
    }
}

impl DoSpaces {
    pub const SGP1: &'static str = "sgp1";
    pub const NYC3: &'static str = "nyc3";
    pub const FRA1: &'static str = "fra1";
    pub const SFO2: &'static str = "sfo2";
    pub const SFO3: &'static str = "sfo3";
    pub const AMS3: &'static str = "AMS3";

    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        let bucket = bucket.into();
        let region = region.into();
        let host = format!("{bucket}.{region}.digitaloceanspaces.com");
        Ok(Self {
            inner: S3::with_bucket(root, access_key, secret_key, host, region, acl, bucket)?,
        })
    }
}

impl Linode {
    pub const EU_CENTRAL_1: &'static str = "eu-central-1";
    pub const US_SOUTHEAST_1: &'static str = "us-southeast-1";
    pub const US_EAST_1: &'static str = "us-east-1";
    pub const AP_SOUTH_1: &'static str = "ap-south-1";

    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        let bucket = bucket.into();
        let region = region.into();
        let host = format!("{bucket}.{region}.linodeobjects.com");
        Ok(Self {
            inner: S3::with_bucket(root, access_key, secret_key, host, region, acl, bucket)?,
        })
    }
}

impl Backblaze {
    pub const US_WEST_000: &'static str = "us-west-000";
    pub const US_WEST_001: &'static str = "us-west-001";
    pub const US_WEST_002: &'static str = "us-west-002";
    pub const US_WEST_004: &'static str = "us-west-004";
    pub const EU_CENTRAL_003: &'static str = "eu-central-003";

    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        let bucket = bucket.into();
        let region = region.into();
        let host = format!("{bucket}.s3.{region}.backblazeb2.com");
        Ok(Self {
            inner: S3::with_bucket(root, access_key, secret_key, host, region, acl, bucket)?,
        })
    }
}

impl Wasabi {
    pub const US_WEST_1: &'static str = "us-west-1";
    pub const AP_NORTHEAST_1: &'static str = "ap-northeast-1";
    pub const AP_NORTHEAST_2: &'static str = "ap-northeast-2";
    pub const EU_CENTRAL_1: &'static str = "eu-central-1";
    pub const EU_CENTRAL_2: &'static str = "eu-central-2";
    pub const EU_WEST_1: &'static str = "eu-west-1";
    pub const EU_WEST_2: &'static str = "eu-west-2";
    pub const US_CENTRAL_1: &'static str = "us-central-1";
    pub const US_EAST_1: &'static str = "us-east-1";
    pub const US_EAST_2: &'static str = "us-east-2";

    pub fn new(
        root: impl Into<PathBuf>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
        acl: Acl,
    ) -> Result<Self, StorageError> {
        let bucket = bucket.into();
        let region = region.into();
        let host = format!("{bucket}.s3.{region}.wasabisys.com");
        Ok(Self {
            inner: S3::with_bucket(root, access_key, secret_key, host, region, acl, bucket)?,
        })
    }
}

impl S3Body {
    fn xml(&self) -> Option<&XmlValue> {
        match self {
            Self::Xml(value) => Some(value),
            Self::Bytes(_) => None,
        }
    }
}

struct CountingWriter<'a> {
    inner: &'a mut dyn Write,
    count: u64,
}

impl Write for CountingWriter<'_> {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        let written = self.inner.write(buf)?;
        self.count += written as u64;
        Ok(written)
    }

    fn flush(&mut self) -> std::io::Result<()> {
        self.inner.flush()
    }
}

fn hash_body(body: &[u8]) -> (String, String) {
    let md5 = Md5::digest(body);
    let sha256 = Sha256::digest(body);
    (
        base64::engine::general_purpose::STANDARD.encode(md5),
        hex::encode(sha256),
    )
}

fn sha256_hex(body: &[u8]) -> String {
    hex::encode(Sha256::digest(body))
}

fn hmac_bytes(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = HmacSha256::new_from_slice(key).expect("HMAC accepts keys of any size");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn build_query(parameters: &[(&str, &str)]) -> String {
    let mut sorted = parameters.to_vec();
    sorted.sort_by(|(left, _), (right, _)| left.cmp(right));
    let mut serializer = url::form_urlencoded::Serializer::new(String::new());
    for (key, value) in sorted {
        serializer.append_pair(key, value);
    }
    serializer.finish()
}

fn percent_encode(value: &str) -> String {
    url::form_urlencoded::byte_serialize(value.as_bytes()).collect()
}

fn uri_for_write(path: &Path) -> String {
    let key = path_to_key(path);
    let encoded = percent_encode(&key).replace("%2F", "/").replace("%3F", "?");
    if encoded.is_empty() {
        "/".to_string()
    } else {
        format!("/{encoded}")
    }
}

fn uri_for_read(path: &Path) -> String {
    let key = path_to_key(path);
    let encoded = percent_encode(&key).replace("%2F", "/");
    if encoded.is_empty() {
        "/".to_string()
    } else {
        format!("/{encoded}")
    }
}

fn path_to_key(path: &Path) -> String {
    path_to_string(path).trim_start_matches('/').to_string()
}

fn path_to_string(path: &Path) -> String {
    path.to_string_lossy().replace('\\', "/")
}

fn default_s3_client() -> Client<curl::Client> {
    Client::new(curl::Client::new()).with_connection_reuse(true)
}

fn lower_headers(headers: &HeaderMap) -> HashMap<String, String> {
    let mut output = HashMap::new();
    for (name, value) in headers {
        if let Ok(value) = value.to_str() {
            output.insert(name.as_str().to_ascii_lowercase(), value.to_string());
        }
    }
    output
}

fn parse_s3_error(body: &[u8], status: u16, headers: &HashMap<String, String>) -> StorageError {
    let text = String::from_utf8_lossy(body);
    let trimmed = text.trim_start();
    let (error_code, message) = if trimmed.starts_with("<?xml") || trimmed.starts_with("<Error") {
        parse_xml(&text).map_or((None, None), |xml| {
            (
                xml.get("Code")
                    .and_then(XmlValue::as_str)
                    .map(str::to_string),
                xml.get("Message")
                    .and_then(XmlValue::as_str)
                    .map(str::to_string),
            )
        })
    } else {
        (None, None)
    };

    let request_ids = request_ids(headers);
    if status == 404 || error_code.as_deref() == Some("NoSuchKey") {
        let mut message = message.unwrap_or_else(|| "File not found".to_string());
        append_request_ids(&mut message, &request_ids);
        return NotFound(message).into();
    }

    StorageError::remote(
        status,
        error_code,
        message.unwrap_or_else(|| {
            if text.is_empty() {
                "S3 request failed".to_string()
            } else {
                text.to_string()
            }
        }),
        request_ids,
    )
}

fn request_ids(headers: &HashMap<String, String>) -> HashMap<String, String> {
    let mut ids = HashMap::new();
    if let Some(value) = headers
        .get("x-amz-request-id")
        .filter(|value| !value.is_empty())
    {
        ids.insert("request-id".to_string(), value.clone());
    }
    if let Some(value) = headers.get("x-amz-id-2").filter(|value| !value.is_empty()) {
        ids.insert("id-2".to_string(), value.clone());
    }
    ids
}

fn append_request_ids(message: &mut String, request_ids: &HashMap<String, String>) {
    if request_ids.is_empty() {
        return;
    }
    let mut ids = request_ids.iter().collect::<Vec<_>>();
    ids.sort_by(|(left, _), (right, _)| left.cmp(right));
    message.push_str(" [");
    message.push_str(
        &ids.into_iter()
            .map(|(key, value)| format!("{key}: {value}"))
            .collect::<Vec<_>>()
            .join(", "),
    );
    message.push(']');
}

#[derive(Default)]
struct XmlNode {
    children: BTreeMap<String, Vec<XmlValue>>,
    text: String,
}

fn parse_xml(body: &str) -> Result<XmlValue, StorageError> {
    let mut reader = Reader::from_str(body);
    reader.config_mut().trim_text(true);
    let mut stack: Vec<(String, XmlNode)> = Vec::new();

    loop {
        match reader.read_event() {
            Ok(Event::Start(event)) => {
                let name = String::from_utf8_lossy(event.name().as_ref()).into_owned();
                stack.push((name, XmlNode::default()));
            }
            Ok(Event::Empty(event)) => {
                let name = String::from_utf8_lossy(event.name().as_ref()).into_owned();
                let value = XmlValue::Map(BTreeMap::new());
                if let Some((_, parent)) = stack.last_mut() {
                    parent.children.entry(name).or_default().push(value);
                } else {
                    return Ok(value);
                }
            }
            Ok(Event::Text(event)) => {
                if let Some((_, node)) = stack.last_mut() {
                    let text = event
                        .unescape()
                        .map_err(|error| {
                            StorageError::remote(
                                200,
                                None,
                                format!("Failed to decode S3 XML response: {error}"),
                                HashMap::new(),
                            )
                        })?
                        .into_owned();
                    node.text.push_str(&text);
                }
            }
            Ok(Event::CData(event)) => {
                if let Some((_, node)) = stack.last_mut() {
                    node.text.push_str(&String::from_utf8_lossy(event.as_ref()));
                }
            }
            Ok(Event::End(_)) => {
                let Some((name, node)) = stack.pop() else {
                    return Err(StorageError::remote(
                        200,
                        None,
                        "Failed to decode S3 XML response",
                        HashMap::new(),
                    ));
                };
                let value = node.into_value();
                if let Some((_, parent)) = stack.last_mut() {
                    parent.children.entry(name).or_default().push(value);
                } else {
                    return Ok(value);
                }
            }
            Ok(Event::Eof) => break,
            Err(error) => {
                return Err(StorageError::remote(
                    200,
                    None,
                    format!("Failed to decode S3 XML response: {error}"),
                    HashMap::new(),
                ));
            }
            _ => {}
        }
    }

    Err(StorageError::remote(
        200,
        None,
        "Failed to decode S3 XML response",
        HashMap::new(),
    ))
}

impl XmlNode {
    fn into_value(self) -> XmlValue {
        if self.children.is_empty() {
            return if self.text.is_empty() {
                XmlValue::Map(BTreeMap::new())
            } else {
                XmlValue::Text(self.text)
            };
        }

        let mut map = BTreeMap::new();
        for (name, mut values) in self.children {
            let value = if values.len() == 1 {
                values.pop().expect("len checked")
            } else {
                XmlValue::List(values)
            };
            map.insert(name, value);
        }
        XmlValue::Map(map)
    }
}

fn parse_s3_time(value: &str) -> Option<SystemTime> {
    let parsed = OffsetDateTime::parse(value, &Rfc3339).ok()?;
    let seconds = parsed.unix_timestamp();
    let nanos = parsed.nanosecond();
    if seconds >= 0 {
        Some(UNIX_EPOCH + Duration::new(seconds as u64, nanos))
    } else {
        UNIX_EPOCH.checked_sub(Duration::new(seconds.unsigned_abs(), nanos))
    }
}

fn trim_etag(value: &str) -> String {
    value.trim_matches('"').to_string()
}

fn xml_escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&apos;")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fixed_random() -> f64 {
        1.0
    }

    #[test]
    fn retry_strategy_detects_transient_xml_errors() {
        let body = br#"<?xml version="1.0"?><Error><Code>SlowDown</Code></Error>"#;
        let strategy = RetryStrategy::new(3, Duration::from_millis(500), Duration::from_secs(20))
            .with_randomizer(fixed_random);
        assert_eq!(
            strategy.delay(1, 503, body),
            Some(Duration::from_millis(500))
        );
    }

    #[test]
    fn retry_strategy_respects_non_transient_xml_code() {
        let body = br#"<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>"#;
        assert_eq!(RetryStrategy::default().delay(1, 503, body), None);
    }

    #[test]
    fn xml_repeated_children_become_lists() {
        let xml = parse_xml(
            "<ListBucketResult><Contents><Key>a</Key></Contents><Contents><Key>b</Key></Contents></ListBucketResult>",
        )
        .expect("xml");
        assert_eq!(xml.get("Contents").expect("contents").entries().len(), 2);
    }
}
