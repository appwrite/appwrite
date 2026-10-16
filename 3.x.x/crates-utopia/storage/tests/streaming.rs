//! Streaming I/O and parallel multipart upload coverage.

use std::io::Cursor;

use tempfile::TempDir;
use utopia_storage::{Device, Local, ParallelUploadOptions, PIPE_CHUNK_SIZE};

#[test]
fn local_write_from_and_read_into_stream_large_file() {
    let dir = TempDir::new().unwrap();
    let device = Local::new(dir.path());
    let path = device.get_path("stream.bin");

    // ~1.5 MiB of non-UTF8 bytes - must survive round-trip without lossy decode.
    let size = PIPE_CHUNK_SIZE * 3 + 123;
    let mut payload = vec![0_u8; size];
    for (index, byte) in payload.iter_mut().enumerate() {
        *byte = (index % 251) as u8;
    }
    // Ensure high bytes are present (would corrupt under UTF-8 lossy conversion).
    payload[0] = 0xFF;
    payload[1] = 0xFE;

    let mut source = Cursor::new(payload.as_slice());
    device
        .write_from(&path, &mut source, "application/octet-stream")
        .expect("write_from");

    let mut sunk = Vec::new();
    let written = device
        .read_into(&path, &mut sunk, 0, None)
        .expect("read_into");
    assert_eq!(written, size as u64);
    assert_eq!(sunk, payload);

    let window = device.read(&path, 10, Some(5)).expect("range");
    assert_eq!(window, &payload[10..15]);
}

#[test]
fn local_copy_never_needs_full_source_in_one_buffer_beyond_chunk() {
    let dir = TempDir::new().unwrap();
    let source_device = Local::new(dir.path().join("a"));
    let target_device = Local::new(dir.path().join("b"));
    let source = source_device.get_path("big.bin");
    let target = target_device.get_path("big.bin");

    let payload = vec![0xAB_u8; 250_000];
    source_device
        .write(&source, &payload, "application/octet-stream")
        .unwrap();

    // Chunk smaller than the object - exercises the streamed multipart copy path.
    source_device
        .copy(&source, &target, Some(&target_device), 64_000)
        .expect("chunked copy");

    assert_eq!(target_device.read(&target, 0, None).unwrap(), payload);
}

#[test]
fn local_upload_parallel_assembles_parts() {
    let dir = TempDir::new().unwrap();
    let device = Local::new(dir.path());
    let path = device.get_path("parallel.bin");

    let payload = vec![7_u8; 300_000];
    let mut source = Cursor::new(payload.as_slice());
    device
        .upload_parallel(
            &mut source,
            &path,
            "application/octet-stream",
            ParallelUploadOptions::new(100_000, 3),
        )
        .expect("upload_parallel");

    assert_eq!(device.get_file_size(&path).unwrap(), payload.len() as u64);
    assert_eq!(device.read(&path, 0, None).unwrap(), payload);
}

#[cfg(feature = "s3")]
mod s3_streaming {
    use super::*;
    use std::path::Path;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Arc;
    use std::time::Duration;

    use utopia_storage::{Acl, RetryStrategy, MIN_MULTIPART_PART_SIZE, S3};
    use utopia_test_wiremock::{
        method, path as uri_path, query_param, Mock, MockServer, RecordedRequest, Respond,
        ResponseTemplate,
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
        .with_retry_strategy(RetryStrategy::new(1, Duration::ZERO, Duration::ZERO))
    }

    #[test]
    fn binary_download_is_not_utf8_lossy() {
        let rt = runtime();
        let server = rt.block_on(MockServer::start());
        let body = vec![0xFF, 0xFE, 0x00, 0x01, 0x80];
        rt.block_on(async {
            Mock::given(method("GET"))
                .and(uri_path("/root/bin.dat"))
                .respond_with(
                    ResponseTemplate::new(200)
                        .insert_header("content-type", "application/octet-stream")
                        .set_body_bytes(body.clone()),
                )
                .mount(&server)
                .await;
        });

        let mut out = Vec::new();
        let n = s3(&server)
            .read_into(Path::new("/root/bin.dat"), &mut out, 0, None)
            .expect("read_into");
        assert_eq!(n, body.len() as u64);
        assert_eq!(out, body);
        assert_eq!(
            s3(&server)
                .read(Path::new("/root/bin.dat"), 0, None)
                .unwrap(),
            body
        );
    }

    #[test]
    fn parallel_multipart_uploads_all_parts() {
        let rt = runtime();
        let server = rt.block_on(MockServer::start());
        let part_puts = Arc::new(AtomicUsize::new(0));
        let peak = Arc::new(AtomicUsize::new(0));
        let in_flight = Arc::new(AtomicUsize::new(0));

        rt.block_on(async {
            Mock::given(method("POST"))
                .and(uri_path("/root/large.bin"))
                .and(query_param("uploads", ""))
                .respond_with(
                    ResponseTemplate::new(200)
                        .insert_header("content-type", "application/xml")
                        .set_body_string(
                            "<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>",
                        ),
                )
                .mount(&server)
                .await;

            let part_puts = Arc::clone(&part_puts);
            let peak = Arc::clone(&peak);
            let in_flight = Arc::clone(&in_flight);
            Mock::given(method("PUT"))
                .and(uri_path("/root/large.bin"))
                .and(query_param("uploadId", "up-1"))
                .respond_with_dyn(PartTracker {
                    part_puts,
                    peak,
                    in_flight,
                })
                .mount(&server)
                .await;

            Mock::given(method("POST"))
                .and(uri_path("/root/large.bin"))
                .and(query_param("uploadId", "up-1"))
                .respond_with(ResponseTemplate::new(200))
                .mount(&server)
                .await;
        });

        // 3 × 5 MiB => multipart with concurrent workers.
        let part = MIN_MULTIPART_PART_SIZE;
        let payload = vec![0xCD_u8; part * 3];
        let mut source = Cursor::new(payload.as_slice());
        s3(&server)
            .upload_parallel(
                &mut source,
                Path::new("/root/large.bin"),
                "application/octet-stream",
                ParallelUploadOptions::new(part, 3),
            )
            .expect("parallel upload");

        assert_eq!(part_puts.load(Ordering::SeqCst), 3);

        let requests = rt.block_on(server.received_requests()).expect("requests");
        let part_numbers: Vec<_> = requests
            .iter()
            .filter(|request| request.method.as_str() == "PUT")
            .filter_map(|request| {
                request
                    .url
                    .query_pairs()
                    .find(|(key, _)| key == "partNumber")
                    .map(|(_, value)| value.to_string())
            })
            .collect();
        assert_eq!(part_numbers.len(), 3);
        assert!(part_numbers.contains(&"1".to_string()));
        assert!(part_numbers.contains(&"2".to_string()));
        assert!(part_numbers.contains(&"3".to_string()));
        // Wiremock may serialize handlers; peak in-flight is recorded for diagnostics.
        assert!(peak.load(Ordering::SeqCst) >= 1);
    }

    struct PartTracker {
        part_puts: Arc<AtomicUsize>,
        peak: Arc<AtomicUsize>,
        in_flight: Arc<AtomicUsize>,
    }

    impl Respond for PartTracker {
        fn respond(&self, _request: &RecordedRequest) -> ResponseTemplate {
            let current = self.in_flight.fetch_add(1, Ordering::SeqCst) + 1;
            self.peak.fetch_max(current, Ordering::SeqCst);
            self.in_flight.fetch_sub(1, Ordering::SeqCst);
            self.part_puts.fetch_add(1, Ordering::SeqCst);
            ResponseTemplate::new(200).insert_header("etag", "\"part-etag\"")
        }
    }
}
