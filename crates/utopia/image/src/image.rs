//! [`Image`]: a decoded image and the preview operations on it.

use std::path::Path;

use php_std::format::{RoundingMode, ceil, dval_to_lval, round};
use php_std::value::Number;

use crate::color::Color;
use crate::decode;
use crate::encode;
use crate::error::{Error, Result};
use crate::frame::{Format, Frame};
use crate::options::{FocalPoint, Gravity, OutputFormat};
use crate::transform::{self, RoundRect};

/// A decoded image (every frame of an animation), transformed in place and
/// encoded on [`output`](Image::output) or [`save`](Image::save).
///
/// Every method is CPU-bound and can take tens of milliseconds on a large
/// image: in async code, run the whole pipeline on the blocking pool
/// (`utopia_async::spawn_blocking`).
///
/// Like ImageMagick, an image tracks a *current frame*: the first after
/// decoding, the last after cropping an animation. Borders, rounded corners,
/// opacity, rotation and the output format apply to the current frame only,
/// and an animation is written in its first frame's format. These are kept
/// from PHP as quirks.
///
/// PHP: `Utopia\Image\Image`.
///
/// ```
/// use utopia_image::{Gravity, Image, OutputFormat};
///
/// let png = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
/// let mut image = Image::new(&png)?;
/// image.crop(50, 25, Gravity::Top, None)?.set_border(2, "#ff0000")?;
/// let jpeg = image.output(OutputFormat::Jpeg, 80)?;
/// assert_eq!(&jpeg[..3], &[0xFF, 0xD8, 0xFF]);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Image\Image;
///
/// $image = new Image(file_get_contents('/usr/src/code/packages/image/tests/resources/resize/100x100.png'));
/// $image->crop(50, 25, Image::GRAVITY_TOP)->setBorder(2, '#ff0000');
/// echo bin2hex(substr($image->output('jpg', 80), 0, 3)), "\n"; // ffd8ff
/// ```
#[derive(Debug, Clone)]
pub struct Image {
    frames: Vec<Frame>,
    current: usize,
    width: i64,
    height: i64,
    border_width: u32,
    border_color: String,
    rotation: i32,
    wand_quality: Option<i64>,
}

/// PHP's `/` on numbers: `DivisionByZeroError` for a zero divisor.
fn div(a: f64, b: f64) -> Result<f64> {
    if b == 0.0 { Err(Error::DivisionByZero) } else { Ok(a / b) }
}

/// PHP's `intval()` of a float.
fn intval(v: f64) -> i64 {
    dval_to_lval(v)
}

impl Image {
    /// Decodes a JPEG, PNG, GIF or WebP blob, every frame of an animation, and
    /// reads its EXIF orientation (3, 6 and 8 rotate the image when it is
    /// written; mirrored orientations are ignored).
    ///
    /// PHP: `new Image($data)`.
    pub fn new(data: &[u8]) -> Result<Image> {
        let frames = decode::decode(data)?;
        let rotation = match decode::orientation(data) {
            Some(3) => 180,
            Some(6) => 90,
            Some(8) => -90,
            _ => 0,
        };
        let (width, height) = (i64::from(frames[0].width()), i64::from(frames[0].height()));
        Ok(Image {
            frames,
            current: 0,
            width,
            height,
            border_width: 0,
            border_color: String::new(),
            rotation,
            wand_quality: None,
        })
    }

    /// The width the operations work from: the decoded width, then the last
    /// crop's (borders and rotations do not change it, as in PHP).
    pub fn width(&self) -> i64 {
        self.width
    }

    /// The height the operations work from (see [`width`](Image::width)).
    pub fn height(&self) -> i64 {
        self.height
    }

    /// The number of frames.
    pub fn frames(&self) -> usize {
        self.frames.len()
    }

    /// Crops to `width` x `height`.
    ///
    /// Without a focal point and with [`Gravity::Center`], the image is scaled
    /// to cover the target and its centre is kept (a thumbnail crop). With
    /// another gravity or a focal point, it is scaled to cover the target and
    /// the rectangle at that gravity, or centred on the point as far as the
    /// edges allow, is kept. A zero width or height follows the aspect ratio;
    /// both zero keep the size. Every frame of an animation is cropped.
    ///
    /// PHP: `crop($width, $height, $gravity, $x, $y)`.
    ///
    /// ```
    /// use utopia_image::{FocalPoint, Gravity, Image, OutputFormat};
    ///
    /// let png = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
    /// let mut image = Image::new(&png)?;
    /// image.crop(40, 20, Gravity::Center, Some(FocalPoint::new(0.25, 0.75)?))?;
    /// assert_eq!((image.width(), image.height()), (40, 20));
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Image\Image;
    ///
    /// $image = new Image(file_get_contents('/usr/src/code/packages/image/tests/resources/resize/100x100.png'));
    /// $image->crop(40, 20, Image::GRAVITY_CENTER, x: 0.25, y: 0.75);
    /// ```
    pub fn crop(&mut self, width: u32, height: u32, gravity: Gravity, focal: Option<FocalPoint>) -> Result<&mut Self> {
        let center = gravity == Gravity::Center && focal.is_none();
        let (mut width, mut height) = (i64::from(width), i64::from(height));
        if center && width != 0 && height != 0 && width == self.width && height == self.height {
            return Ok(self);
        }
        let original_aspect = div(self.width as f64, self.height as f64)?;
        if width == 0 {
            width = intval(height as f64 * original_aspect);
        }
        if height == 0 {
            height = intval(div(width as f64, original_aspect)?);
        }
        if height == 0 && width == 0 {
            height = self.height;
            width = self.width;
        }
        let (mut resize_width, mut resize_height) = (self.width, self.height);
        if !center {
            let target_aspect = div(width as f64, height as f64)?;
            if target_aspect > original_aspect {
                resize_width = width;
                resize_height = intval(ceil(Number::Float(div(width as f64, original_aspect)?)));
            } else {
                resize_width = intval(ceil(Number::Float(height as f64 * original_aspect)));
                resize_height = height;
            }
        }
        let (x, y) = crop_coordinates(width, height, resize_width, resize_height, gravity, focal);
        let dims = |v: i64| u32::try_from(v.max(0)).unwrap_or(u32::MAX);
        let (w, h, rw, rh) = (dims(width), dims(height), dims(resize_width), dims(resize_height));
        if self.frames.len() > 1 {
            for frame in &mut self.frames {
                if center {
                    transform::crop_thumbnail(frame, w, h)?;
                } else {
                    transform::scale(frame, rw, rh)?;
                    transform::crop(frame, w, h, x, y);
                    if w != 0 && h != 0 {
                        transform::thumbnail(frame, w, h)?;
                    }
                }
                frame.page = crate::frame::Page::at_origin(w, h);
            }
            self.current = self.frames.len() - 1;
        } else if center {
            transform::crop_thumbnail(&mut self.frames[0], w, h)?;
        } else {
            let frame = &mut self.frames[0];
            transform::scale(frame, rw, rh)?;
            transform::crop(frame, w, h, x, y);
        }
        self.width = width;
        self.height = height;
        Ok(self)
    }

    /// Adds a `width`-pixel border of `color` around the current frame
    /// (see [`Color`] for the strings accepted). The border is also what
    /// [`set_border_radius`](Image::set_border_radius) strokes.
    ///
    /// PHP: `setBorder($borderWidth, $borderColor)`. The width and colour are
    /// remembered before the colour is parsed, as in PHP.
    pub fn set_border(&mut self, width: u32, color: &str) -> Result<&mut Self> {
        self.border_width = width;
        self.border_color = color.to_owned();
        let color = Color::parse(color)?;
        transform::border(&mut self.frames[self.current], width, color)?;
        Ok(self)
    }

    /// Rounds the corners of the current frame with `radius`: outside the
    /// rounded rectangle pixels turn transparent, or black on an image without
    /// an alpha channel (a JPEG). With a border set, the rectangle is inset by
    /// it and stroked with its colour, and the image becomes that one frame.
    ///
    /// PHP: `setBorderRadius($cornerRadius)`.
    pub fn set_border_radius(&mut self, radius: u32) -> Result<&mut Self> {
        let (w, h) = (self.width, self.height);
        let bw = i64::from(self.border_width);
        let (rect_w, rect_h) = if bw > 0 { (w - (bw + 1), h - (bw + 1)) } else { (w - 1, h - 1) };
        if rect_w < bw || rect_h < bw {
            // ImageMagick refuses a rectangle whose end comes before its start.
            return Err(Error::imagick(
                "non-conforming drawing primitive definition `roundrectangle' @ error/draw.c/RenderMVGContent/4661",
            ));
        }
        let shape =
            RoundRect { x0: bw as f64, y0: bw as f64, x1: rect_w as f64, y1: rect_h as f64, radius: f64::from(radius) };
        let stroke = if bw > 0 { Some(Color::parse(&self.border_color)?) } else { None };
        let (mw, mh) = (u32::try_from(w.max(1)).unwrap_or(1), u32::try_from(h.max(1)).unwrap_or(1));
        transform::mask(&mut self.frames[self.current], mw, mh, &shape);
        if let Some(color) = stroke {
            let frame = transform::stroke(&self.frames[self.current], mw, mh, &shape, self.border_width, color);
            self.frames = vec![frame];
            self.current = 0;
        }
        Ok(self)
    }

    /// Sets the opacity of the current frame: every pixel's alpha becomes
    /// `opacity` of opaque (existing transparency is replaced). `1.0` changes
    /// nothing; values past `0..=1` clamp, NaN is transparent.
    ///
    /// PHP: `setOpacity($opacity)`.
    pub fn set_opacity(&mut self, opacity: f64) -> &mut Self {
        if opacity == 1.0 {
            return self;
        }
        transform::opacity(&mut self.frames[self.current], opacity);
        self
    }

    /// Rotates the current frame clockwise by `degrees`, filling with
    /// transparency; the canvas grows to fit.
    ///
    /// PHP: `setRotation($degree)`.
    pub fn set_rotation(&mut self, degrees: i64) -> Result<&mut Self> {
        if degrees == 0 {
            return Ok(self);
        }
        transform::rotate(&mut self.frames[self.current], degrees as f64)?;
        Ok(self)
    }

    /// Flattens every frame onto `color` (see [`Color`]), leaving one frame.
    ///
    /// PHP: `setBackground($color)`. The colour is set on the current frame
    /// but the flattening uses the first frame's, as in PHP.
    pub fn set_background(&mut self, color: &str) -> Result<&mut Self> {
        let color = Color::parse(color)?;
        self.frames[self.current].background = color;
        let flat = transform::flatten(&self.frames)?;
        self.frames = vec![flat];
        self.current = 0;
        Ok(self)
    }

    /// Encodes the image as `format` with `quality` (0–100; negative leaves
    /// the encoder's default), applying the pending EXIF rotation first (once).
    ///
    /// PHP: `output($type, $quality)`.
    ///
    /// ```
    /// use utopia_image::{Image, OutputFormat};
    ///
    /// let png = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
    /// let webp = Image::new(&png)?.output("webp".parse::<OutputFormat>()?, 75)?;
    /// assert_eq!(&webp[8..12], b"WEBP");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Image\Image;
    ///
    /// $image = new Image(file_get_contents('/usr/src/code/packages/image/tests/resources/resize/100x100.png'));
    /// echo substr($image->output('webp', 75), 8, 4), "\n"; // WEBP
    /// ```
    pub fn output(&mut self, format: OutputFormat, quality: i64) -> Result<Vec<u8>> {
        self.prepare(format, quality)?;
        let first = &self.frames[0];
        encode::encode(&self.frames, first.format, first.quality, self.wand_quality)
    }

    /// Writes the image to `path`, creating its directory (mode 0755) when
    /// missing. A recognised file extension (`.png`, `.jpg`, ...) decides the
    /// format over `format`, as ImageMagick does.
    ///
    /// PHP: `save($path, $type, $quality)`.
    pub fn save(&mut self, path: impl AsRef<Path>, format: OutputFormat, quality: i64) -> Result<()> {
        let path = path.as_ref();
        let text = path.to_string_lossy();
        let dir = php_std::path::dirname(&text);
        if !Path::new(&dir).exists() && (dir.is_empty() || create_dir(&dir).is_err()) {
            return Err(Error::Exception(format!("Can't create directory {dir}")));
        }
        self.prepare(format, quality)?;
        let first = &self.frames[0];
        let written = extension_format(path).unwrap_or(first.format);
        if self.frames.len() == 1 || encode::adjoins(written) {
            let data = encode::encode(&self.frames, written, first.quality, self.wand_quality)?;
            return encode::write(path, &data);
        }
        // A format without animations gets one file per frame, numbered as
        // ImageMagick numbers them (`name-0.png`, `name-1.png`, ...); a name
        // without an extension gets only the first frame (`name-0`).
        let (quality, wand_quality) = (first.quality, self.wand_quality);
        let stem = path.file_stem().map(|s| s.to_string_lossy().into_owned()).unwrap_or_default();
        let Some(ext) = path.extension().map(|e| e.to_string_lossy().into_owned()) else {
            let data = encode::encode(&self.frames[..1], written, quality, wand_quality)?;
            return encode::write(&path.with_file_name(format!("{stem}-0")), &data);
        };
        for (i, frame) in self.frames.iter().enumerate() {
            let data = encode::encode(std::slice::from_ref(frame), written, quality, wand_quality)?;
            encode::write(&path.with_file_name(format!("{stem}-{i}.{ext}")), &data)?;
        }
        Ok(())
    }

    /// The pending rotation, then the output settings on the current frame.
    fn prepare(&mut self, format: OutputFormat, quality: i64) -> Result<()> {
        if self.rotation != 0 {
            transform::rotate(&mut self.frames[self.current], f64::from(self.rotation))?;
            self.rotation = 0;
        }
        let frame = &mut self.frames[self.current];
        let set = quality >= 0;
        match format {
            OutputFormat::Jpeg => {
                if set {
                    frame.quality = Some(quality);
                }
                frame.format = Format::Jpeg;
            }
            OutputFormat::Gif => frame.format = Format::Gif,
            OutputFormat::Avif | OutputFormat::Heic => {
                frame.format = if format == OutputFormat::Avif { Format::Avif } else { Format::Heic };
                if set {
                    self.wand_quality = Some(if format == OutputFormat::Avif { quality.min(99) } else { quality });
                }
            }
            OutputFormat::Webp => {
                if set {
                    frame.quality = Some(quality);
                }
                frame.format = Format::Webp;
            }
            OutputFormat::Png => {
                if set {
                    let scaled = round(Number::Float(quality as f64 / 100.0 * 9.0), 0, RoundingMode::HalfAwayFromZero);
                    frame.quality = Some(intval(9.0 - scaled));
                }
                frame.format = Format::Png;
            }
        }
        Ok(())
    }

    /// The width that keeps the aspect ratio at `height`.
    ///
    /// PHP: `getSizeByFixedHeight($newHeight)` (protected).
    pub fn size_by_fixed_height(&self, height: i64) -> Result<i64> {
        let ratio = div(self.width as f64, self.height as f64)?;
        Ok(intval(height as f64 * ratio))
    }

    /// The height that keeps the aspect ratio at `width`.
    ///
    /// PHP: `getSizeByFixedWidth($newWidth)` (protected).
    pub fn size_by_fixed_width(&self, width: i64) -> Result<i64> {
        let ratio = div(self.height as f64, self.width as f64)?;
        Ok(intval(width as f64 * ratio))
    }
}

/// `getCropCoordinates()`: the top-left corner of the crop in the scaled image.
fn crop_coordinates(
    width: i64,
    height: i64,
    resize_width: i64,
    resize_height: i64,
    gravity: Gravity,
    focal: Option<FocalPoint>,
) -> (i64, i64) {
    let (w, h, rw, rh) = (width as f64, height as f64, resize_width as f64, resize_height as f64);
    if let Some(p) = focal {
        let x = (rw - w).min(p.x() * rw - w / 2.0).max(0.0);
        let y = (rh - h).min(p.y() * rh - h / 2.0).max(0.0);
        return (intval(x), intval(y));
    }
    let mid_x = intval((rw - w) / 2.0);
    let mid_y = intval((rh - h) / 2.0);
    let (right, bottom) = (resize_width - width, resize_height - height);
    match gravity {
        Gravity::TopLeft => (0, 0),
        Gravity::Top => (mid_x, 0),
        Gravity::TopRight => (right, 0),
        Gravity::Left => (0, mid_y),
        Gravity::Right => (right, mid_y),
        Gravity::BottomLeft => (0, bottom),
        Gravity::Bottom => (mid_x, bottom),
        Gravity::BottomRight => (right, bottom),
        Gravity::Center => (mid_x, mid_y),
    }
}

/// The format ImageMagick infers from a file name's extension.
fn extension_format(path: &Path) -> Option<Format> {
    let ext = path.extension()?.to_str()?.to_ascii_lowercase();
    Some(match ext.as_str() {
        "jpg" | "jpeg" | "jpe" => Format::Jpeg,
        "png" => Format::Png,
        "gif" => Format::Gif,
        "webp" => Format::Webp,
        "avif" => Format::Avif,
        "heic" | "heif" => Format::Heic,
        _ => return None,
    })
}

fn create_dir(dir: &str) -> std::io::Result<()> {
    let mut builder = std::fs::DirBuilder::new();
    builder.recursive(true);
    #[cfg(unix)]
    std::os::unix::fs::DirBuilderExt::mode(&mut builder, 0o755);
    builder.create(dir)
}
