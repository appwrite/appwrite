# Getting started

`utopia-storage` stores files on the local disk or in any S3-compatible object store behind one `Device` trait. Every device writes and reads streams (`Body`), uploads files in chunks, writes conditionally on ETags, copies between devices and lists what it holds. Devices are async; build them once and share them.

## Write and read a file

`write` replaces whatever is at the path and returns the file's ETag (the MD5 of its bytes on the local disk). `read` takes an offset and a length for a byte range.

```rust
use utopia_storage::{Body, Device, Local};

# utopia_async::run(async {
let device = Local::new(std::env::temp_dir().join("guide-write").to_str().unwrap());
let path = device.path("hello.txt");

let etag = device.write(&path, Body::from("Hello World"), "text/plain").await?;
assert_eq!(etag, "b10a8db164e0754105b7a99be72e3fe5");

let world = device.read(&path, 6, Some(5), None).await?.bytes().await.unwrap();
assert_eq!(&world[..], b"World");
# Ok::<(), utopia_storage::Error>(())
# }).unwrap();
```

```php
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

$device = new Local(sys_get_temp_dir() . '/guide-write');
$path = $device->getPath('hello.txt');

echo $device->write($path, new Stream('Hello World'), 'text/plain'), "\n"; // b10a8db164e0754105b7a99be72e3fe5
echo $device->read($path, 6, 5)->getContents(), "\n"; // World
```

## Upload a file in chunks

Pass every chunk to `upload` with the same `Metadata`; the device joins the chunks when the last one arrives, whatever order they came in.

```rust
use utopia_storage::{Body, Device, Local, Metadata};

# utopia_async::run(async {
let device = Local::new(std::env::temp_dir().join("guide-chunks").to_str().unwrap());
let path = device.path("video.mp4");
let mut metadata = Metadata::default();

device.upload(Body::from("BBBB"), &path, "video/mp4", 2, 2, &mut metadata).await?;
let received = device.upload(Body::from("AAAA"), &path, "video/mp4", 1, 2, &mut metadata).await?;
assert_eq!(received, 2);
assert_eq!(&device.read(&path, 0, None, None).await?.bytes().await.unwrap()[..], b"AAAABBBB");
# device.delete(&path, false).await?;
# Ok::<(), utopia_storage::Error>(())
# }).unwrap();
```

```php
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

$device = new Local(sys_get_temp_dir() . '/guide-chunks');
$path = $device->getPath('video.mp4');
$metadata = [];

$device->upload(new Stream('BBBB'), $path, 'video/mp4', 2, 2, $metadata);
echo $device->upload(new Stream('AAAA'), $path, 'video/mp4', 1, 2, $metadata), "\n"; // 2
echo $device->read($path)->getContents(), "\n"; // AAAABBBB
$device->delete($path);
```

## Write only where nothing is

`create` fails with `Error::PreconditionFailed` when a file is already there, and `replace` when the file no longer has the ETag you name, so of several writers racing for a file exactly one wins.

```rust
use utopia_storage::{Body, Device, Error, Local};

# utopia_async::run(async {
let device = Local::new(std::env::temp_dir().join("guide-lock").to_str().unwrap());
let path = device.path("lock");
# let _ = device.delete(&path, false).await;

let etag = device.create(&path, Body::from("first"), "text/plain").await?;
let again = device.create(&path, Body::from("second"), "text/plain").await;
assert!(matches!(again, Err(Error::PreconditionFailed { .. })));

device.replace(&path, Body::from("second"), &etag, "text/plain").await?;
let stale = device.replace(&path, Body::from("third"), &etag, "text/plain").await;
assert!(matches!(stale, Err(Error::PreconditionFailed { .. })));
# device.delete(&path, false).await?;
# Ok::<(), utopia_storage::Error>(())
# }).unwrap();
```

```php
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Exception\PreconditionFailedException;

$device = new Local(sys_get_temp_dir() . '/guide-lock');
$path = $device->getPath('lock');
@unlink($path);

$etag = $device->create($path, new Stream('first'), 'text/plain');
try {
    $device->create($path, new Stream('second'), 'text/plain');
} catch (PreconditionFailedException) {
    echo "taken\n"; // taken
}
$device->replace($path, new Stream('second'), $etag, 'text/plain');
$device->delete($path);
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
# Ok::<(), utopia_storage::Error>(())
```

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\S3;

$device = new S3('uploads', 'access-key', 'secret-key', 'http://minio:9000/bucket', 'us-east-1', Acl::PublicRead, bucket: 'bucket');
echo $device->getPath('photo.jpg'), "\n"; // uploads/photo.jpg

$aws = new AWS('uploads', 'access-key', 'secret-key', 'my-bucket', 'eu-west-1');
echo $aws->getType()->value, "\n"; // awss3
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

```php
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileName;
use Utopia\Storage\Validator\FileSize;

var_dump((new FileExt(['jpg', 'png']))->isValid('photo.PNG')); // bool(true)
var_dump((new FileName())->isValid('../etc/passwd')); // bool(false)
var_dump((new FileSize(1024))->isValid(2048)); // bool(false)
```
