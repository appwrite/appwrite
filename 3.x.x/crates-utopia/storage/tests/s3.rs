#![cfg(feature = "s3")]

use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use utopia_storage::{
    Acl, AwsS3, Backblaze, Device, DeviceType, DoSpaces, Linode, PartValue, RetryStrategy,
    StorageError, Wasabi, S3,
};
use utopia_test_wiremock::{
    method, path, query_param, Mock, MockServer, RecordedRequest, Respond, ResponseTemplate,
};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn s3(server: &MockServer) -> S3 {
    S3::new(
        "/root",
        "test-key",
        "test-secret",
        server.uri(),
        "us-east-1",
        Acl::Private,
    )
    .expect("s3")
    .with_retry_strategy(RetryStrategy::new(3, Duration::ZERO, Duration::ZERO))
}

#[test]
fn signed_put_sends_sigv4_headers() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("PUT"))
            .and(path("/root/file.txt"))
            .respond_with(ResponseTemplate::new(200))
            .mount(&server)
            .await;
    });

    s3(&server)
        .write(Path::new("/root/file.txt"), b"Hello World", "text/plain")
        .expect("write");

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.method.as_str(), "PUT");
    assert_eq!(request.url.path(), "/root/file.txt");
    assert_eq!(request.body, b"Hello World");
    assert_eq!(header(request, "content-type"), "text/plain");
    assert_eq!(header(request, "x-amz-acl"), "private");
    assert_eq!(
        header(request, "x-amz-content-sha256"),
        "a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e"
    );
    assert_eq!(header(request, "content-md5"), "sQqNsWTgdUEFt6mb5y4/5Q==");
    assert!(header(request, "authorization").starts_with("AWS4-HMAC-SHA256 Credential=test-key/"));
    assert_eq!(header(request, "user-agent"), "utopia-php/storage");
}

#[test]
fn multipart_upload_prepares_parts_and_completes() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/root/file.txt"))
            .and(query_param("uploads", ""))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/xml")
                    .set_body_string("<InitiateMultipartUploadResult><UploadId>upload-123</UploadId></InitiateMultipartUploadResult>"),
            )
            .mount(&server)
            .await;
        Mock::given(method("PUT"))
            .and(path("/root/file.txt"))
            .and(query_param("uploadId", "upload-123"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-encoding", "identity")
                    .insert_header("etag", "etag-part"),
            )
            .mount(&server)
            .await;
        Mock::given(method("HEAD"))
            .and(path("/root/file.txt"))
            .respond_with(ResponseTemplate::new(404))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .and(path("/root/file.txt"))
            .and(query_param("uploadId", "upload-123"))
            .respond_with(ResponseTemplate::new(200))
            .mount(&server)
            .await;
    });

    let mut metadata = utopia_storage::UploadMetadata::default();
    let device = s3(&server);
    assert_eq!(
        device
            .upload(
                b"aaa",
                Path::new("/root/file.txt"),
                "text/plain",
                1,
                2,
                &mut metadata,
            )
            .expect("part 1"),
        1
    );
    assert_eq!(metadata.upload_id.as_deref(), Some("upload-123"));
    assert!(matches!(
        metadata.parts.get(&1),
        Some(PartValue::Etag(value)) if value == "etag-part"
    ));

    assert_eq!(
        device
            .upload(
                b"bbb",
                Path::new("/root/file.txt"),
                "text/plain",
                2,
                2,
                &mut metadata,
            )
            .expect("part 2"),
        2
    );

    let requests = rt.block_on(server.received_requests()).expect("requests");
    let complete = requests
        .iter()
        .find(|request| {
            request.method.as_str() == "POST" && request.url.query() == Some("uploadId=upload-123")
        })
        .expect("complete request");
    let body = std::str::from_utf8(&complete.body).expect("xml body");
    assert!(body.contains("<CompleteMultipartUpload>"));
    assert!(body.contains("<PartNumber>1</PartNumber>"));
    assert!(body.contains("<PartNumber>2</PartNumber>"));
}

#[test]
fn slowdown_response_is_retried() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("PUT"))
            .and(path("/root/file.txt"))
            .respond_with_dyn(SlowDownThenOk {
                attempts: AtomicUsize::new(0),
            })
            .mount(&server)
            .await;
    });

    s3(&server)
        .write(Path::new("/root/file.txt"), b"Hello World", "text/plain")
        .expect("write after retry");

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 2);
}

#[test]
fn not_found_response_maps_to_storage_not_found() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("GET"))
            .and(path("/root/missing.txt"))
            .respond_with(
                ResponseTemplate::new(404)
                    .insert_header("content-type", "application/xml")
                    .set_body_string("<?xml version=\"1.0\"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>"),
            )
            .mount(&server)
            .await;
    });

    let error = s3(&server)
        .read(Path::new("/root/missing.txt"), 0, None)
        .unwrap_err();
    assert!(matches!(error, StorageError::NotFound(_)));
}

#[test]
fn provider_constructors_set_type_and_host() {
    let aws = AwsS3::new(
        "/root",
        "key",
        "secret",
        "bucket",
        AwsS3::US_EAST_1,
        Acl::Private,
    )
    .expect("aws");
    assert_eq!(aws.get_type(), DeviceType::AwsS3);
    assert_eq!(aws.inner().host(), "bucket.s3.us-east-1.amazonaws.com");

    let spaces = DoSpaces::new(
        "/root",
        "key",
        "secret",
        "bucket",
        DoSpaces::NYC3,
        Acl::Private,
    )
    .expect("spaces");
    assert_eq!(spaces.get_type(), DeviceType::DoSpaces);
    assert_eq!(spaces.inner().host(), "bucket.nyc3.digitaloceanspaces.com");

    let linode = Linode::new(
        "/root",
        "key",
        "secret",
        "bucket",
        Linode::EU_CENTRAL_1,
        Acl::Private,
    )
    .expect("linode");
    assert_eq!(linode.get_type(), DeviceType::Linode);
    assert_eq!(
        linode.inner().host(),
        "bucket.eu-central-1.linodeobjects.com"
    );

    let backblaze = Backblaze::new(
        "/root",
        "key",
        "secret",
        "bucket",
        Backblaze::US_WEST_004,
        Acl::Private,
    )
    .expect("backblaze");
    assert_eq!(backblaze.get_type(), DeviceType::Backblaze);
    assert_eq!(
        backblaze.inner().host(),
        "bucket.s3.us-west-004.backblazeb2.com"
    );

    let wasabi = Wasabi::new(
        "/root",
        "key",
        "secret",
        "bucket",
        Wasabi::EU_CENTRAL_1,
        Acl::Private,
    )
    .expect("wasabi");
    assert_eq!(wasabi.get_type(), DeviceType::Wasabi);
    assert_eq!(
        wasabi.inner().host(),
        "bucket.s3.eu-central-1.wasabisys.com"
    );
}

struct SlowDownThenOk {
    attempts: AtomicUsize,
}

impl Respond for SlowDownThenOk {
    fn respond(&self, _request: &RecordedRequest) -> ResponseTemplate {
        if self.attempts.fetch_add(1, Ordering::SeqCst) == 0 {
            ResponseTemplate::new(503)
                .insert_header("content-type", "application/xml")
                .set_body_string("<?xml version=\"1.0\"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>")
        } else {
            ResponseTemplate::new(200)
        }
    }
}

fn header(request: &RecordedRequest, name: &str) -> String {
    request
        .headers
        .get(name)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("")
        .to_string()
}
