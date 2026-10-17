//! [`S3`]: Amazon S3 and S3-compatible object stores, and the HTTP client,
//! retry strategy and region names it works with.

mod client;
mod retry;
#[cfg(test)]
mod tests;
mod uri;
pub(crate) mod xml;

use std::any::Any;
use std::sync::Arc;

use async_trait::async_trait;
use base64::Engine as _;
use bytes::Bytes;
use chrono::{DateTime, FixedOffset};
use hmac::{Hmac, Mac};
use indexmap::IndexMap;
use md5::Md5;
use sha2::{Digest, Sha256};

pub use client::{Client, HttpClient, Method, Payload, Request, Response, Retry, TransportError};
pub use retry::RetryStrategy;

use crate::device::{self, Acl, Device, DeviceType, FileInfo, FileList, Metadata, Part, Sink, UploadInfo, UploadList};
use crate::{Body, Error, Result};
use xml::Decoded;

/// The most objects or uploads one listing request returns.
const MAX_PAGE_SIZE: i64 = 1000;
/// How much of a streamed error response is kept to read the error from.
const ERROR_BODY_BUFFER_SIZE: usize = 16384;

/// The error number of a response body that ended early: cURL's
/// `CURLE_PARTIAL_FILE`.
const CURLE_PARTIAL_FILE: i64 = 18;
/// The CopyObject limit; larger objects copy server side part by part.
const MAX_COPY_OBJECT_SIZE: u64 = 5_368_709_120;

/// Region names of the providers [`S3`] has constructors for.
///
/// PHP: the region constants of `AWS`, `Backblaze`, `DOSpaces`, `Linode`
/// and `Wasabi`.
pub mod regions {
    /// Amazon S3 regions.
    pub mod aws {
        #![allow(missing_docs)]
        pub const US_EAST_1: &str = "us-east-1";
        pub const US_EAST_2: &str = "us-east-2";
        pub const US_WEST_1: &str = "us-west-1";
        pub const US_WEST_2: &str = "us-west-2";
        pub const AF_SOUTH_1: &str = "af-south-1";
        pub const AP_EAST_1: &str = "ap-east-1";
        pub const AP_SOUTH_1: &str = "ap-south-1";
        pub const AP_NORTHEAST_3: &str = "ap-northeast-3";
        pub const AP_NORTHEAST_2: &str = "ap-northeast-2";
        pub const AP_NORTHEAST_1: &str = "ap-northeast-1";
        pub const AP_SOUTHEAST_1: &str = "ap-southeast-1";
        pub const AP_SOUTHEAST_2: &str = "ap-southeast-2";
        pub const CA_CENTRAL_1: &str = "ca-central-1";
        pub const EU_CENTRAL_1: &str = "eu-central-1";
        pub const EU_WEST_1: &str = "eu-west-1";
        pub const EU_SOUTH_1: &str = "eu-south-1";
        pub const EU_WEST_2: &str = "eu-west-2";
        pub const EU_WEST_3: &str = "eu-west-3";
        pub const EU_NORTH_1: &str = "eu-north-1";
        /// `eu-north-1`, as PHP's `AWS::SA_EAST_1` is (a quirk kept).
        pub const SA_EAST_1: &str = "eu-north-1";
        pub const CN_NORTH_1: &str = "cn-north-1";
        pub const CN_NORTH_4: &str = "cn-north-4";
        pub const CN_NORTHWEST_1: &str = "cn-northwest-1";
        pub const ME_SOUTH_1: &str = "me-south-1";
        pub const US_GOV_EAST_1: &str = "us-gov-east-1";
        pub const US_GOV_WEST_1: &str = "us-gov-west-1";
    }

    /// Backblaze B2 clusters.
    pub mod backblaze {
        #![allow(missing_docs)]
        pub const US_WEST_000: &str = "us-west-000";
        pub const US_WEST_001: &str = "us-west-001";
        pub const US_WEST_002: &str = "us-west-002";
        pub const US_WEST_004: &str = "us-west-004";
        pub const EU_CENTRAL_003: &str = "eu-central-003";
    }

    /// DigitalOcean Spaces regions.
    pub mod do_spaces {
        #![allow(missing_docs)]
        pub const SGP1: &str = "sgp1";
        pub const NYC3: &str = "nyc3";
        pub const FRA1: &str = "fra1";
        pub const SFO2: &str = "sfo2";
        pub const SFO3: &str = "sfo3";
        pub const AMS3: &str = "AMS3";
    }

    /// Linode Object Storage regions.
    pub mod linode {
        #![allow(missing_docs)]
        pub const EU_CENTRAL_1: &str = "eu-central-1";
        pub const US_SOUTHEAST_1: &str = "us-southeast-1";
        pub const US_EAST_1: &str = "us-east-1";
        pub const AP_SOUTH_1: &str = "ap-south-1";
    }

    /// Wasabi regions.
    pub mod wasabi {
        #![allow(missing_docs)]
        pub const US_WEST_1: &str = "us-west-1";
        pub const AP_NORTHEAST_1: &str = "ap-northeast-1";
        pub const AP_NORTHEAST_2: &str = "ap-northeast-2";
        pub const EU_CENTRAL_1: &str = "eu-central-1";
        pub const EU_CENTRAL_2: &str = "eu-central-2";
        pub const EU_WEST_1: &str = "eu-west-1";
        pub const EU_WEST_2: &str = "eu-west-2";
        pub const US_CENTRAL_1: &str = "us-central-1";
        pub const US_EAST_1: &str = "us-east-1";
        pub const US_EAST_2: &str = "us-east-2";
    }
}

/// A response S3 answered with: its status, its headers (lowercase names)
/// and its body, decoded when it is XML.
///
/// PHP: `Utopia\Storage\Device\S3\Response`.
struct S3Response {
    status: u16,
    headers: IndexMap<String, String>,
    body: ResponseBody,
}

enum ResponseBody {
    Raw(Bytes),
    Xml(Decoded),
    /// A streamed response's body, still to read.
    Stream(Body),
}

impl ResponseBody {
    fn decoded(&self) -> Option<&Decoded> {
        match self {
            ResponseBody::Xml(d @ Decoded::Array(_)) => Some(d),
            _ => None,
        }
    }
}

/// Amazon S3 or an S3-compatible object store (MinIO, Google Cloud Storage,
/// DigitalOcean Spaces, Backblaze, Linode, Wasabi...).
///
/// Every request is signed with AWS Signature V4 and carries an explicit
/// `Content-Length`. Writes return the object's ETag; [`Device::create`]
/// and [`Device::replace`] send `If-None-Match` and `If-Match`, which S3
/// checks in the same step as the write. Chunked uploads are multipart
/// uploads. With a bucket name, copies on the same device run server side.
/// The default client retries transient failures with [`RetryStrategy`].
///
/// ```no_run
/// use utopia_storage::{Body, Device, S3};
///
/// # async fn demo() -> utopia_storage::Result<()> {
/// let device = S3::new("uploads", "access-key", "secret-key", "http://minio:9000/bucket", "us-east-1")?;
/// let etag = device.write(&device.path("hello.txt"), Body::from("Hello World"), "text/plain").await?;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\Psr7\Stream;
/// use Utopia\Storage\Device\S3;
///
/// $device = new S3('uploads', 'access-key', 'secret-key', 'http://minio:9000/bucket', 'us-east-1');
/// // $etag = $device->write($device->getPath('hello.txt'), new Stream('Hello World'), 'text/plain');
/// echo $device->getPath('hello.txt'), "\n"; // uploads/hello.txt
/// ```
///
/// PHP: `Utopia\Storage\Device\S3`; [`S3::aws`], [`S3::do_spaces`],
/// [`S3::backblaze`], [`S3::linode`] and [`S3::wasabi`] are the `AWS`,
/// `DOSpaces`, `Backblaze`, `Linode` and `Wasabi` subclasses.
#[derive(Clone)]
pub struct S3 {
    root: String,
    access_key: String,
    secret_key: String,
    region: String,
    acl: Option<Acl>,
    bucket: Option<String>,
    kind: DeviceType,
    fqdn: String,
    host: String,
    path: String,
    client: Arc<dyn Client>,
}

impl std::fmt::Debug for S3 {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("S3")
            .field("root", &self.root)
            .field("kind", &self.kind)
            .field("endpoint", &self.fqdn)
            .field("path", &self.path)
            .field("region", &self.region)
            .field("bucket", &self.bucket)
            .finish_non_exhaustive()
    }
}

impl S3 {
    /// A device for the objects under `root` at `host`: a host name
    /// (`https://` is assumed) or a URL whose path is the bucket's
    /// (`http://minio:9000/bucket`). Objects are written with the `private`
    /// ACL, through the default client.
    ///
    /// PHP: `new S3($root, $accessKey, $secretKey, $host, $region)`.
    pub fn new(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        host: &str,
        region: impl Into<String>,
    ) -> Result<Self> {
        let endpoint = if host.starts_with("http://") || host.starts_with("https://") {
            host.to_owned()
        } else {
            format!("https://{host}")
        };
        let endpoint = uri::Uri::parse(endpoint.trim_end_matches('/'))?;
        let mut authority = endpoint.host.clone();
        if let Some(port) = endpoint.port() {
            authority.push_str(&format!(":{port}"));
        }
        let scheme = endpoint.scheme.clone();
        let path = endpoint.path.trim_end_matches('/').to_owned();
        Ok(S3 {
            root: root.into(),
            access_key: access_key.into(),
            secret_key: secret_key.into(),
            region: region.into(),
            acl: Some(Acl::Private),
            bucket: None,
            kind: DeviceType::S3,
            fqdn: format!("{scheme}://{authority}"),
            host: authority,
            path,
            client: Arc::new(Retry::new(HttpClient::new(), RetryStrategy::default())),
        })
    }

    /// The device writing objects with `acl`, or with no ACL header at all
    /// (which buckets with ACLs disabled require).
    ///
    /// PHP: the `$acl` argument.
    pub fn with_acl(mut self, acl: Option<Acl>) -> Self {
        self.acl = acl;
        self
    }

    /// The device sending its requests through `client` (used as is: wrap
    /// it in [`Retry`] for retries).
    ///
    /// PHP: the `$client` argument.
    pub fn with_client(mut self, client: impl Client + 'static) -> Self {
        self.client = Arc::new(client);
        self
    }

    /// The device knowing its bucket's name, so copies on it run server
    /// side.
    ///
    /// PHP: the `$bucket` argument.
    pub fn with_bucket(mut self, bucket: impl Into<String>) -> Self {
        self.bucket = Some(bucket.into());
        self
    }

    fn provider(
        kind: DeviceType,
        root: String,
        access_key: String,
        secret_key: String,
        bucket: String,
        host: &str,
        region: String,
    ) -> Result<Self> {
        let mut s3 = S3::new(root, access_key, secret_key, host, region)?.with_bucket(bucket);
        s3.kind = kind;
        Ok(s3)
    }

    /// Amazon S3: the bucket at `<bucket>.s3.<region>.amazonaws.com`
    /// (`.amazonaws.cn` in the China regions). See [`regions::aws`].
    ///
    /// PHP: `new AWS($root, $accessKey, $secretKey, $bucket, $region)`.
    pub fn aws(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
    ) -> Result<Self> {
        let (bucket, region) = (bucket.into(), region.into());
        let host = match region.as_str() {
            regions::aws::CN_NORTH_1 | regions::aws::CN_NORTH_4 | regions::aws::CN_NORTHWEST_1 => {
                format!("{bucket}.s3.{region}.amazonaws.cn")
            }
            _ => format!("{bucket}.s3.{region}.amazonaws.com"),
        };
        Self::provider(DeviceType::AwsS3, root.into(), access_key.into(), secret_key.into(), bucket, &host, region)
    }

    /// DigitalOcean Spaces: `<bucket>.<region>.digitaloceanspaces.com`. See
    /// [`regions::do_spaces`].
    ///
    /// PHP: `new DOSpaces($root, $accessKey, $secretKey, $bucket, $region)`.
    pub fn do_spaces(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
    ) -> Result<Self> {
        let (bucket, region) = (bucket.into(), region.into());
        let host = format!("{bucket}.{region}.digitaloceanspaces.com");
        Self::provider(DeviceType::DoSpaces, root.into(), access_key.into(), secret_key.into(), bucket, &host, region)
    }

    /// Backblaze B2: `<bucket>.s3.<region>.backblazeb2.com`. See
    /// [`regions::backblaze`].
    ///
    /// PHP: `new Backblaze($root, $accessKey, $secretKey, $bucket, $region)`.
    pub fn backblaze(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
    ) -> Result<Self> {
        let (bucket, region) = (bucket.into(), region.into());
        let host = format!("{bucket}.s3.{region}.backblazeb2.com");
        Self::provider(DeviceType::Backblaze, root.into(), access_key.into(), secret_key.into(), bucket, &host, region)
    }

    /// Linode Object Storage: `<bucket>.<region>.linodeobjects.com`. See
    /// [`regions::linode`].
    ///
    /// PHP: `new Linode($root, $accessKey, $secretKey, $bucket, $region)`.
    pub fn linode(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
    ) -> Result<Self> {
        let (bucket, region) = (bucket.into(), region.into());
        let host = format!("{bucket}.{region}.linodeobjects.com");
        Self::provider(DeviceType::Linode, root.into(), access_key.into(), secret_key.into(), bucket, &host, region)
    }

    /// Wasabi: `<bucket>.s3.<region>.wasabisys.com`. See [`regions::wasabi`].
    ///
    /// PHP: `new Wasabi($root, $accessKey, $secretKey, $bucket, $region)`.
    pub fn wasabi(
        root: impl Into<String>,
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        bucket: impl Into<String>,
        region: impl Into<String>,
    ) -> Result<Self> {
        let (bucket, region) = (bucket.into(), region.into());
        let host = format!("{bucket}.s3.{region}.wasabisys.com");
        Self::provider(DeviceType::Wasabi, root.into(), access_key.into(), secret_key.into(), bucket, &host, region)
    }

    /// One page of at most `max` multipart uploads in progress under
    /// `prefix`: prepared but neither finalized nor aborted, and billed
    /// until one or the other happens. Amazon S3 takes any prefix; MinIO
    /// only honours a whole key.
    ///
    /// PHP: `listUploads()`.
    pub async fn list_uploads(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<UploadList> {
        if max > MAX_PAGE_SIZE {
            return Err(Error::InvalidArgument(format!("Cannot list more than {MAX_PAGE_SIZE} uploads")));
        }
        let mut parameters = vec![
            ("uploads".to_owned(), String::new()),
            ("prefix".to_owned(), prefix.trim_start_matches('/').to_owned()),
            ("max-uploads".to_owned(), max.to_string()),
        ];
        if let Some(cursor) = cursor.filter(|c| !c.is_empty()) {
            let (key, upload) = decode_cursor(cursor).ok_or_else(|| Error::InvalidArgument("Invalid cursor".into()))?;
            parameters.push(("key-marker".to_owned(), key));
            parameters.push(("upload-id-marker".to_owned(), upload));
        }
        let response = self
            .call(Method::Get, "/", Hashed::empty(), &parameters, &[("content-type", "text/plain")], &[], true, false)
            .await?;
        let body = response.body.decoded().ok_or_else(|| Error::remote("Unexpected S3 upload listing response"))?;
        let mut uploads = Vec::new();
        for entry in body.entries("Upload") {
            let (Some(key), Some(id)) = (xml::text(entry, "Key"), xml::text(entry, "UploadId")) else { continue };
            uploads.push(UploadInfo {
                path: key.to_owned(),
                upload_id: id.to_owned(),
                initiated_at: xml::text(entry, "Initiated").and_then(date),
            });
        }
        let key_marker = body.text("NextKeyMarker");
        let upload_marker = body.text("NextUploadIdMarker");
        let cursor = match (body.text("IsTruncated"), key_marker, upload_marker) {
            (Some("true"), Some(key), Some(upload)) => Some(encode_cursor(key, upload)),
            _ => None,
        };
        Ok(UploadList { uploads, cursor })
    }

    fn acl_headers(&self) -> Vec<(&'static str, &'static str)> {
        self.acl.map(|acl| vec![("x-amz-acl", acl.as_str())]).unwrap_or_default()
    }

    async fn info(&self, path: &str) -> Result<IndexMap<String, String>> {
        Ok(self.call(Method::Head, &read_uri(path), Hashed::empty(), &[], &[], &[], true, false).await?.headers)
    }

    async fn create_multipart_upload(&self, path: &str, content_type: &str) -> Result<String> {
        let response = self
            .call(
                Method::Post,
                &write_uri(path),
                Hashed::empty(),
                &[("uploads".into(), String::new())],
                &[("content-type", content_type)],
                &self.acl_headers(),
                true,
                false,
            )
            .await?;
        response
            .body
            .decoded()
            .and_then(|b| b.text("UploadId"))
            .map(str::to_owned)
            .ok_or_else(|| Error::remote("Missing upload ID in S3 response"))
    }

    async fn upload_part(
        &self,
        data: Hashed,
        path: &str,
        content_type: &str,
        chunk: i64,
        upload_id: &str,
    ) -> Result<String> {
        // The ACL header is not allowed on parts, only on the creation.
        let response = self
            .call(
                Method::Put,
                &write_uri(path),
                data,
                &[("partNumber".into(), chunk.to_string()), ("uploadId".into(), upload_id.to_owned())],
                &[("content-type", content_type)],
                &[],
                true,
                false,
            )
            .await?;
        response.headers.get("etag").cloned().ok_or_else(|| Error::remote("Missing ETag in S3 response"))
    }

    async fn complete_multipart_upload(&self, path: &str, upload_id: &str, parts: &IndexMap<i64, Part>) -> Result<()> {
        let mut sorted: Vec<(&i64, &Part)> = parts.iter().collect();
        sorted.sort_by_key(|(k, _)| **k);
        let mut body = String::from("<CompleteMultipartUpload>");
        for (key, part) in sorted {
            let Part::Etag(etag) = part else {
                return Err(Error::Upload(format!("Missing ETag for part {key}")));
            };
            body.push_str(&format!("<Part><ETag>{etag}</ETag><PartNumber>{key}</PartNumber></Part>"));
        }
        body.push_str("</CompleteMultipartUpload>");
        self.call(
            Method::Post,
            &write_uri(path),
            Hashed::from(Bytes::from(body)),
            &[("uploadId".into(), upload_id.to_owned())],
            &[("content-type", "application/xml")],
            &[],
            true,
            false,
        )
        .await?;
        Ok(())
    }

    async fn put(&self, path: &str, data: Body, content_type: &str, condition: Option<(&str, &str)>) -> Result<String> {
        let data = Hashed::body(data).await?;
        let mut headers = vec![("content-type", content_type)];
        headers.extend(condition);
        let response =
            self.call(Method::Put, &write_uri(path), data, &[], &headers, &self.acl_headers(), true, false).await?;
        let etag = response.headers.get("etag").ok_or_else(|| Error::remote("Missing ETag in S3 response"))?;
        Ok(unquote(etag).to_owned())
    }

    async fn list_objects(&self, prefix: &str, max: i64, token: &str) -> Result<Decoded> {
        if max > MAX_PAGE_SIZE {
            return Err(Error::InvalidArgument(format!("Cannot list more than {MAX_PAGE_SIZE} objects")));
        }
        let mut parameters = vec![
            ("list-type".to_owned(), "2".to_owned()),
            ("prefix".to_owned(), prefix.trim_start_matches('/').to_owned()),
            ("max-keys".to_owned(), max.to_string()),
        ];
        if !token.is_empty() && token != "0" {
            parameters.push(("continuation-token".to_owned(), token.to_owned()));
        }
        let response = self
            .call(Method::Get, "/", Hashed::empty(), &parameters, &[("content-type", "text/plain")], &[], true, false)
            .await?;
        match response.body {
            ResponseBody::Xml(d @ Decoded::Array(_)) => Ok(d),
            _ => Err(Error::remote("Unexpected S3 list response")),
        }
    }

    async fn copy_object(&self, source: &str, target: &str) -> Result<bool> {
        let info = self.info(source).await?;
        let size = info.get("content-length").map(|s| php_std::format::str_to_int(s.as_bytes())).unwrap_or(0);
        let bucket = self.bucket.as_deref().unwrap_or_default();
        let copy_source = format!("/{bucket}/{}", read_uri(source).trim_start_matches('/'));
        let uri = write_uri(target);
        if size <= MAX_COPY_OBJECT_SIZE as i64 {
            let mut amz = vec![("x-amz-copy-source", copy_source.as_str()), ("x-amz-metadata-directive", "COPY")];
            amz.extend(self.acl_headers());
            let response = self.call(Method::Put, &uri, Hashed::empty(), &[], &[], &amz, true, false).await?;
            // S3 reports CopyObject failures in a 200 body, so success is the ETag.
            if response.body.decoded().and_then(|b| b.get("ETag")).is_none() {
                return Err(Error::remote("Unexpected S3 copy response"));
            }
            return Ok(true);
        }
        let size = size as u64;
        let content_type = info.get("content-type").cloned().unwrap_or_default();
        let upload_id = self.create_multipart_upload(target, &content_type).await?;
        let result = async {
            let mut parts = IndexMap::new();
            let total = size.div_ceil(MAX_COPY_OBJECT_SIZE);
            for part in 1..=total {
                let start = (part - 1) * MAX_COPY_OBJECT_SIZE;
                let end = size.min(start + MAX_COPY_OBJECT_SIZE) - 1;
                let range = format!("bytes={start}-{end}");
                let response = self
                    .call(
                        Method::Put,
                        &uri,
                        Hashed::empty(),
                        &[("partNumber".into(), part.to_string()), ("uploadId".into(), upload_id.clone())],
                        &[],
                        &[("x-amz-copy-source", copy_source.as_str()), ("x-amz-copy-source-range", range.as_str())],
                        true,
                        false,
                    )
                    .await?;
                let etag = response
                    .body
                    .decoded()
                    .and_then(|b| b.text("ETag"))
                    .ok_or_else(|| Error::remote("Missing ETag in S3 response"))?;
                parts.insert(part as i64, Part::Etag(etag.to_owned()));
            }
            self.complete_multipart_upload(target, &upload_id, &parts).await
        }
        .await;
        if let Err(e) = result {
            // Best effort: unclaimed multipart parts are billed until aborted.
            let _ = self.abort(target, &upload_id).await;
            return Err(e);
        }
        Ok(true)
    }

    /// Sends a signed request and returns its response; an error status
    /// becomes the matching [`Error`]. A `streamed` response's body is left
    /// to read ([`ResponseBody::Stream`]), but for the head of an error.
    ///
    /// PHP: the protected `call()`, `$sink` standing for `streamed`.
    #[allow(clippy::too_many_arguments)]
    async fn call(
        &self,
        method: Method,
        uri: &str,
        data: Hashed,
        parameters: &[(String, String)],
        headers: &[(&str, &str)],
        amz_headers: &[(&str, &str)],
        decode: bool,
        streamed: bool,
    ) -> Result<S3Response> {
        let uri = format!("{}{}", self.path, device::absolute_path(uri));
        let query = build_query(parameters.iter().map(|(k, v)| (k.as_str(), v.as_str())));
        // An empty query string leaves no `?` behind, as PSR-7's Uri drops it.
        let mut url = format!("{}{uri}?{query}", self.fqdn);
        if url.split_once('?').is_some_and(|(_, q)| q.is_empty()) {
            url.pop();
        }
        let url = uri::Uri::parse(&url)?.to_string();
        let Hashed { payload, md5, sha256, length } = data;
        let now = chrono::Utc::now();

        let mut headers: Vec<(String, String)> =
            headers.iter().filter(|(_, v)| !v.is_empty()).map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect();
        set(&mut headers, "host", self.host.clone());
        set(&mut headers, "date", now.format("%a, %d %b %Y %H:%M:%S GMT").to_string());
        set(&mut headers, "content-md5", md5);
        set(&mut headers, "content-length", length.to_string());
        let mut amz: Vec<(String, String)> = amz_headers
            .iter()
            .filter(|(_, v)| !v.is_empty())
            .map(|(k, v)| ((*k).to_owned(), (*v).to_owned()))
            .collect();
        set(&mut amz, "x-amz-date", now.format("%Y%m%dT%H%M%SZ").to_string());
        set(&mut amz, "x-amz-content-sha256", sha256);

        let authorization = self.signature(method, &uri, parameters, &headers, &amz);
        let mut all = amz;
        for (k, v) in headers {
            set(&mut all, &k, v);
        }
        all.push(("authorization".into(), authorization));
        all.push(("user-agent".into(), "utopia-php/storage".into()));
        let request = Request { method, url, headers: all, body: payload };
        let transport = |e: TransportError| Error::Transport { message: e.to_string(), code: e.code() };

        if streamed {
            let response = self.client.stream(&request).await.map_err(transport)?;
            let response_headers = merge_headers(&response.headers);
            let mut body = response.body;
            if response.status >= 400 {
                // Only the head of a streamed error is kept to read the error from.
                let mut head = Vec::new();
                while head.len() < ERROR_BODY_BUFFER_SIZE {
                    let chunk = body.read(ERROR_BODY_BUFFER_SIZE - head.len()).await.map_err(Error::Body)?;
                    if chunk.is_empty() {
                        break;
                    }
                    head.extend_from_slice(&chunk);
                }
                return Err(s3_error(&head, response.status, &response_headers));
            }
            return Ok(S3Response {
                status: response.status,
                headers: response_headers,
                body: ResponseBody::Stream(body),
            });
        }

        let response = self.client.send(&request).await.map_err(transport)?;
        let response_headers = merge_headers(&response.headers);
        let body = response.body;
        if response.status >= 400 {
            return Err(s3_error(&body, response.status, &response_headers));
        }
        let content_type = response_headers.get("content-type").map(String::as_str).unwrap_or_default();
        let is_xml =
            content_type == "application/xml" || (body.starts_with(b"<?xml") && content_type != "image/svg+xml");
        let body = if decode && is_xml {
            let decoded = xml::parse(&body).map(|root| root.decode());
            match decoded {
                Some(d @ Decoded::Array(_)) => ResponseBody::Xml(d),
                _ => return Err(Error::remote("Failed to decode S3 XML response")),
            }
        } else {
            ResponseBody::Raw(body)
        };
        Ok(S3Response { status: response.status, headers: response_headers, body })
    }

    /// The `Authorization` header of a request: AWS Signature V4.
    fn signature(
        &self,
        method: Method,
        uri: &str,
        parameters: &[(String, String)],
        headers: &[(String, String)],
        amz: &[(String, String)],
    ) -> String {
        let amz_date = amz.iter().find(|(k, _)| k == "x-amz-date").map(|(_, v)| v.as_str()).unwrap_or_default();
        let date_stamp = amz_date.get(..8).unwrap_or(amz_date);
        let mut combined: Vec<(String, String)> = Vec::new();
        for (k, v) in headers.iter().chain(amz) {
            let value = String::from_utf8_lossy(php_std::string::trim(v.as_bytes(), b" \t\n\r\0\x0B")).into_owned();
            set(&mut combined, &k.to_ascii_lowercase(), value);
        }
        combined.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()));
        let mut sorted: Vec<(&str, &str)> = parameters.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
        sorted.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()));
        let query = build_query(sorted.into_iter());
        let signed = combined.iter().map(|(k, _)| k.as_str()).collect::<Vec<_>>().join(";");
        let mut canonical =
            vec![method.as_str().to_owned(), uri.split('?').next().unwrap_or_default().to_owned(), query];
        canonical.extend(combined.iter().map(|(k, v)| format!("{k}:{v}")));
        canonical.push(String::new());
        canonical.push(signed.clone());
        canonical
            .push(amz.iter().find(|(k, _)| k == "x-amz-content-sha256").map(|(_, v)| v.clone()).unwrap_or_default());
        let scope = format!("{date_stamp}/{}/s3/aws4_request", self.region);
        let to_sign = format!(
            "AWS4-HMAC-SHA256\n{amz_date}\n{scope}\n{}",
            hex::encode(Sha256::digest(canonical.join("\n").as_bytes()))
        );
        let key = hmac(format!("AWS4{}", self.secret_key).as_bytes(), date_stamp.as_bytes());
        let key = hmac(&key, self.region.as_bytes());
        let key = hmac(&key, b"s3");
        let key = hmac(&key, b"aws4_request");
        let signature = hex::encode(hmac(&key, to_sign.as_bytes()));
        format!("AWS4-HMAC-SHA256 Credential={}/{scope},SignedHeaders={signed},Signature={signature}", self.access_key)
    }
}

#[async_trait]
impl Device for S3 {
    fn kind(&self) -> DeviceType {
        self.kind
    }

    fn root(&self) -> &str {
        &self.root
    }

    fn path(&self, filename: &str) -> String {
        format!("{}/{filename}", self.root)
    }

    async fn prepare(&self, path: &str, content_type: &str, chunks: i64, metadata: &mut Metadata) -> Result<()> {
        metadata.parts_mut();
        metadata.chunks.get_or_insert(0);
        metadata.content_type.get_or_insert_with(|| content_type.to_owned());
        if chunks == 1 || metadata.active_upload_id().is_some() {
            return Ok(());
        }
        metadata.upload_id = Some(self.create_multipart_upload(path, content_type).await?);
        Ok(())
    }

    async fn upload_chunk(
        &self,
        data: Body,
        path: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64> {
        let content_type = metadata.content_type.clone().unwrap_or_default();
        if chunk == 1 && chunks == 1 {
            self.write(path, data, &content_type).await?;
            metadata.parts_mut().insert(chunk, Part::Received);
            metadata.chunks = Some(1);
            return Ok(1);
        }
        let Some(upload_id) = metadata.active_upload_id().map(str::to_owned) else {
            return Err(Error::Upload("Missing multipart upload ID".into()));
        };
        metadata.parts_mut();
        metadata.chunks.get_or_insert(0);
        let data = Hashed::body(data).await?;
        let etag = self.upload_part(data, path, &content_type, chunk, &upload_id).await?;
        // A chunk uploaded again is not counted twice.
        if !metadata.parts_mut().contains_key(&chunk) {
            metadata.chunks = Some(metadata.chunks.unwrap_or(0) + 1);
        }
        metadata.parts_mut().insert(chunk, Part::Etag(etag));
        Ok(metadata.chunks.unwrap_or(0))
    }

    async fn finalize(&self, path: &str, chunks: i64, metadata: &mut Metadata) -> Result<bool> {
        // No multipart upload to complete: a single chunk went up whole, or an
        // earlier call completed the upload. The object in place answers both.
        let Some(upload_id) = metadata.active_upload_id().map(str::to_owned) else {
            return self.exists(path).await;
        };
        let parts = metadata.parts_mut();
        for i in 1..=chunks {
            if !parts.contains_key(&i) {
                return Err(Error::Upload(format!("Missing chunk {i}")));
            }
        }
        let parts = parts.clone();
        match self.complete_multipart_upload(path, &upload_id, &parts).await {
            Ok(()) => Ok(true),
            // The upload is gone: completed by an earlier call (the object is
            // there) or aborted (it is not).
            Err(e) if e.is_not_found() => {
                if self.exists(path).await? {
                    Ok(true)
                } else {
                    Err(e)
                }
            }
            Err(e) => Err(e),
        }
    }

    async fn abort(&self, path: &str, upload_id: &str) -> Result<bool> {
        self.call(
            Method::Delete,
            &write_uri(path),
            Hashed::empty(),
            &[("uploadId".into(), upload_id.to_owned())],
            &[],
            &[],
            true,
            false,
        )
        .await?;
        Ok(true)
    }

    async fn read(&self, path: &str, offset: u64, length: Option<u64>, etag: Option<&str>) -> Result<Body> {
        if length == Some(0) {
            return Ok(Body::empty());
        }
        let mut headers: Vec<(&str, String)> = Vec::new();
        match length {
            Some(length) => headers.push(("range", format!("bytes={offset}-{}", offset as i128 + length as i128 - 1))),
            None if offset > 0 => headers.push(("range", format!("bytes={offset}-"))),
            None => {}
        }
        if let Some(etag) = etag {
            headers.push(("if-match", quote(etag)));
        }
        let headers: Vec<(&str, &str)> = headers.iter().map(|(k, v)| (*k, v.as_str())).collect();
        let response =
            self.call(Method::Get, &read_uri(path), Hashed::empty(), &[], &headers, &[], false, true).await?;
        match response.body {
            // Streamed as it arrives, and kept as it is read, so the body
            // rewinds like PHP's `php://temp` copy of the response; memory
            // holds 2 MiB of it at most, the rest goes to a temporary file.
            ResponseBody::Stream(body) => Ok(body.spooled()),
            ResponseBody::Raw(bytes) => Ok(Body::from(bytes)),
            ResponseBody::Xml(_) => Ok(Body::empty()),
        }
    }

    /// One GET pins the object for the whole transfer, even when its path
    /// is deleted or overwritten meanwhile. Its head is checked before any
    /// byte reaches the sink (an error status, or a range the service did
    /// not honour, delivers nothing); the body then goes to the sink as it
    /// arrives, and is never fetched again. A client without response
    /// streaming ([`Client::supports_response_streaming`]) reads the object
    /// first ([`device::stream`]).
    ///
    /// PHP: `stream()`.
    async fn stream(
        &self,
        path: &str,
        sink: &mut dyn Sink,
        offset: u64,
        length: Option<u64>,
        etag: Option<&str>,
    ) -> Result<()> {
        if !self.client.supports_response_streaming() {
            return device::stream(self, path, sink, offset, length, etag).await;
        }
        if length == Some(0) {
            return Ok(());
        }
        let mut headers: Vec<(&str, String)> = Vec::new();
        match length {
            Some(length) => headers.push(("range", format!("bytes={offset}-{}", offset as i128 + length as i128 - 1))),
            None if offset > 0 => headers.push(("range", format!("bytes={offset}-"))),
            None => {}
        }
        if let Some(etag) = etag {
            headers.push(("if-match", quote(etag)));
        }
        // The stored bytes, even when the service has a Content-Encoding for them.
        headers.push(("accept-encoding", "identity".into()));
        let headers: Vec<(&str, &str)> = headers.iter().map(|(k, v)| (*k, v.as_str())).collect();
        let response =
            self.call(Method::Get, &read_uri(path), Hashed::empty(), &[], &headers, &[], false, true).await?;
        let unexpected = || Error::remote("Backend returned an unexpected object response.");
        if !(200..300).contains(&response.status) {
            return Err(unexpected());
        }
        if length.is_some() || offset > 0 {
            let range = response.headers.get("content-range").map(String::as_str).unwrap_or_default();
            if !honours_range(response.status, range, offset, length) {
                return Err(Error::remote("Backend did not honor the requested byte range."));
            }
        } else if response.status != 200 {
            return Err(unexpected());
        }
        let mut body = match response.body {
            ResponseBody::Stream(body) => body,
            ResponseBody::Raw(bytes) => Body::from(bytes),
            ResponseBody::Xml(_) => Body::empty(),
        };
        loop {
            let chunk = body
                .read(client::CHUNK_SIZE)
                .await
                .map_err(|e| Error::Transport { message: e.to_string(), code: CURLE_PARTIAL_FILE })?;
            if chunk.is_empty() {
                return Ok(());
            }
            sink.write(chunk).await.map_err(Error::Body)?;
        }
    }

    async fn write(&self, path: &str, data: Body, content_type: &str) -> Result<String> {
        self.put(path, data, content_type, None).await
    }

    async fn create(&self, path: &str, data: Body, content_type: &str) -> Result<String> {
        self.put(path, data, content_type, Some(("if-none-match", "*"))).await
    }

    async fn replace(&self, path: &str, data: Body, etag: &str, content_type: &str) -> Result<String> {
        let quoted = quote(etag);
        self.put(path, data, content_type, Some(("if-match", quoted.as_str()))).await
    }

    async fn copy(&self, source: &str, target: &str, to: Option<&dyn Device>, chunk_size: i64) -> Result<bool> {
        let same_device = to.is_none_or(|to| device::same(to, self));
        if same_device && self.bucket.is_some() {
            return self.copy_object(source, target).await;
        }
        device::copy(self, source, target, to, chunk_size).await
    }

    async fn move_file(&self, source: &str, target: &str) -> Result<bool> {
        device::move_file(self, source, target).await
    }

    async fn delete(&self, path: &str, _recursive: bool) -> Result<bool> {
        self.call(Method::Delete, &read_uri(path), Hashed::empty(), &[], &[], &[], true, false).await?;
        Ok(true)
    }

    async fn delete_path(&self, path: &str) -> Result<bool> {
        let path = format!("{}/{path}", self.root);
        let mut token = String::new();
        loop {
            let objects = self.list_objects(&path, MAX_PAGE_SIZE, &token).await?;
            token = objects.text("NextContinuationToken").unwrap_or_default().to_owned();
            let keys: Vec<&str> = objects.entries("Contents").into_iter().filter_map(|o| xml::text(o, "Key")).collect();
            if keys.is_empty() {
                break;
            }
            let mut body = String::from("<Delete xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">");
            for key in keys {
                body.push_str(&format!("<Object><Key>{key}</Key></Object>"));
            }
            body.push_str("<Quiet>true</Quiet></Delete>");
            self.call(
                Method::Post,
                "/",
                Hashed::from(Bytes::from(body)),
                &[("delete".into(), String::new())],
                &[("content-type", "application/xml")],
                &[],
                true,
                false,
            )
            .await?;
            if token.is_empty() {
                break;
            }
        }
        Ok(true)
    }

    async fn exists(&self, path: &str) -> Result<bool> {
        match self.info(path).await {
            Ok(_) => Ok(true),
            Err(e) if e.is_not_found() => Ok(false),
            Err(e) => Err(e),
        }
    }

    async fn list_files(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<FileList> {
        let data = self.list_objects(prefix, max, cursor.unwrap_or_default()).await?;
        let mut files = Vec::new();
        for object in data.entries("Contents") {
            let Some(key) = xml::text(object, "Key") else { continue };
            let size = xml::text(object, "Size")
                .filter(|s| php_std::value::numeric_str(s).is_some())
                .map(|s| php_std::format::str_to_int(s.as_bytes()).max(0) as u64)
                .unwrap_or(0);
            files.push(FileInfo {
                path: key.to_owned(),
                size,
                modified_at: xml::text(object, "LastModified").and_then(date),
                etag: xml::text(object, "ETag").map(|e| unquote(e).to_owned()),
            });
        }
        let cursor = match (data.text("IsTruncated"), data.text("NextContinuationToken")) {
            (Some("true"), Some(token)) => Some(token.to_owned()),
            _ => None,
        };
        Ok(FileList { files, cursor })
    }

    async fn file_info(&self, path: &str) -> Result<FileInfo> {
        let headers = self.info(path).await?;
        let modified = headers.get("last-modified").and_then(|m| {
            php_std::datetime::strtotime(m.as_bytes(), php_std::datetime::time(), &php_std::datetime::TzInfo::utc())
        });
        Ok(FileInfo {
            path: path.to_owned(),
            size: content_length(&headers),
            modified_at: modified.and_then(|t| DateTime::from_timestamp(t, 0)).map(|d| d.fixed_offset()),
            etag: headers.get("etag").map(|e| unquote(e).to_owned()),
        })
    }

    async fn file_size(&self, path: &str) -> Result<u64> {
        Ok(content_length(&self.info(path).await?))
    }

    async fn file_mime_type(&self, path: &str) -> Result<String> {
        Ok(self.info(path).await?.get("content-type").cloned().unwrap_or_default())
    }

    async fn file_hash(&self, path: &str) -> Result<String> {
        Ok(unquote(self.info(path).await?.get("etag").map(String::as_str).unwrap_or_default()).to_owned())
    }

    fn as_any(&self) -> &dyn Any {
        self
    }
}

/// `(int) ($headers['content-length'] ?? 0)`.
fn content_length(headers: &IndexMap<String, String>) -> u64 {
    headers.get("content-length").map(|s| php_std::format::str_to_int(s.as_bytes()) as u64).unwrap_or(0)
}

/// Sets a header, replacing an earlier value of the same name in place.
fn set(headers: &mut Vec<(String, String)>, name: &str, value: String) {
    match headers.iter_mut().find(|(k, _)| k == name) {
        Some(entry) => entry.1 = value,
        None => headers.push((name.to_owned(), value)),
    }
}

fn hmac(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = <Hmac<Sha256> as Mac>::new_from_slice(key).expect("HMAC takes keys of any length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

/// `http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)` of flat
/// string values.
fn build_query<'a>(parameters: impl Iterator<Item = (&'a str, &'a str)>) -> String {
    parameters
        .map(|(k, v)| {
            format!(
                "{}={}",
                php_std::encoding::rawurlencode(k.as_bytes()),
                php_std::encoding::rawurlencode(v.as_bytes())
            )
        })
        .collect::<Vec<_>>()
        .join("&")
}

/// The request path of an object read, deleted or looked at: the path
/// URL-encoded, slashes kept.
/// Whether a response answers the byte range a stream asked for: a 206
/// whose `Content-Range` (`bytes <start>-<end>/<total>`) starts at
/// `offset` and ends where the range, cut at the end of the object, does.
///
/// PHP: the `Content-Range` check of `S3::stream()`, its digits read by
/// `(int)`, which saturates.
fn honours_range(status: u16, content_range: &str, offset: u64, length: Option<u64>) -> bool {
    let (unit, extent) = content_range.split_once(' ').unwrap_or((content_range, ""));
    let (span, total) = extent.split_once('/').unwrap_or((extent, ""));
    let (start, end) = span.split_once('-').unwrap_or((span, ""));
    let digits = |s: &str| !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit());
    if status != 206 || unit != "bytes" || !digits(start) || !digits(end) || !digits(total) {
        return false;
    }
    let int = |s: &str| i128::from(s.parse::<i64>().unwrap_or(i64::MAX));
    let (start, end, total, offset) = (int(start), int(end), int(total), i128::from(offset));
    let last = match length {
        None => total - 1,
        Some(length) => (offset + i128::from(length) - 1).min(total - 1),
    };
    start == offset && end >= offset && total > end && end == last
}

fn read_uri(path: &str) -> String {
    if path.is_empty() {
        return "/".into();
    }
    format!("/{}", php_std::encoding::rawurlencode(path.as_bytes()).replace("%2F", "/"))
}

/// The request path of an object written: as [`read_uri`], with `?` kept as
/// well (a PHP quirk: it starts the query string).
fn write_uri(path: &str) -> String {
    if path.is_empty() {
        return "/".into();
    }
    format!("/{}", php_std::encoding::rawurlencode(path.as_bytes()).replace("%2F", "/").replace("%3F", "?"))
}

/// An ETag in the double quotes S3 puts around it.
fn quote(etag: &str) -> String {
    if etag.starts_with('"') { etag.to_owned() } else { format!("\"{etag}\"") }
}

/// An ETag without its double quotes.
fn unquote(etag: &str) -> &str {
    etag.trim_matches('"')
}

/// `new \DateTimeImmutable($text)` (UTC when the text names no zone).
fn date(text: &str) -> Option<DateTime<FixedOffset>> {
    let d = php_std::datetime::DateTime::new(text.as_bytes(), None, &php_std::datetime::TzInfo::utc()).ok()?;
    let offset = FixedOffset::east_opt(d.offset() as i32)?;
    DateTime::from_timestamp(d.timestamp(), (d.microsecond() * 1000) as u32).map(|t| t.with_timezone(&offset))
}

/// Response headers by lowercase name, repeated ones joined by `, `.
fn merge_headers(headers: &[(String, String)]) -> IndexMap<String, String> {
    let mut merged: IndexMap<String, String> = IndexMap::new();
    for (name, value) in headers {
        let name = name.to_ascii_lowercase();
        match merged.get_mut(&name) {
            Some(existing) => {
                existing.push_str(", ");
                existing.push_str(value);
            }
            None => {
                merged.insert(name, value.clone());
            }
        }
    }
    merged
}

/// A request payload with the digests its signature covers: the base64
/// MD5 (`Content-MD5`), the hex SHA-256 (`x-amz-content-sha256`, signed:
/// PHP signs the payload's hash, never `UNSIGNED-PAYLOAD`) and the length
/// (`Content-Length`).
struct Hashed {
    payload: Payload,
    md5: String,
    sha256: String,
    length: u64,
}

impl Hashed {
    /// No payload.
    fn empty() -> Self {
        Hashed::from(Bytes::new())
    }

    /// Hashes a body without holding it in memory. The digests are signed,
    /// so they must be known before the first byte goes out: a first pass
    /// reads the body chunk by chunk to hash it, then the body is sent as a
    /// [`Payload`] that rewinds it and streams it again (on every retry
    /// too), so at most a chunk or two is in memory either way. This is why
    /// S3 needs a seekable body. A body that fits in one chunk is kept
    /// and sent from memory, without a second read.
    ///
    /// PHP: `hashBody()`.
    async fn body(mut data: Body) -> Result<Self> {
        if !data.is_seekable() {
            return Err(Error::InvalidArgument("S3 requires a seekable stream so the payload can be signed".into()));
        }
        data.rewind().await.map_err(Error::Body)?;
        let (mut md5, mut sha256) = (Md5::new(), Sha256::new());
        let mut length = 0u64;
        let mut first = None;
        loop {
            let chunk = data.read(client::CHUNK_SIZE).await.map_err(Error::Body)?;
            if chunk.is_empty() {
                break;
            }
            md5.update(&chunk);
            sha256.update(&chunk);
            length += chunk.len() as u64;
            first = (length == chunk.len() as u64).then_some(chunk);
        }
        let payload = match first {
            Some(chunk) => Payload::from(chunk),
            None if length == 0 => Payload::default(),
            None => {
                data.rewind().await.map_err(Error::Body)?;
                Payload::from_body(data)
            }
        };
        Ok(Hashed {
            payload,
            md5: base64::engine::general_purpose::STANDARD.encode(md5.finalize()),
            sha256: hex::encode(sha256.finalize()),
            length,
        })
    }
}

impl From<Bytes> for Hashed {
    fn from(data: Bytes) -> Self {
        Hashed {
            md5: base64::engine::general_purpose::STANDARD.encode(Md5::digest(&data)),
            sha256: hex::encode(Sha256::digest(&data)),
            length: data.len() as u64,
            payload: Payload::from(data),
        }
    }
}

/// `json_encode(['key' => $key, 'upload' => $upload])`.
fn encode_cursor(key: &str, upload: &str) -> String {
    use php_std::{Array, ArrayKey, Value};
    let array: Array = Array::from_entries(
        [(ArrayKey::from("key"), Value::Str(key.into())), (ArrayKey::from("upload"), Value::Str(upload.into()))],
        None,
    );
    php_std::json::encode(&Value::Array(array), php_std::json::Flags::NONE, 512).unwrap_or_default()
}

/// The markers in a listing cursor, as `json_decode($cursor, true)` reads them.
fn decode_cursor(cursor: &str) -> Option<(String, String)> {
    use php_std::Value;
    let Ok(Value::Array(array)) = php_std::json::decode(cursor.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
    else {
        return None;
    };
    let string = |name: &str| match array.get_str(name) {
        Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    };
    Some((string("key")?, string("upload")?))
}

/// The error an S3 error response stands for.
fn s3_error(body: &[u8], status: u16, headers: &IndexMap<String, String>) -> Error {
    let mut code = None;
    let mut message = None;
    let trimmed = php_std::string::ltrim(body, b" \t\n\r\0\x0B");
    if (trimmed.starts_with(b"<?xml") || trimmed.starts_with(b"<Error"))
        && let Some(root) = xml::parse(body)
    {
        // `?: null`: "" and "0" are falsy.
        code = root.child_text("Code").filter(|c| !c.is_empty() && c != "0");
        message = root.child_text("Message").filter(|m| !m.is_empty() && m != "0");
    }
    let ids: Vec<String> = [("request-id", "x-amz-request-id"), ("id-2", "x-amz-id-2")]
        .into_iter()
        .filter_map(|(label, header)| {
            let value = headers.get(header).map(String::as_str).unwrap_or_default();
            // array_filter() drops "" and "0".
            (!value.is_empty() && value != "0").then(|| format!("{label}: {value}"))
        })
        .collect();
    let suffix = if ids.is_empty() { String::new() } else { format!(" [{}]", ids.join(", ")) };
    let status = i64::from(status);
    // HEAD error responses carry no body, so the status is the only signal.
    if status == 404 || code.as_deref() == Some("NoSuchKey") {
        return Error::NotFound {
            message: format!("{}{suffix}", message.as_deref().unwrap_or("File not found")),
            code: status,
        };
    }
    if status == 412 || code.as_deref() == Some("PreconditionFailed") {
        return Error::PreconditionFailed {
            message: format!("{}{suffix}", message.as_deref().unwrap_or("Precondition failed")),
            code: status,
        };
    }
    let message = message.unwrap_or_else(|| {
        if body.is_empty() { "S3 request failed".to_owned() } else { String::from_utf8_lossy(body).into_owned() }
    });
    Error::Remote { message: format!("{message}{suffix}"), code: status, error_code: code }
}
