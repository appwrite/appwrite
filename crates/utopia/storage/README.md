# Utopia Storage

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/storage`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/storage) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

File storage devices: the local disk and S3-compatible object stores (AWS, DigitalOcean Spaces, Backblaze, Linode, Wasabi), with chunked uploads, conditional writes, telemetry and upload validators.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-storage = { git = "https://github.com/utopia-rs/storage" }
```

# Getting started

`utopia-storage` stores files on the local disk or in any S3-compatible object store behind one `Device` trait. Every device writes and reads streams (`Body`), uploads files in chunks, writes conditionally on ETags, copies between devices and lists what it holds. Devices are async; build them once and share them.

## Write and read a file

`write` replaces whatever is at the path and returns the file's ETag (the MD5 of its bytes on the local disk). `read` takes an offset and a length for a byte range.

```rust
use utopia_storage::{Body, Device, Local};

let device = Local::new(std::env::temp_dir().join("guide-write").to_str().unwrap());
let path = device.path("hello.txt");

let etag = device.write(&path, Body::from("Hello World"), "text/plain").await?;
assert_eq!(etag, "b10a8db164e0754105b7a99be72e3fe5");

let world = device.read(&path, 6, Some(5), None).await?.bytes().await.unwrap();
assert_eq!(&world[..], b"World");
```

## Upload a file in chunks

Pass every chunk to `upload` with the same `Metadata`; the device joins the chunks when the last one arrives, whatever order they came in.

```rust
use utopia_storage::{Body, Device, Local, Metadata};

let device = Local::new(std::env::temp_dir().join("guide-chunks").to_str().unwrap());
let path = device.path("video.mp4");
let mut metadata = Metadata::default();

device.upload(Body::from("BBBB"), &path, "video/mp4", 2, 2, &mut metadata).await?;
let received = device.upload(Body::from("AAAA"), &path, "video/mp4", 1, 2, &mut metadata).await?;
assert_eq!(received, 2);
assert_eq!(&device.read(&path, 0, None, None).await?.bytes().await.unwrap()[..], b"AAAABBBB");
```

## Write only where nothing is

`create` fails with `Error::PreconditionFailed` when a file is already there, and `replace` when the file no longer has the ETag you name, so of several writers racing for a file exactly one wins.

```rust
use utopia_storage::{Body, Device, Error, Local};

let device = Local::new(std::env::temp_dir().join("guide-lock").to_str().unwrap());
let path = device.path("lock");

let etag = device.create(&path, Body::from("first"), "text/plain").await?;
let again = device.create(&path, Body::from("second"), "text/plain").await;
assert!(matches!(again, Err(Error::PreconditionFailed { .. })));

device.replace(&path, Body::from("second"), &etag, "text/plain").await?;
let stale = device.replace(&path, Body::from("third"), &etag, "text/plain").await;
assert!(matches!(stale, Err(Error::PreconditionFailed { .. })));
```

## Store files in S3

`S3::new` takes the endpoint with the bucket in its path (path style), or a provider constructor (`S3::aws`, `S3::do_spaces`, `S3::backblaze`, `S3::linode`, `S3::wasabi`) builds the bucket's virtual host. With a bucket name, copies on the device run server side.

```rust
use utopia_storage::{Acl, Device, DeviceType, S3};

let device = S3::new("uploads", "access-key", "secret-key", "http://minio:9000/bucket", "us-east-1")?
    .with_acl(Some(Acl::PublicRead))
    .with_bucket("bucket");
assert_eq!(device.path("photo.jpg"), "uploads/photo.jpg");

let aws = S3::aws("uploads", "access-key", "secret-key", "my-bucket", "eu-west-1")?;
assert_eq!(aws.kind(), DeviceType::AwsS3);
```

## Validate uploads

The validators implement `utopia_validators::Validator`, for request parameters.

```rust
use utopia_storage::validator::{FileExt, FileName, FileSize};
use utopia_validators::Validator;

assert!(FileExt::new(["jpg", "png"]).is_valid(&"photo.PNG".into()));
assert!(!FileName.is_valid(&"../etc/passwd".into()));
assert!(!FileSize::new(1024).is_valid(&2048.into()));
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
