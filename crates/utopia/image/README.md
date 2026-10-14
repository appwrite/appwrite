# Utopia Image

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/image`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/image) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Image previews: decode, crop by gravity or focal point, borders, rounded corners, opacity, rotation and background, then encode to JPEG, PNG, GIF, WebP or AVIF.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-image = { git = "https://github.com/utopia-rs/image" }
```

`utopia-image` turns an uploaded image into a preview: decode it, crop it, decorate it, and encode it in the format the client asked for. Everything runs in memory on the calling thread; in async code, run it on the blocking pool.

## Make a thumbnail

Decode the blob, crop it to the target size (the centre is kept, scaled to cover), and encode it.

```rust
use utopia_image::{Gravity, Image, OutputFormat};

let blob = std::fs::read("../../../packages/image/tests/resources/disk-a/kitten-1.jpg")?;
let mut image = Image::new(&blob)?;
image.crop(400, 400, Gravity::Center, None)?;
let preview = image.output(OutputFormat::Jpeg, 80)?;
assert_eq!(&preview[..2], &[0xFF, 0xD8]);
```

## Keep a point of interest in view

A gravity keeps an edge or a corner; a focal point (fractions of the width and height) keeps a spot as centred as the edges allow. A request that carries the coordinates separately can check them with `FocalPoint::from_parts`.

```rust
use utopia_image::{FocalPoint, Gravity, Image, OutputFormat};

let blob = std::fs::read("../../../packages/image/tests/resources/disk-a/kitten-1.jpg")?;
let mut image = Image::new(&blob)?;
let focal = FocalPoint::from_parts(Some(0.3), Some(0.2))?;
image.crop(300, 100, Gravity::Center, focal)?;
let webp = image.output(OutputFormat::Webp, 75)?;
assert_eq!(&webp[8..12], b"WEBP");
```

## Decorate it

Borders, rounded corners, opacity, rotation and a background colour chain like the PHP setters. Colours are ImageMagick colour strings.

```rust
use utopia_image::{Image, OutputFormat};

let blob = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
let mut image = Image::new(&blob)?;
image.set_opacity(0.5).set_rotation(90)?.set_background("#ffffff")?;
let png = image.output(OutputFormat::Png, 100)?;
assert_eq!(&png[1..4], b"PNG");
```

## Save it to disk

`save` creates the directory when it is missing; a known file extension decides the format.

```rust
use utopia_image::{Image, OutputFormat};

let blob = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
let dir = std::env::temp_dir().join("utopia-image-guide");
Image::new(&blob)?.save(dir.join("preview.gif"), OutputFormat::Gif, 75)?;
assert!(dir.join("preview.gif").exists());
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
