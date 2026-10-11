This library compresses and decompresses data with seven algorithms behind one `Algorithm` trait: Brotli, Deflate, Gzip, LZ4, Snappy, XZ and Zstd. It also picks the algorithm to answer an HTTP request with, from the client's `Accept-Encoding` header. The output matches what PHP's extensions write, so either runtime reads what the other wrote. This guide covers the common tasks.

## Compress a response for the client

`Compression::from_accept_encoding` reads the header and returns the accepted algorithm with the highest `q` value, or `None` when the response should go out as is. Send the result with its `content_encoding()` as the `Content-Encoding` header.

```
use utopia_compression::{Algorithm, Compression};

let body = b"{\"users\": [], \"total\": 0}".repeat(100);

match Compression::from_accept_encoding("gzip;q=0.9, br, deflate;q=0.5", &[] as &[&str]) {
    Some(algorithm) => {
        let encoded = algorithm.compress(&body)?;
        assert_eq!(algorithm.content_encoding(), "br");
        assert!(encoded.len() < body.len());
    }
    None => unreachable!("Brotli is accepted"),
}
# Ok::<(), utopia_compression::Error>(())
```

```php
use Utopia\Compression\Compression;

$body = str_repeat('{"users": [], "total": 0}', 100);

$algorithm = Compression::fromAcceptEncoding('gzip;q=0.9, br, deflate;q=0.5');
$encoded = $algorithm->compress($body);
echo $algorithm->getContentEncoding(), "\n"; // br
var_dump(strlen($encoded) < strlen($body)); // bool(true)
```

## Offer only some algorithms

Pass the names you offer. Clients' preferences decide among them, and the first one listed in the header wins a tie. `none` and `identity` mean no compression.

```
use utopia_compression::{Algorithm, Compression};

let chosen = Compression::from_accept_encoding("br;q=0.5, gzip;q=0.5", &["gzip", "brotli"]);
assert_eq!(chosen.unwrap().name(), "brotli");

assert!(Compression::from_accept_encoding("br", &["gzip"]).is_none());
```

```php
use Utopia\Compression\Compression;

echo Compression::fromAcceptEncoding('br;q=0.5, gzip;q=0.5', ['gzip', 'brotli'])->getName(), "\n"; // brotli
var_dump(Compression::fromAcceptEncoding('br', ['gzip'])); // NULL
```

## Choose an algorithm and its level

Each algorithm is a type you can build directly. Brotli, LZ4 and Zstd have levels: `set_level` checks the range and fails with `Error::InvalidArgument` outside it.

```
use utopia_compression::{Algorithm, Brotli, Error, Zstd};

let mut zstd = Zstd::new();
zstd.set_level(19)?;
let packed = zstd.compress(b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")?;
assert_eq!(zstd.decompress(&packed)?, b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");

let mut brotli = Brotli::new();
assert_eq!(brotli.set_level(12), Err(Error::InvalidArgument("Level must be between 0 and 11".into())));
# Ok::<(), Error>(())
```

```php
use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Zstd;

$zstd = new Zstd();
$zstd->setLevel(19);
$packed = $zstd->compress(str_repeat('a', 40));
echo $zstd->decompress($packed), "\n"; // aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa

try {
    (new Brotli())->setLevel(12);
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage(), "\n"; // Level must be between 0 and 11
}
```

## Handle data that is not compressed

Decompressing data that is not a valid stream fails with `Error::Codec`, whose message is the warning PHP's extension emits. PHP returns an empty string in that case.

```
use utopia_compression::{Algorithm, Error, Gzip};

assert_eq!(Gzip::new().decompress(b"plain text"), Err(Error::Codec("gzdecode(): data error".into())));
```

```php
use Utopia\Compression\Algorithms\GZIP;

var_dump(@(new GZIP())->decompress('plain text')); // string(0) ""
echo error_get_last()['message'], "\n"; // gzdecode(): data error
```

## Add your own algorithm

Implement `Algorithm` for a type. `content_encoding()` defaults to the name in lower case.

```
use utopia_compression::{Algorithm, Error};

/// Reverses the bytes: not compression, but an inverse pair.
struct Reverse;

impl Algorithm for Reverse {
    fn name(&self) -> &str {
        "Reverse"
    }
    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(data.iter().rev().copied().collect())
    }
    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        self.compress(data)
    }
    fn supported(&self) -> bool {
        true
    }
}

assert_eq!(Reverse.content_encoding(), "reverse");
assert_eq!(Reverse.decompress(&Reverse.compress(b"abc")?)?, b"abc");
# Ok::<(), Error>(())
```

```php
use Utopia\Compression\Compression;

class Reverse extends Compression
{
    public function getName(): string { return 'Reverse'; }
    public function compress(string $data): string { return strrev($data); }
    public function decompress(string $data): string { return strrev($data); }
    public static function isSupported(): bool { return true; }
}

echo (new Reverse())->getContentEncoding(), "\n"; // reverse
echo (new Reverse())->decompress((new Reverse())->compress('abc')), "\n"; // abc
```
