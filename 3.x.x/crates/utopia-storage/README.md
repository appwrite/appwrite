# utopia-storage

Storage devices and file validators for Utopia. Rust port of [utopia-php/storage](https://github.com/utopia-php/storage).

## Install

This crate is standalone (not yet in the workspace `members` list). Add it by path:

```toml
utopia-storage = { path = "../utopia-storage" }
```

Optional features:

```toml
utopia-storage = { path = "../utopia-storage" }
```

| Feature | Description |
|---------|-------------|
| `s3` | S3-compatible adapters (`S3`, `AwsS3`, `DoSpaces`, `Linode`, `Backblaze`, `Wasabi`) with SigV4, multipart upload, listing, deletes, server-side copy, and retry handling |
| `telemetry` | `TelemetryDevice` decorator that records `storage.operation` histograms through `utopia-telemetry` |
| `validators` | Implement [`utopia-validators`](https://github.com/utopia-php/validators) `Validator` for file validators |

Default features enable `s3`, `telemetry`, and `validators`.

## Usage

```rust
use std::fs::File;
use utopia_storage::{Device, Local, ParallelUploadOptions, UploadMetadata};

let device = Local::new("/var/storage");
let path = device.get_path("uploads/photo.png");

device.write(&path, b"...", "image/png")?;
let bytes = device.read(&path, 0, None)?;
assert!(device.exists(&path));
device.delete(&path, false)?;

// Stream large objects - memory stays bounded by the pipe/part size
let mut file = File::open("/tmp/large.bin")?;
device.write_from(&path, &mut file, "application/octet-stream")?;
let mut out = File::create("/tmp/download.bin")?;
device.read_into(&path, &mut out, 0, None)?;

// Parallel multipart upload (S3 uses concurrent part PUTs)
let mut file = File::open("/tmp/large.bin")?;
device.upload_parallel(
    &mut file,
    &path,
    "application/octet-stream",
    ParallelUploadOptions::default(),
)?;

// Chunked upload (3 parts)
let mut metadata = UploadMetadata::default();
device.upload(b"aaa", &path, "text/plain", 1, 3, &mut metadata)?;
device.upload(b"bbb", &path, "text/plain", 2, 3, &mut metadata)?;
device.upload(b"ccc", &path, "text/plain", 3, 3, &mut metadata)?;
# Ok::<(), utopia_storage::StorageError>(())
```

## API Reference

### Errors

| Type | Description |
|------|-------------|
| `StorageError` | Top-level error enum (`NotFound`, `Upload`, `Io`, `Remote`, `Transport`, `Message`) |
| `NotFound` | File or directory does not exist |
| `UploadError` | Chunked upload failed (missing chunk, finalize error, etc.) |
| `Remote` | Remote service failure with HTTP status, provider error code, message, and request IDs |
| `Transport` | HTTP transport failure with optional source error |

### Types

| Type | Description |
|------|-------------|
| `DeviceType` | Backend kind (`Local`, `S3`, `AwsS3`, `DoSpaces`, `Wasabi`, `Backblaze`, `Linode`) |
| `FileInfo` | Single file metadata (`path`, `size`, `modified_at`, `etag`) |
| `FileList` | Paginated listing (`files`, `cursor`) |
| `Acl` | S3 canned ACL values |
| `UploadMetadata` | Chunked upload state (`parts`, `chunks`, `content_type`, `upload_id`) |
| `PartValue` | Upload part marker (`Done` for local parts, `Etag(String)` for S3 multipart parts) |
| `COPY_CHUNK_SIZE` | Default 20 MiB copy chunk size (matches PHP) |

### `Device` trait

Byte-slice helpers (`read` / `write`) remain for small payloads. Large I/O uses
`read_into` / `write_from` / `upload_parallel` so memory stays bounded (PHP
`StreamInterface` parity). S3 downloads stream the HTTP body; uploads over 5 MiB
use multipart, with concurrent part PUTs via `ParallelUploadOptions`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `get_type` | `fn get_type(&self) -> DeviceType` | Device backend type |
| `get_root` | `fn get_root(&self) -> &Path` | Configured storage root |
| `get_path` | `fn get_path(&self, filename: &str) -> PathBuf` | Resolve and normalize `root/filename` |
| `prepare` | `fn prepare(&self, path, content_type, chunks, &mut UploadMetadata) -> Result<(), StorageError>` | Initialize chunked upload state |
| `upload_chunk` | `fn upload_chunk(&self, data, path, chunk, chunks, &mut UploadMetadata) -> Result<u32, StorageError>` | Store one chunk; returns chunks received |
| `finalize` | `fn finalize(&self, path, chunks, &mut UploadMetadata) -> Result<bool, StorageError>` | Assemble chunks into final file |
| `abort` | `fn abort(&self, path, upload_id) -> Result<bool, StorageError>` | Cancel chunked upload and remove temp parts |
| `upload` | default | `prepare` + `upload_chunk` + auto-`finalize` on last chunk |
| `read` | `fn read(&self, path, offset, length) -> Result<Vec<u8>, StorageError>` | Read full file or byte window into memory |
| `read_into` | `fn read_into(&self, path, writer, offset, length) -> Result<u64, StorageError>` | Stream object bytes into a writer (bounded memory) |
| `write` | `fn write(&self, path, data, content_type) -> Result<(), StorageError>` | Write bytes already in memory |
| `write_from` | `fn write_from(&self, path, reader, content_type) -> Result<(), StorageError>` | Stream a seekable reader; large S3 objects use multipart |
| `upload_from` | `fn upload_from(&self, reader, path, content_type, chunk, chunks, metadata)` | Upload one part from a reader |
| `upload_parallel` | `fn upload_parallel(&self, source, path, content_type, options)` | Multipart upload with concurrent part PUTs on S3 |
| `exists` | `fn exists(&self, path) -> bool` | Path exists on device |
| `delete` | `fn delete(&self, path, recursive) -> Result<bool, StorageError>` | Delete file or directory |
| `delete_path` | `fn delete_path(&self, path) -> Result<bool, StorageError>` | Delete directory tree relative to root |
| `list_files` | `fn list_files(&self, prefix, max, cursor) -> Result<FileList, StorageError>` | Recursive paginated listing |
| `get_file_size` | `fn get_file_size(&self, path) -> Result<u64, StorageError>` | File size in bytes |
| `get_file_mime_type` | `fn get_file_mime_type(&self, path) -> Result<String, StorageError>` | MIME type via extension (`mime_guess`) |
| `get_file_hash` | `fn get_file_hash(&self, path) -> Result<String, StorageError>` | MD5 hex digest |
| `create_directory` | `fn create_directory(&self, path) -> Result<bool, StorageError>` | Create directory tree |
| `copy` | default | Copy within or across devices (chunked for large files) |
| `move` | `fn move(&self, source, target) -> Result<bool, StorageError>` | Move/rename file |
| `get_absolute_path` | default | Normalize path segments (PHP `realpath`-like) |

### `Local` device

Filesystem adapter mirroring [`Local.php`](https://github.com/utopia-php/storage/blob/main/src/Storage/Device/Local.php).

| Method | Description |
|--------|-------------|
| `Local::new(root)` | Construct with filesystem root path |
| `get_directory_size` | Recursive directory size (`-1` on error) |
| `get_partition_free_space` | Free space on the filesystem containing the device root |
| `get_partition_total_space` | Total space on the filesystem containing the device root |

Chunked uploads store parts under `tmp_{basename}/` next to the destination file, then assemble with a temporary file and atomic rename.

### S3 adapters

The `s3` feature talks to S3-compatible APIs through [`utopia-client`](../utopia-client) (PHP `utopia-php/client` cURL adapter):

```rust
use utopia_storage::{Acl, AwsS3, Device};

let device = AwsS3::new(
    "/root",
    "access-key",
    "secret-key",
    "bucket-name",
    AwsS3::US_EAST_1,
    Acl::Private,
)?;

let path = device.get_path("avatars/user.png");
device.write(&path, b"...", "image/png")?;
# Ok::<(), utopia_storage::StorageError>(())
```

Available adapters:

| Adapter | Constructor host format | Device type |
|---------|-------------------------|-------------|
| `S3` | Custom S3-compatible endpoint | `DeviceType::S3` |
| `AwsS3` | `{bucket}.s3.{region}.amazonaws.com` / China regions use `.amazonaws.cn` | `DeviceType::AwsS3` |
| `DoSpaces` | `{bucket}.{region}.digitaloceanspaces.com` | `DeviceType::DoSpaces` |
| `Linode` | `{bucket}.{region}.linodeobjects.com` | `DeviceType::Linode` |
| `Backblaze` | `{bucket}.s3.{region}.backblazeb2.com` | `DeviceType::Backblaze` |
| `Wasabi` | `{bucket}.s3.{region}.wasabisys.com` | `DeviceType::Wasabi` |

`S3` signs every request with AWS Signature V4, sends `content-md5` and
`x-amz-content-sha256`, decodes S3 XML responses, maps S3 404/`NoSuchKey` to
`StorageError::NotFound`, and wraps transport failures as `StorageError::Transport`.
Object bodies are opaque bytes (never lossy UTF-8). Successful GETs stream into
the caller via `read_into`; request retries reuse `bytes::Bytes` without
re-copying. Multipart upload stores S3 ETags in `UploadMetadata.parts` and
completes parts in numeric order. `upload_parallel` uploads parts concurrently
(default 4 workers × 8 MiB parts). Same-device copies with a configured bucket use
server-side `CopyObject` up to 5 GiB and `UploadPartCopy` above that size.
Cross-device `copy` pipes windows of at most `COPY_CHUNK_SIZE`.

`RetryStrategy` retries transient S3 throttling responses (`SlowDown`,
`ServiceUnavailable`, `Throttling`, `RequestThrottled`, plus status `429`/`503`
fallback) with exponential backoff and jitter.

### Telemetry

Enable `telemetry` (default) and wrap any device:

```rust
use utopia_storage::{Local, TelemetryDevice};
use utopia_telemetry::NoneAdapter;

let adapter = NoneAdapter;
let device = TelemetryDevice::new(&adapter, Local::new("/var/storage"));
```

Each delegated operation records a `storage.operation` histogram with
`storage=<device type>` and `operation=device:<method>`.

### Validators

Standalone validators with `is_valid` helpers. Enable `validators` feature for `utopia_validators::Validator` integration.

| Validator | Validates |
|-----------|-----------|
| `FileName` | Non-empty `a-z`, `A-Z`, `0-9`, `.`, `-`, `_` only |
| `FileSize` | Size `<= max` bytes |
| `FileExt` | Allowed file extension |
| `FileType` | Binary signature (JPEG, GIF, PNG, GZIP) |
| `Upload` | Existing regular file under configured upload roots (Rust equivalent of PHP `is_uploaded_file`) |

## Tests

```bash
cd crates/utopia-storage
cargo test -p utopia-storage --all-features
```

Integration tests use `tempfile` for isolated directories. S3 protocol coverage lives in
`tests/s3.rs` (utopia-test-wiremock). Live MinIO coverage is in `tests/e2e_minio.rs`.

### MinIO E2E

Live S3 adapter tests against MinIO from `docker-compose.test.yml` (same stack as
other live services). Start MinIO, then run the suite:

```bash
./crates/utopia-storage/e2e/minio.sh
# or:
docker compose -f docker-compose.test.yml up -d --wait minio
cargo test -p utopia-storage --features s3 --test e2e_minio -- --nocapture
```

## Benchmarks

```bash
cd crates/utopia-storage
cargo bench -p utopia-storage --bench storage_local_write_read
```

Reports `storage_local_write` and `storage_local_read` ops/s.

### Big-file S3 upload (PHP vs Rust)

Compare large MinIO uploads (single PUT / sequential multipart / parallel multipart),
including peak OS RSS (sampled from `/proc` every ~2ms; fresh process per workload):

```bash
BENCH_SIZE_MB=64 BENCH_ITERS=3 ./benchmarks/storage/bench_big_upload.sh
# starts MinIO from docker-compose.test.yml when not already healthy
```

Writes `benchmarks/storage/big_upload_report.md` with throughput and peak-memory tables. Rust-only:

```bash
S3_HOST=http://127.0.0.1:9805/utopia-storage-test \
  BENCH_SIZE_MB=64 cargo bench -p utopia-storage --bench storage_s3_big_upload
```

## Code quality

```bash
cargo fmt
cargo clippy -p utopia-storage --all-features --all-targets -- -D warnings
cargo doc --no-deps
```
