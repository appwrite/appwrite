# Utopia Storage

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/storage`](https://github.com/utopia-php/monorepo/tree/main/packages/storage) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/storage.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Storage is a simple and lightweight library for managing application storage across multiple adapters. This library is designed to be easy to learn and use, with a consistent API regardless of the storage provider. This library is maintained by the [Appwrite team](https://appwrite.io).

This library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project.

## Getting started

Install using Composer:
```bash
composer require utopia-php/storage
```

### Basic usage

Devices are immutable value objects: construct one with its configuration and use it anywhere, including across coroutines.

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

$device = new Local('/path/to/storage');

// Upload a file — contents move as PSR-7 streams, so memory stays bounded
$device->upload(Stream::fromResource(fopen('/local/path/to/file.png', 'rb')), 'destination/path/file.png', 'image/png');

// Check if file exists
$exists = $device->exists('destination/path/file.png');

// Read file contents as a stream
$contents = (string) $device->read('destination/path/file.png');

// Delete a file
$device->delete('destination/path/file.png');
```

## Available adapters

### Local storage

Use the local filesystem for storing files.

```php
use Utopia\Storage\Device\Local;

$device = new Local('/path/to/storage');
```

### AWS S3

Store files in Amazon S3 or compatible services.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\S3;

$device = new S3(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME.s3.us-east-1.amazonaws.com', // Host
    'us-east-1', // Region
    Acl::Private, // Access control (default: private)
    bucket: 'YOUR_BUCKET_NAME', // Optional: enables server-side copy
);
```

When the bucket name is known, a same-device `copy()` — and therefore `move()` — runs entirely server side (`CopyObject`, or `UploadPartCopy` above 5 GB), so no bytes move through PHP. The provider-specific adapters below pass their bucket automatically. Without it, `copy()` falls back to a streamed download and re-upload.

The provider-specific adapters below build the host for you from a bucket and region. Every S3-family adapter also accepts optional named constructor arguments:

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\AWS;

$device = new AWS(
    'root',
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    AWS::US_EAST_1,
    Acl::Private,
    client: $psrClient, // any PSR-18 client (default: utopia-php/client with retries, see below)
);

// Available ACL options
// Acl::Private, Acl::PublicRead, Acl::PublicReadWrite, Acl::AuthenticatedRead
// Pass null to write without an ACL, which buckets that have ACLs disabled require
```

### DigitalOcean Spaces

Store files in DigitalOcean Spaces.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\DOSpaces;

$device = new DOSpaces(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    DOSpaces::NYC3, // Region (default: nyc3)
    Acl::Private // Access control (default: private)
);

// Available regions
// DOSpaces::NYC3, DOSpaces::SGP1, DOSpaces::FRA1, DOSpaces::SFO2, DOSpaces::SFO3, DOSpaces::AMS3
```

### Backblaze B2

Store files in Backblaze B2 Cloud Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Backblaze;

$device = new Backblaze(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Backblaze::US_WEST_004, // Region (default: us-west-004)
    Acl::Private // Access control (default: private)
);

// Available regions (clusters)
// Backblaze::US_WEST_000, Backblaze::US_WEST_001, Backblaze::US_WEST_002,
// Backblaze::US_WEST_004, Backblaze::EU_CENTRAL_003
```

### Linode Object Storage

Store files in Linode Object Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Linode;

$device = new Linode(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Linode::EU_CENTRAL_1, // Region (default: eu-central-1)
    Acl::Private // Access control (default: private)
);

// Available regions
// Linode::EU_CENTRAL_1, Linode::US_SOUTHEAST_1, Linode::US_EAST_1, Linode::AP_SOUTH_1
```

### Wasabi cloud storage

Store files in Wasabi Cloud Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Wasabi;

$device = new Wasabi(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Wasabi::EU_CENTRAL_1, // Region (default: eu-central-1)
    Acl::Private // Access control (default: private)
);

// Available regions
// Wasabi::US_EAST_1, Wasabi::US_EAST_2, Wasabi::US_WEST_1, Wasabi::US_CENTRAL_1,
// Wasabi::EU_CENTRAL_1, Wasabi::EU_CENTRAL_2, Wasabi::EU_WEST_1, Wasabi::EU_WEST_2,
// Wasabi::AP_NORTHEAST_1, Wasabi::AP_NORTHEAST_2
```

## Common operations

All storage adapters provide a consistent API for working with files:

```php
use Utopia\Psr7\Stream;

// Upload a file from disk without loading it into memory
$device->upload(Stream::fromResource(fopen('/path/to/local/file.jpg', 'rb')), 'remote/path/file.jpg', 'image/jpeg');

// Contents already in memory wrap into a stream for free
$device->upload(new Stream($data), 'remote/path/file.jpg', 'image/jpeg');

// Check if file exists
$exists = $device->exists('remote/path/file.jpg');

// Get file size
$size = $device->getFileSize('remote/path/file.jpg');

// Get file MIME type
$mime = $device->getFileMimeType('remote/path/file.jpg');

// Get file MD5 hash
$hash = $device->getFileHash('remote/path/file.jpg');

// Size, last modification and ETag in one request
$info = $device->getFileInfo('remote/path/file.jpg');

// Read file contents as a PSR-7 stream; casting to string buffers it
$stream = $device->read('remote/path/file.jpg');

// Read partial file contents
$chunk = (string) $device->read('remote/path/file.jpg', 0, 1024); // Read first 1KB

// Multipart/chunked uploads — part 1 of 3
$metadata = [];
$device->upload(new Stream($firstChunk), 'remote/video.mp4', 'video/mp4', 1, 3, $metadata);

// Resumable uploads: prepare, upload chunks in any order, finalize
$device->prepare('remote/video.mp4', 'video/mp4', 3, $metadata);
$device->upload(new Stream($secondChunk), 'remote/video.mp4', 'video/mp4', 2, 3, $metadata);
$device->finalize('remote/video.mp4', 3, $metadata);

// Streaming uploads whose chunk count is unknown up front: pass 0 chunks, then finalize with the final count
$device->upload(new Stream($firstChunk), 'remote/video.mp4', 'video/mp4', 1, 0, $metadata);
$device->upload(new Stream($secondChunk), 'remote/video.mp4', 'video/mp4', 2, 0, $metadata);
$device->finalize('remote/video.mp4', 2, $metadata); // replaces whatever was at the path

// List files under a prefix, one page at a time
$list = $device->listFiles('remote/directory', 100);
foreach ($list->files as $file) {
    echo $file->path . ' (' . $file->size . " bytes)\n";
}
if ($list->cursor !== null) {
    $list = $device->listFiles('remote/directory', 100, $list->cursor); // Next page
}

// Delete file
$device->delete('remote/path/file.jpg');

// Delete directory
$device->deletePath('remote/directory');

// Copy a file, on the same device or onto another one
$device->copy('source/path.jpg', 'target/path.jpg');
$sourceDevice->copy('source/path.jpg', 'target/path.jpg', $targetDevice);

// Copy with a custom chunk size (default: 20 MB)
$sourceDevice->copy('source/path.jpg', 'target/path.jpg', $targetDevice, 10000000);

// Move is copy plus delete
$device->move('source/path.jpg', 'target/path.jpg');
```

### Conditional operations

Every file carries an ETag, reported by `getFileInfo()` and returned by every write, conditional or not. Naming it makes an operation apply to that version of the file and no other, which is what coordinating several processes through one file takes: a lock, a lease, or a dataset that must never be read half old and half new.

```php
use Utopia\Storage\Exception\PreconditionFailedException;

// Write only where nothing is yet; exactly one of several racing callers succeeds
try {
    $etag = $device->create('locks/refresh', new Stream($token), 'text/plain');
} catch (PreconditionFailedException) {
    // Someone else holds it
}

// Write over one version only; a stale ETag is refused
$etag = $device->replace('locks/refresh', new Stream($token), $etag, 'text/plain');

// Read only while the file still is the version you know
$chunk = $device->read('dataset.bin', $offset, $length, $etag);
```

On S3 the check and the operation are one request (`If-None-Match`, `If-Match`), so nothing can slip in between. On the local disk the ETag is the file's MD5 hash. `create()` opens the file exclusively and has no window at all; `replace()` checks the hash before writing, so a replacement landing between the two goes unnoticed and the last writer wins. Local writes land by renaming a finished temporary file over the path, so a reader never sees two versions mixed and a write that fails part way leaves the previous one in place. A conditional read on `Local` pins the file open and hashes it, one full pass before the first byte comes back.

Multipart uploads left neither finalized nor aborted keep their parts, and S3 bills for them. `listUploads()` finds them, so a cleanup job can abort what a crashed process left behind. It is an `S3` method, forwarded by the `Telemetry` decorator; other devices have no multipart uploads and refuse the call. Amazon S3 takes any prefix; MinIO only honours a whole key.

```php
$list = $device->listUploads('remote/directory');
foreach ($list->uploads as $upload) {
    $device->abort($upload->path, $upload->uploadId);
}
```

## Custom HTTP client

The S3-family adapters send requests through any [PSR-18](https://www.php-fig.org/psr/psr-18/) client. By default they use [utopia-php/client](https://github.com/utopia-php/client) with the cURL adapter — no overall request timeout, a stall watchdog that aborts once no bytes move for 60 seconds, TCP keepalive — and the `Retry` decorator configured with `S3\RetryStrategy`. It retries transient S3 rate-limiting errors (`SlowDown`, `ServiceUnavailable`, `Throttling`, `RequestThrottled`, and plain 429/503 responses) three times with exponential backoff and full jitter from a 0.5 second base delay.

Inject your own client to change the transport or the retry policy — for example the Swoole coroutine adapter with more aggressive retries:

```php
use Utopia\Client;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\RetryStrategy;

$client = new Retry(
    new Client(new SwooleAdapter())->withTimeout(60),
    new RetryStrategy(retries: 5, delay: 1.0),
);

$device = new S3('root', 'ACCESS_KEY', 'SECRET_KEY', 'HOST', 'us-east-1', client: $client);
```

Omit the `Retry` decorator to disable retries entirely, or pass any `Utopia\Client\Decorator\Retry\Strategy` of your own.

## Error handling

Every runtime failure throws a subclass of `Utopia\Storage\Exception\StorageException`, so one catch covers any storage problem. Match more precisely when you need to branch:

```php
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\Exception\TransportException;
use Utopia\Storage\Exception\UploadException;

try {
    $contents = $device->read('remote/path/file.jpg');
} catch (NotFoundException) {
    // The file does not exist
} catch (TransportException $e) {
    // The request never reached the service; $e->getPrevious() is the PSR-18 exception
} catch (RemoteException $e) {
    // The service answered with an error: $e->getCode() is the HTTP status,
    // $e->errorCode the service's own error identifier (for example `SlowDown`)
} catch (UploadException) {
    // A chunked upload is in an invalid state (missing chunk, never prepared)
} catch (StorageException $e) {
    // Anything else, such as a local filesystem failure
}
```

Invalid arguments (a non-positive chunk size, a page size above the adapter limit) throw SPL `\InvalidArgumentException` — these are programmer errors, not storage failures.

## Telemetry

Wrap any device with the `Telemetry` decorator to record a `storage.operation` histogram for every call through a [utopia-php/telemetry](https://github.com/utopia-php/telemetry) adapter:

```php
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Telemetry;

$device = new Telemetry($telemetryAdapter, new Local('/path/to/storage'));
```

## Upgrading from 4.1

- `write()` returns the ETag of the file written, a string, instead of `true`. A `false` never happened: every adapter threw instead. Callers testing the result for truth keep working; callers comparing it with `true` do not.
- `Device` has three new abstract methods, `getFileInfo()`, `create()` and `replace()`, and `read()` takes an optional ETag. Adapters outside this library must implement them.
- `finalize()` completes a multipart upload over an existing file instead of skipping it. Finalizing twice is still not an error, on either adapter and whatever the chunk count. A chunk that never arrived is reported, whatever file happens to sit at the path.

## Upgrading from 3.x

Version 4.0 makes streaming the only I/O path: file contents move as PSR-7 streams end to end, so memory stays bounded regardless of file size.

- `read()` returns a `Psr\Http\Message\StreamInterface` instead of a string. Cast with `(string)` or call `getContents()` where you want the bytes in memory — the cost is visible at the call site.
- `write()` and the upload methods take a `StreamInterface` instead of a string. Wrap a string with `new Utopia\Psr7\Stream($data)`; wrap an open file handle with `Utopia\Psr7\Stream::fromResource($handle)`. S3 requires seekable streams: the payload is hashed for signing, rewound and sent — the same approach the AWS SDK takes.
- `uploadData()` and `uploadChunk()` merged into one `upload($data, $path, $contentType, $chunk, $chunks, $metadata)` — a whole file is the single-chunk default. `prepareUpload()`/`finalizeUpload()` are now `prepare()`/`finalize()`.
- `transfer()` is replaced by `copy($source, $target, $to, $chunkSize)` where `$to` defaults to the same device, and `move()` now works across every adapter as copy plus delete (`Local` still renames in place). The `TRANSFER_CHUNK_SIZE` constant is now `COPY_CHUNK_SIZE`.

## Upgrading from 2.x

Version 3.0 makes every device immutable and safe to share across coroutines, and removes all global state:

- The `Storage` class is gone entirely: replace `Storage::setDevice('files', $device)` and `Storage::getDevice('files')` with your own wiring (a container, or passing the device instance directly), and inline `Storage::human()` if you used it.
- `setTelemetry()` is gone: wrap the device in the `Utopia\Storage\Device\Telemetry` decorator instead. `setHttpVersion()` is gone: transport options now belong to the injected PSR-18 client. The static `S3::setRetryAttempts()`/`S3::setRetryDelay()` moved into the `S3\RetryStrategy` used by the default client's `Retry` decorator — inject your own client to tune or disable retries.
- `setTransferChunkSize()`/`getTransferChunkSize()` became a per-call argument: `transfer($path, $destination, $device, $chunkSize)`.
- String constants became enums: the `Storage::DEVICE_*` constants are now the `Utopia\Storage\DeviceType` enum (`getType()` returns it), and the `S3::ACL_*` constants are now the `Utopia\Storage\Acl` enum.
- The S3 adapter no longer stores request headers on the instance, so one device can serve concurrent requests (for example Swoole coroutines) without data races.
- Requests go through a PSR-18 client instead of raw cURL calls. The default is [utopia-php/client](https://github.com/utopia-php/client) with the cURL adapter; pass the `client` constructor argument to swap the transport.
- Uploads are data-based: `upload($sourcePath, ...)` was removed — read the file yourself and call `uploadData($data, ...)` — and `uploadChunk()` now takes the chunk contents instead of a source file path.
- Filesystem-only methods (`createDirectory()`, `getDirectorySize()`, `getPartitionFreeSpace()`, `getPartitionTotalSpace()`) left the base `Device` contract and remain on `Local`; wrap devices in the `Telemetry` decorator and use its `getDevice()` accessor when you need them. `getFiles()` — which returned path strings on `Local` but a raw `ListObjectsV2` array on `S3` — is replaced by `listFiles(prefix, max, cursor)` returning typed `FileList`/`FileInfo` value objects consistently on every adapter.
- `getName()` and `getDescription()` were removed; use `getType()` to identify an adapter.
- Exceptions are typed: every runtime failure extends `Utopia\Storage\Exception\StorageException` (`NotFoundException`, `TransportException`, `RemoteException`, `UploadException`) instead of bare `\Exception`, and missing files throw `NotFoundException` consistently on every adapter and method — including S3 HEAD responses, which previously surfaced as a generic error with an empty message. Existing `catch (\Exception)` blocks keep working.

## Adding new adapters

For information on adding new storage adapters, see the [Adding New Storage Adapter](https://github.com/utopia-php/storage/blob/master/docs/adding-new-storage-adapter.md) guide.

## System requirements

Utopia Storage requires PHP 8.5 or later. We recommend using the latest PHP version whenever possible.

## Contributing

For security issues, please email [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We welcome you to contribute to the Utopia Storage library. For details on how to do this, please refer to our [Contributing Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## License

This library is available under the MIT License.

## Copyright

```
Copyright (c) 2019-2025 Appwrite Team <team@appwrite.io>
```
