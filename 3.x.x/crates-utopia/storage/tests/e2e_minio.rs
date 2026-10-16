//! `MinIO` end-to-end tests for the S3 adapter family.
//!
//! Mirrors `utopia-php/storage` `tests/E2E/S3Test.php` + `S3Base.php`.
//!
//! Run with:
//! ```bash
//! ./crates-utopia/storage/e2e/minio.sh
//! # or: docker compose -f docker-compose.test.yml up -d --wait minio
//! cargo test -p utopia-storage --features s3 --test e2e_minio
//! ```

#![cfg(feature = "s3")]

use std::fmt::Write as _;
use std::path::{Path, PathBuf};
use std::sync::Mutex;

use tempfile::TempDir;
use utopia_storage::{
    Acl, Device, DeviceType, Local, ParallelUploadOptions, StorageError, UploadMetadata,
    MIN_MULTIPART_PART_SIZE, S3,
};

fn env_or(key: &str, default: &str) -> String {
    std::env::var(key).unwrap_or_else(|_| default.to_string())
}

/// Path-style endpoint: `http://127.0.0.1:9805/bucket`
fn path_style_host(bucket: &str) -> String {
    let base = env_or(
        "S3_PATH_HOST",
        &format!("http://127.0.0.1:{}", env_or("S3_PORT", "9805")),
    );
    format!("{}/{}/", base.trim_end_matches('/'), bucket)
}

fn virtual_host(bucket: &str) -> String {
    env_or(
        "S3_VIRTUAL_HOST",
        &format!("http://{}.localhost:{}", bucket, env_or("S3_PORT", "9805")),
    )
}

fn make_s3(host: &str, bucket: &str) -> S3 {
    S3::with_bucket(
        "/root",
        env_or("S3_ACCESS_KEY", "minioadmin"),
        env_or("S3_SECRET", "minioadmin"),
        host,
        env_or("S3_REGION", "us-east-1"),
        Acl::Private,
        bucket,
    )
    .expect("S3 device")
}

fn device() -> S3 {
    let bucket = env_or("S3_BUCKET", "utopia-storage-test");
    let host = std::env::var("S3_HOST").unwrap_or_else(|_| path_style_host(&bucket));
    make_s3(&host, &bucket)
}

fn unique_prefix() -> String {
    format!("e2e/{}", hex_encode(&rand_bytes(8)))
}

fn hex_encode(bytes: &[u8]) -> String {
    let mut out = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        let _ = write!(out, "{byte:02x}");
    }
    out
}

fn rand_bytes(len: usize) -> Vec<u8> {
    use std::time::{SystemTime, UNIX_EPOCH};
    let mut seed = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_nanos() as u64;
    let mut out = vec![0_u8; len];
    for byte in &mut out {
        seed = seed.wrapping_mul(6_364_136_223_846_793_005).wrapping_add(1);
        *byte = (seed >> 33) as u8;
    }
    out
}

/// Serialize live `MinIO` tests - shared bucket, avoid key collisions / races.
static MINIO_LOCK: Mutex<()> = Mutex::new(());

macro_rules! e2e {
    ($name:ident $body:block) => {
        #[test]
        fn $name() {
            let _guard = MINIO_LOCK
                .lock()
                .unwrap_or_else(std::sync::PoisonError::into_inner);
            $body
        }
    };
}

e2e!(type_and_root {
    let s3 = device();
    assert_eq!(s3.get_type(), DeviceType::S3);
    assert_eq!(s3.get_root(), Path::new("/root"));
    assert_eq!(s3.get_path("image.png"), PathBuf::from("/root/image.png"));
});

e2e!(write_read_delete {
    let s3 = device();
    let path = s3.get_path(&format!("{}/text.txt", unique_prefix()));
    s3.write(&path, b"Hello World", "text/plain").expect("write");
    assert!(s3.exists(&path));
    assert_eq!(s3.read(&path, 0, None).expect("read"), b"Hello World");
    assert!(s3.delete(&path, false).expect("delete"));
    assert!(!s3.exists(&path));
});

e2e!(read_missing_is_not_found {
    let s3 = device();
    let path = s3.get_path(&format!("{}/missing.txt", unique_prefix()));
    let err = s3.read(&path, 0, None).unwrap_err();
    assert!(matches!(err, StorageError::NotFound(_)));
});

e2e!(move_renames_object {
    let s3 = device();
    let prefix = unique_prefix();
    let src = s3.get_path(&format!("{prefix}/move-src.txt"));
    let dst = s3.get_path(&format!("{prefix}/move-dst.txt"));
    s3.write(&src, b"Hello World", "text/plain").unwrap();
    assert!(s3.r#move(&src, &dst).unwrap());
    assert!(!s3.exists(&src));
    assert_eq!(s3.read(&dst, 0, None).unwrap(), b"Hello World");
    assert!(!s3.r#move(&dst, &dst).unwrap());
    s3.delete(&dst, false).unwrap();
});

e2e!(copy_same_device {
    let s3 = device();
    let prefix = unique_prefix();
    let src = s3.get_path(&format!("{prefix}/copy-src.bin"));
    let dst = s3.get_path(&format!("{prefix}/copy-dst.bin"));
    let payload = rand_bytes(4096);
    s3.write(&src, &payload, "application/octet-stream").unwrap();
    s3.copy(&src, &dst, None, utopia_storage::COPY_CHUNK_SIZE)
        .unwrap();
    assert_eq!(s3.get_file_hash(&src).unwrap(), s3.get_file_hash(&dst).unwrap());
    assert_eq!(s3.get_file_size(&src).unwrap(), s3.get_file_size(&dst).unwrap());
    s3.delete(&src, false).unwrap();
    s3.delete(&dst, false).unwrap();
});

e2e!(list_files_and_pagination {
    let s3 = device();
    let prefix = unique_prefix();
    let base = s3.get_path(&format!("{prefix}/listing"));
    for name in ["a.txt", "b.txt", "c.txt", "d.txt"] {
        let path = base.join(name);
        s3.write(&path, name.as_bytes(), "text/plain").unwrap();
    }

    let list = s3.list_files(&base, 1000, None).unwrap();
    assert_eq!(list.files.len(), 4);
    assert!(list.cursor.is_none());
    assert!(list.files[0].size > 0);
    assert!(list.files[0].etag.is_some());
    assert!(list.files[0].modified_at.is_some());

    let page1 = s3.list_files(&base, 3, None).unwrap();
    assert_eq!(page1.files.len(), 3);
    assert!(page1.cursor.is_some());
    let page2 = s3
        .list_files(&base, 1000, page1.cursor.as_deref())
        .unwrap();
    assert_eq!(page2.files.len(), 1);
    assert!(page2.cursor.is_none());

    s3.delete_path(&format!("{prefix}/listing")).unwrap();
});

e2e!(delete_path_removes_prefix {
    let s3 = device();
    let prefix = unique_prefix();
    let p1 = s3.get_path(&format!("{prefix}/bucket/one.txt"));
    let p2 = s3.get_path(&format!("{prefix}/bucket/two.txt"));
    s3.write(&p1, b"one", "text/plain").unwrap();
    s3.write(&p2, b"two", "text/plain").unwrap();
    assert!(s3.delete_path(&format!("{prefix}/bucket")).unwrap());
    assert!(!s3.exists(&p1));
    assert!(!s3.exists(&p2));
});

e2e!(file_metadata_mime_and_hash {
    let s3 = device();
    let prefix = unique_prefix();
    let jpeg = s3.get_path(&format!("{prefix}/pic.jpg"));
    let png = s3.get_path(&format!("{prefix}/pic.png"));
    // Minimal magic-byte headers; S3 stores the content-type we send.
    let jpeg_bytes = {
        let mut v = vec![0xFF, 0xD8, 0xFF, 0xE0];
        v.extend(rand_bytes(64));
        v
    };
    let png_bytes = {
        let mut v = b"\x89PNG\r\n\x1a\n".to_vec();
        v.extend(rand_bytes(64));
        v
    };
    s3.write(&jpeg, &jpeg_bytes, "image/jpeg").unwrap();
    s3.write(&png, &png_bytes, "image/png").unwrap();
    assert_eq!(s3.get_file_size(&jpeg).unwrap(), jpeg_bytes.len() as u64);
    assert_eq!(s3.get_file_mime_type(&jpeg).unwrap(), "image/jpeg");
    assert_eq!(s3.get_file_mime_type(&png).unwrap(), "image/png");

    // Single-part S3 ETag is the content MD5 (same as Local::get_file_hash).
    let temp = TempDir::new().unwrap();
    let local = Local::new(temp.path());
    let local_jpeg = local.get_path("pic.jpg");
    local.write(&local_jpeg, &jpeg_bytes, "image/jpeg").unwrap();
    assert_eq!(
        s3.get_file_hash(&jpeg).unwrap(),
        local.get_file_hash(&local_jpeg).unwrap()
    );

    s3.delete(&jpeg, false).unwrap();
    s3.delete(&png, false).unwrap();
});

e2e!(multipart_upload_and_range_read {
    let s3 = device();
    let path = s3.get_path(&format!("{}/multipart.bin", unique_prefix()));
    // AWS/MinIO require non-final multipart parts ≥ 5 MiB.
    let chunk_size = 5 * 1024 * 1024;
    let chunks = 3_u32;
    let last_size = 256 * 1024;
    let mut payload = Vec::with_capacity(chunk_size * (chunks as usize - 1) + last_size);
    for i in 0..(chunks - 1) {
        payload.extend(std::iter::repeat(i as u8).take(chunk_size));
    }
    payload.extend(std::iter::repeat((chunks - 1) as u8).take(last_size));

    let mut metadata = UploadMetadata::default();
    let mut offset = 0usize;
    for index in 1..=chunks {
        let end = if index == chunks {
            payload.len()
        } else {
            offset + chunk_size
        };
        s3.upload(
            &payload[offset..end],
            &path,
            "application/octet-stream",
            index,
            chunks,
            &mut metadata,
        )
        .unwrap();
        offset = end;
    }
    assert_eq!(s3.get_file_size(&path).unwrap(), payload.len() as u64);
    let head = s3.read(&path, 0, Some(500)).unwrap();
    assert_eq!(head, payload[..500]);
    s3.delete(&path, false).unwrap();
});

e2e!(multipart_out_of_order {
    let s3 = device();
    let path = s3.get_path(&format!("{}/ooo.bin", unique_prefix()));
    let chunk_size = 5 * 1024 * 1024;
    let chunks = 3_u32;
    let parts: Vec<Vec<u8>> = (1..=chunks)
        .map(|i| {
            let size = if i == chunks {
                128 * 1024
            } else {
                chunk_size
            };
            vec![i as u8; size]
        })
        .collect();
    let mut metadata = UploadMetadata::default();
    for i in (1..=chunks).rev() {
        s3.upload(
            &parts[(i - 1) as usize],
            &path,
            "application/octet-stream",
            i,
            chunks,
            &mut metadata,
        )
        .unwrap();
    }
    let expected: u64 = parts.iter().map(|p| p.len() as u64).sum();
    assert_eq!(s3.get_file_size(&path).unwrap(), expected);
    s3.delete(&path, false).unwrap();
});

e2e!(copy_to_local_device {
    let s3 = device();
    let temp = TempDir::new().unwrap();
    let local = Local::new(temp.path());
    let src = s3.get_path(&format!("{}/hello.txt", unique_prefix()));
    let dst = local.get_path("hello.txt");
    s3.write(&src, b"Hello World", "text/plain").unwrap();
    s3.copy(&src, &dst, Some(&local), 1_000_000).unwrap();
    assert!(local.exists(&dst));
    assert_eq!(local.read(&dst, 0, None).unwrap(), b"Hello World");
    s3.delete(&src, false).unwrap();
});

e2e!(streaming_read_into_and_write_from {
    let s3 = device();
    let path = s3.get_path(&format!("{}/stream.bin", unique_prefix()));
    let mut payload = vec![0_u8; 256 * 1024];
    for (index, byte) in payload.iter_mut().enumerate() {
        *byte = (index % 251) as u8;
    }
    payload[0] = 0xFF;
    payload[1] = 0xFE;

    let mut source = std::io::Cursor::new(payload.as_slice());
    s3.write_from(&path, &mut source, "application/octet-stream")
        .unwrap();

    let mut sunk = Vec::new();
    let n = s3.read_into(&path, &mut sunk, 0, None).unwrap();
    assert_eq!(n, payload.len() as u64);
    assert_eq!(sunk, payload);
    s3.delete(&path, false).unwrap();
});

e2e!(parallel_multipart_upload {
    let s3 = device();
    let path = s3.get_path(&format!("{}/parallel.bin", unique_prefix()));
    let part = MIN_MULTIPART_PART_SIZE;
    let mut payload = vec![0x11_u8; part * 2 + 1024];
    for (index, byte) in payload.iter_mut().enumerate() {
        *byte = (index % 250) as u8;
    }

    let mut source = std::io::Cursor::new(payload.as_slice());
    s3.upload_parallel(
        &mut source,
        &path,
        "application/octet-stream",
        ParallelUploadOptions::new(part, 3),
    )
    .unwrap();

    assert_eq!(s3.get_file_size(&path).unwrap(), payload.len() as u64);
    let mut sunk = Vec::new();
    s3.read_into(&path, &mut sunk, 0, None).unwrap();
    assert_eq!(sunk, payload);
    s3.delete(&path, false).unwrap();
});

e2e!(path_style_and_virtual_hosted_share_bucket {
    let bucket = env_or("S3_BUCKET", "utopia-storage-test");
    let path = make_s3(&path_style_host(&bucket), &bucket);
    let Some(vhost) = make_s3_virtual(&bucket) else {
        eprintln!("skipping virtual-hosted cross-check (*.localhost unreachable)");
        return;
    };

    let prefix = unique_prefix();
    let path_object = path.get_path(&format!("{prefix}/path.txt"));
    let virtual_object = vhost.get_path(&format!("{prefix}/virtual.txt"));

    path.write(&path_object, b"path-style", "text/plain").unwrap();
    assert_eq!(vhost.read(&path_object, 0, None).unwrap(), b"path-style");

    vhost
        .write(&virtual_object, b"virtual-hosted", "text/plain")
        .unwrap();
    assert_eq!(path.read(&virtual_object, 0, None).unwrap(), b"virtual-hosted");

    let listed = path
        .list_files(&path.get_path(&prefix), 1000, None)
        .unwrap();
    let keys: Vec<_> = listed
        .files
        .iter()
        .map(|f| f.path.to_string_lossy().into_owned())
        .collect();
    assert!(keys.iter().any(|k| k.ends_with("path.txt")));
    assert!(keys.iter().any(|k| k.ends_with("virtual.txt")));

    let _ = path.delete(&path_object, false);
    let _ = path.delete(&virtual_object, false);
});

fn make_s3_virtual(bucket: &str) -> Option<S3> {
    let host = virtual_host(bucket);
    let device = make_s3(&host, bucket);
    // Probe with a HEAD against a missing key - connection/DNS failures skip the test.
    match device.get_file_size(Path::new("/__utopia_e2e_probe__")) {
        Ok(_) | Err(StorageError::NotFound(_) | StorageError::Remote { status: 404, .. }) => {
            Some(device)
        }
        Err(StorageError::Transport { .. }) => None,
        Err(other) => {
            eprintln!("virtual-hosted probe failed: {other}");
            None
        }
    }
}
