//! The values the operations take: gravities, focal points, output formats
//! and resource limits.

use std::str::FromStr;
use std::sync::atomic::{AtomicU64, Ordering};

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
/// assert_eq!("bmp".parse::<OutputFormat>().unwrap_err().to_string(), "Invalid output type given");
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
            _ => return Err(Error::Exception("Invalid output type given".into())),
        })
    }
}

/// A resource ImageMagick limits.
///
/// PHP: the `$type` of `Image::setResourceLimit()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Resource {
    /// `area`: pixels of one image held in memory.
    Area,
    /// `disk`: bytes of pixel cache on disk.
    Disk,
    /// `file`: open pixel cache files.
    File,
    /// `map`: bytes of memory-mapped pixel cache.
    Map,
    /// `memory`: bytes of pixel cache in memory.
    Memory,
    /// `thread`: worker threads.
    Thread,
}

impl Resource {
    /// The resource named like PHP's `$type` (`area`, `disk`, ...).
    pub fn from_name(name: &str) -> Option<Resource> {
        Some(match name {
            "area" => Resource::Area,
            "disk" => Resource::Disk,
            "file" => Resource::File,
            "map" => Resource::Map,
            "memory" => Resource::Memory,
            "thread" => Resource::Thread,
            _ => return None,
        })
    }

    fn slot(self) -> &'static AtomicU64 {
        static LIMITS: [AtomicU64; 6] = [
            AtomicU64::new(512_000_000),
            AtomicU64::new(4_294_967_296),
            AtomicU64::new(768),
            AtomicU64::new(536_870_912),
            AtomicU64::new(268_435_456),
            AtomicU64::new(1),
        ];
        &LIMITS[self as usize]
    }

    /// The current limit (ImageMagick's defaults in Appwrite's image until set).
    pub fn limit(self) -> u64 {
        self.slot().load(Ordering::Relaxed)
    }
}

/// Sets a process-wide resource limit.
///
/// Rust keeps every frame in memory and resizes on the calling thread, so the
/// limits are recorded for callers to read back (to bound concurrency, say)
/// rather than switching pixels to a disk cache as ImageMagick does. A negative
/// value is stored as zero.
///
/// PHP: `Image::setResourceLimit($type, $value)`.
///
/// ```
/// use utopia_image::{Resource, set_resource_limit};
///
/// set_resource_limit(Resource::Memory, 64 * 1024 * 1024);
/// assert_eq!(Resource::Memory.limit(), 64 * 1024 * 1024);
/// ```
///
/// ```php
/// use Utopia\Image\Image;
///
/// Image::setResourceLimit('memory', 64 * 1024 * 1024);
/// echo \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY), "\n";
/// ```
pub fn set_resource_limit(resource: Resource, value: i64) {
    resource.slot().store(value.max(0) as u64, Ordering::Relaxed);
}

/// The largest width or height of an image (ImageMagick's `width` and `height`
/// policy in Appwrite's image).
pub const MAX_DIMENSION: u64 = 50_000;

/// Fails like ImageMagick's pixel cache when a dimension passes the limit.
pub(crate) fn check_dimensions(width: u64, height: u64) -> Result<()> {
    if width > MAX_DIMENSION || height > MAX_DIMENSION {
        return Err(Error::imagick(format!(
            "width or height exceeds limit `' ({width}x{height}) > ({MAX_DIMENSION}x{MAX_DIMENSION}) @ error/cache.c/OpenPixelCache/3805"
        )));
    }
    Ok(())
}
