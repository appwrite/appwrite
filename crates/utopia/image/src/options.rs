//! The values the operations take: gravities, focal points, output formats
//! and resource limits.

use std::str::FromStr;

use crate::error::{Error, Result};

/// Which part of the image a crop keeps.
///
/// PHP: the `Image::GRAVITY_*` string constants.
///
/// ```
/// use utopia_image::Gravity;
///
/// assert_eq!(Gravity::from_name("top-left"), Some(Gravity::TopLeft));
/// assert_eq!(Gravity::TopLeft.name(), "top-left");
/// assert_eq!(Gravity::ALL.len(), 9);
/// ```
///
/// ```php
/// use Utopia\Image\Image;
///
/// echo Image::GRAVITY_TOP_LEFT, "\n"; // top-left
/// echo count(Image::getGravityTypes()), "\n"; // 9
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub enum Gravity {
    /// `center`: a centred thumbnail crop.
    #[default]
    Center,
    /// `top-left`
    TopLeft,
    /// `top`
    Top,
    /// `top-right`
    TopRight,
    /// `left`
    Left,
    /// `right`
    Right,
    /// `bottom-left`
    BottomLeft,
    /// `bottom`
    Bottom,
    /// `bottom-right`
    BottomRight,
}

impl Gravity {
    /// Every gravity, in the order of `Image::getGravityTypes()`.
    ///
    /// PHP: `Image::getGravityTypes()` returns their names.
    pub const ALL: [Gravity; 9] = [
        Gravity::Center,
        Gravity::TopLeft,
        Gravity::Top,
        Gravity::TopRight,
        Gravity::Left,
        Gravity::Right,
        Gravity::BottomLeft,
        Gravity::Bottom,
        Gravity::BottomRight,
    ];

    /// The PHP constant's value (`top-left`).
    pub const fn name(self) -> &'static str {
        match self {
            Gravity::Center => "center",
            Gravity::TopLeft => "top-left",
            Gravity::Top => "top",
            Gravity::TopRight => "top-right",
            Gravity::Left => "left",
            Gravity::Right => "right",
            Gravity::BottomLeft => "bottom-left",
            Gravity::Bottom => "bottom",
            Gravity::BottomRight => "bottom-right",
        }
    }

    /// The gravity named `name`, if there is one.
    pub fn from_name(name: &str) -> Option<Gravity> {
        Gravity::ALL.into_iter().find(|g| g.name() == name)
    }
}

/// A focal point: the spot a crop keeps in view, as fractions of the width
/// and height (`0.0..=1.0`).
///
/// PHP: the `$x` and `$y` arguments of `crop()`.
///
/// ```
/// use utopia_image::FocalPoint;
///
/// let point = FocalPoint::new(0.75, 0.5)?;
/// assert_eq!((point.x(), point.y()), (0.75, 0.5));
/// assert!(FocalPoint::new(1.1, 0.5).is_err());
/// assert!(FocalPoint::from_parts(Some(0.5), None).is_err());
/// assert_eq!(FocalPoint::from_parts(None, None)?, None);
/// # Ok::<(), utopia_image::Error>(())
/// ```
///
/// ```php
/// use Utopia\Image\Image;
///
/// $source = new \Imagick();
/// $source->newImage(6, 2, 'red', 'png');
/// $image = new Image($source->getImageBlob());
/// $image->crop(2, 2, x: 0.75, y: 0.5);
/// try {
///     $image->crop(2, 2, x: 1.1, y: 0.5);
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n";
/// }
/// ```
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct FocalPoint {
    x: f64,
    y: f64,
}

impl FocalPoint {
    /// A focal point, refused unless both coordinates are finite and within
    /// `0.0..=1.0`.
    pub fn new(x: f64, y: f64) -> Result<Self> {
        if !x.is_finite() || !y.is_finite() || !(0.0..=1.0).contains(&x) || !(0.0..=1.0).contains(&y) {
            return Err(Error::InvalidArgument("Focal point coordinates must be finite and between 0 and 1".into()));
        }
        Ok(FocalPoint { x, y })
    }

    /// A focal point from two optional coordinates, as a request carries them:
    /// none for neither, an error for only one.
    pub fn from_parts(x: Option<f64>, y: Option<f64>) -> Result<Option<Self>> {
        match (x, y) {
            (None, None) => Ok(None),
            (Some(x), Some(y)) => FocalPoint::new(x, y).map(Some),
            _ => Err(Error::InvalidArgument("Both focal point coordinates are required".into())),
        }
    }

    /// The horizontal coordinate.
    pub const fn x(self) -> f64 {
        self.x
    }

    /// The vertical coordinate.
    pub const fn y(self) -> f64 {
        self.y
    }
}

/// An output format.
///
/// PHP: the `$type` string of `output()` and `save()`; `jpg` and `jpeg` are
/// the same format.
///
/// ```
/// use utopia_image::OutputFormat;
///
/// assert_eq!("jpeg".parse::<OutputFormat>()?, OutputFormat::Jpeg);
/// assert_eq!("bmp".parse::<OutputFormat>().unwrap_err().to_string(), "Unsupported output format: bmp");
/// # Ok::<(), utopia_image::Error>(())
/// ```
///
/// ```php
/// use Utopia\Image\Image;
///
/// $source = new \Imagick();
/// $source->newImage(2, 2, 'red', 'png');
/// try {
///     (new Image($source->getImageBlob()))->output('bmp');
/// } catch (\Exception $e) {
///     echo $e->getMessage(), "\n"; // Invalid output type given
/// }
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum OutputFormat {
    /// `jpg` or `jpeg`
    Jpeg,
    /// `gif`, every frame
    Gif,
    /// `png`, the first frame
    Png,
    /// `webp`, every frame
    Webp,
    /// `avif`, the first frame
    Avif,
    /// `heic`: refused, as no HEVC encoder is available (see the crate docs)
    Heic,
}

impl FromStr for OutputFormat {
    type Err = Error;

    fn from_str(s: &str) -> Result<Self> {
        Ok(match s {
            "jpg" | "jpeg" => OutputFormat::Jpeg,
            "gif" => OutputFormat::Gif,
            "png" => OutputFormat::Png,
            "webp" => OutputFormat::Webp,
            "avif" => OutputFormat::Avif,
            "heic" => OutputFormat::Heic,
            _ => return Err(Error::UnsupportedFormat(format!("Unsupported output format: {s}"))),
        })
    }
}

/// What one image may cost: its largest width and height in pixels, and how
/// many bytes of decoded pixels it may hold at once.
///
/// The defaults are conservative (16,000 pixels a side, 512 MiB of pixels); a
/// caller that wants more says so. There is no "unlimited".
///
/// PHP: `Utopia\Image\Limits`.
///
/// ```
/// use utopia_image::Limits;
///
/// let limits = Limits { width: 4_000, ..Limits::default() };
/// assert_eq!(limits.height, 16_000);
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Limits {
    /// The largest width of any image held, decoded or produced.
    pub width: u64,
    /// The largest height of any image held, decoded or produced.
    pub height: u64,
    /// Bytes of decoded pixels held at once (four a pixel, every frame).
    pub memory: u64,
}

impl Default for Limits {
    fn default() -> Self {
        Limits { width: 16_000, height: 16_000, memory: 512 * 1024 * 1024 }
    }
}

thread_local! {
    /// The limits of the canvas being worked on. Every operation runs to
    /// completion on the calling thread, so the decoders and transforms read
    /// them from here rather than each taking them as an argument.
    static ACTIVE: std::cell::Cell<Limits> = std::cell::Cell::new(Limits::default());
}

impl Limits {
    /// Runs `work` under these limits, restoring the previous ones after.
    pub(crate) fn scope<T>(self, work: impl FnOnce() -> T) -> T {
        let previous = ACTIVE.replace(self);
        let result = work();
        ACTIVE.set(previous);
        result
    }

    fn active() -> Limits {
        ACTIVE.get()
    }
}

/// Bytes of RGBA pixels held for one canvas of `width` x `height`.
pub(crate) fn canvas_bytes(width: u64, height: u64) -> u64 {
    width.saturating_mul(height).saturating_mul(4)
}

/// How many bytes of pixels the image being worked on may hold.
pub(crate) fn pixel_budget() -> u64 {
    Limits::active().memory
}

/// Fails when holding `bytes` more of pixels would pass [`pixel_budget`].
/// Called with the size a decoder announces, before its buffers are allocated.
pub(crate) fn check_pixels(bytes: u64) -> Result<()> {
    if bytes > pixel_budget() {
        return Err(Error::LimitExceeded);
    }
    Ok(())
}

/// Fails when a dimension passes its limit, or when one canvas of this size
/// would pass the pixel budget.
pub(crate) fn check_dimensions(width: u64, height: u64) -> Result<()> {
    let limits = Limits::active();
    if width > limits.width || height > limits.height {
        return Err(Error::LimitExceeded);
    }
    check_pixels(canvas_bytes(width, height))
}
