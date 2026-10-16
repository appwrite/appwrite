# utopia-image

Image manipulation library - Rust port of [`utopia-php/image`](https://github.com/utopia-php/image).

Uses SIMD-accelerated [`fast_image_resize`](https://crates.io/crates/fast_image_resize) for cover-crops (zero-copy `ImageRef`, box filter on large downs scales, thread-local `Resizer`), `Arc` frame buffers for cheap clone-on-write, libwebp for WebP (including animated hold-frame preservation), and system libheif for HEIC.

## Install

```toml
utopia-image = { path = "../utopia-image" } # workspace
```

System packages for default features: `libwebp-dev`, `libturbojpeg0-dev`, `libheif-dev`, HEVC encode (`libheif-plugin-x265`), HEVC decode (`libheif-plugin-libde265`), AV1 encode (`libheif-plugin-aomenc`, `libheif-plugin-svtenc` for large stills), and `nasm` (rav1e assembly fallback for AVIF).

Opaque stills (JPEG/PNG/WebP without alpha) stay in RGB until an alpha-aware op promotes to RGBA. Format sniffing dispatches JPEG/PNG/GIF/WebP/HEIC before the generic loader.

## Features

| Feature | Default | Notes |
|---------|---------|-------|
| `jpeg` | ✓ | Decode/encode; turbojpeg when `jpeg-turbo` is on |
| `jpeg-turbo` | ✓ | Fast JPEG encode + decode with system libjpeg-turbo |
| `png` | ✓ | Decode/encode via `image` (Fast/NoFilter for latency) |
| `gif` | ✓ | Still + animated (decoder-coalesced; streamed encode) |
| `webp` | ✓ | Still (`method=0`) + animated (`method=1`) via `libwebp` |
| `avif` | ✓ | libheif AOM for thumbs / SVT-AV1 ≥512² when `heic` is on; else ravif |
| `heic` | ✓ | Decode/encode via `libheif` (HEVC / x265); also enables fast AVIF |

## Quickstart

```rust
use utopia_image::prelude::*;

fn main() -> Result<()> {
    let bytes = std::fs::read("photo.jpg")?;
    let mut image = Image::new(&bytes)?;
    image.crop(100, 100, GRAVITY_TOP_LEFT)?;
    image.set_border(2, "#ff0000")?;
    image.save_path("photo_100x100.jpg", "jpg", 90)?;
    Ok(())
}
```

## Prelude

```rust
use utopia_image::prelude::*;
```

Re-exports: `Image`, `ImageError`, `Result`, and all `GRAVITY_*` constants.

## API Reference

### Gravity constants

```rust
pub const GRAVITY_CENTER: &str = "center";
pub const GRAVITY_TOP_LEFT: &str = "top-left";
pub const GRAVITY_TOP: &str = "top";
pub const GRAVITY_TOP_RIGHT: &str = "top-right";
pub const GRAVITY_LEFT: &str = "left";
pub const GRAVITY_RIGHT: &str = "right";
pub const GRAVITY_BOTTOM_LEFT: &str = "bottom-left";
pub const GRAVITY_BOTTOM: &str = "bottom";
pub const GRAVITY_BOTTOM_RIGHT: &str = "bottom-right";
```

### `Image`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(data: &[u8]) -> Result<Self>` | Decode blob; GIF/WebP animations are coalesced; first-frame size used |
| `get_gravity_types` | `fn get_gravity_types() -> &'static [&'static str]` | All gravity strings |
| `width` / `height` | `fn width(&self) -> u32` | Current dimensions |
| `frame_count` | `fn frame_count(&self) -> usize` | Animation length (`1` for stills) |
| `crop` | `fn crop(&mut self, w, h, gravity) -> Result<&mut Self>` | Cover-crop; `0` preserves aspect |
| `set_border` | `fn set_border(&mut self, width, color) -> Result<&mut Self>` | Solid border (`#RRGGBB` / names) |
| `set_border_radius` | `fn set_border_radius(&mut self, radius) -> Result<&mut Self>` | Rounded-corner DSTIN mask |
| `set_opacity` | `fn set_opacity(&mut self, opacity) -> Result<&mut Self>` | Multiply alpha (`0.0`–`1.0`) |
| `set_rotation` | `fn set_rotation(&mut self, degree) -> Result<&mut Self>` | Rotate with transparent fill |
| `set_background` | `fn set_background(&mut self, color) -> Result<&mut Self>` | Flatten transparency |
| `output` | `fn output(&mut self, type, quality) -> Result<Vec<u8>>` | Encode to bytes |
| `save` | `fn save(&mut self, path, type, quality) -> Result<Option<Vec<u8>>>` | Write file or return bytes when `path` is `None` |
| `save_path` | `fn save_path(&mut self, path, type, quality) -> Result<()>` | Path convenience |
| `set_resource_limit` | `fn set_resource_limit(type, value)` | Imagick-style `area` / `memory` / … knobs |

EXIF orientation `3` / `6` / `8` is applied once on the first `save`/`output` (mirrors ignored), matching PHP.

### Output types

`jpg` / `jpeg`, `png`, `gif`, `webp`, `avif`, `heic`. Unknown → `ImageError::InvalidType`.

PNG quality `0–100` maps to compression level `9–0` (inverted). AVIF quality is capped at `99`. HEIC quality uses libheif lossy `0–100`.

### `ImageError`

```rust
pub enum ImageError {
    Decode(String),
    Encode(String),
    InvalidType,
    Unsupported(&'static str),
    ResourceLimit(&'static str),
    InvalidColor(String),
    Io(std::io::Error),
    Message(String),
}
```

## Tests

```bash
cargo test -p utopia-image
```

## Benchmarks

```bash
cargo bench -p utopia-image
./benchmarks/run.sh   # includes PHP Imagick twin
```

## Code quality

Inherits workspace linting (`rustfmt`, Clippy, rustc lints, rustdoc, cargo-deny).
