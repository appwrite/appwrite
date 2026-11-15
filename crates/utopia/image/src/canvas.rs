//! [`Canvas`]: an image and the work planned on it, done when it is encoded.

use std::io::Cursor;
use std::sync::Arc;

use crate::color::Color;
use crate::decode;
use crate::error::{Error, Result};
use crate::format::Format;
use crate::image::Image;
use crate::options::{FocalPoint, Gravity, Limits, OutputFormat, check_dimensions};

/// One preview: a crop, then opacity, a background, a border, rounded corners
/// and a rotation, in that order. A part left at its default is skipped.
///
/// PHP: `Utopia\Image\Transform`.
#[derive(Debug, Clone, PartialEq)]
pub struct Transform {
    /// The width to crop to; 0 follows the aspect ratio.
    pub width: u32,
    /// The height to crop to; 0 follows the aspect ratio.
    pub height: u32,
    /// Which part of the image a crop keeps.
    pub gravity: Gravity,
    /// A point to keep in view, which overrides `gravity`.
    pub focus: Option<FocalPoint>,
    /// From 0 (transparent) to 1 (unchanged).
    pub opacity: f64,
    /// A colour to flatten the image onto.
    pub background: Option<String>,
    /// A border's width in pixels and its colour.
    pub border: Option<(u32, String)>,
    /// The corner radius in pixels.
    pub radius: u32,
    /// Degrees clockwise.
    pub rotation: i64,
}

impl Default for Transform {
    fn default() -> Self {
        Transform {
            width: 0,
            height: 0,
            gravity: Gravity::Center,
            focus: None,
            opacity: 1.0,
            background: None,
            border: None,
            radius: 0,
            rotation: 0,
        }
    }
}

/// Something drawn onto a canvas.
#[derive(Debug, Clone)]
pub enum Drawing {
    /// SVG path data filled with a colour, scaled and then moved.
    Path {
        /// The path data, as in an SVG `d` attribute.
        data: String,
        /// The fill colour.
        color: String,
        /// How far right to move the scaled path.
        x: f64,
        /// How far down to move the scaled path.
        y: f64,
        /// The factor the path's own units are multiplied by.
        scale: f64,
    },
    /// A line of text, without kerning or shaping.
    Text {
        /// The characters to draw; one the font has no glyph for takes no space.
        text: String,
        /// TrueType or OpenType font data.
        font: Arc<[u8]>,
        /// Pixels an em.
        size: f64,
        /// The fill colour.
        color: String,
        /// The horizontal centre of the line.
        x: f64,
        /// The baseline.
        y: f64,
    },
}

#[derive(Debug, Clone)]
enum Source {
    Encoded(Arc<[u8]>),
    Blank { background: String },
}

#[derive(Debug, Clone)]
enum Step {
    Transform(Transform),
    Drawing(Drawing),
}

/// An image, and what has been asked of it so far.
///
/// A canvas is immutable: [`apply`](Canvas::apply) and [`draw`](Canvas::draw)
/// return a new one that shares the source bytes. Steps run in the order they
/// were added, each on what the one before it left. Nothing is decoded until
/// [`encode`](Canvas::encode), which decodes, runs every step and writes the
/// result in one pass. The size, format and orientation come from the header.
///
/// PHP: `Utopia\Image\Canvas`.
///
/// ```
/// use utopia_image::{Canvas, Limits, OutputFormat, Transform};
///
/// let png = std::fs::read("../../../packages/image/tests/resources/resize/100x100.png")?;
/// let canvas = Canvas::decode(&png, Limits::default())?;
/// assert_eq!((canvas.width(), canvas.height()), (100, 100));
/// let jpeg = canvas.apply(Transform { width: 50, height: 25, ..Transform::default() })?.encode(OutputFormat::Jpeg, Some(80))?;
/// assert_eq!(&jpeg[..3], &[0xFF, 0xD8, 0xFF]);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
#[derive(Debug, Clone)]
pub struct Canvas {
    source: Source,
    limits: Limits,
    steps: Vec<Step>,
    width: u32,
    height: u32,
    orientation: u32,
    format: Option<Format>,
}

/// The size a file's header declares, without decoding its pixels.
fn dimensions(data: &[u8], format: Format) -> Result<(u32, u32)> {
    let corrupt = |e: image::ImageError| Error::Corrupt(format!("{}: {e}", format.value()));
    let kind = match format {
        Format::Jpeg => image::ImageFormat::Jpeg,
        Format::Png => image::ImageFormat::Png,
        Format::Gif => image::ImageFormat::Gif,
        Format::Webp => image::ImageFormat::WebP,
        #[cfg(feature = "libheif")]
        Format::Avif | Format::Heic => return crate::heif::dimensions(data, format),
        #[cfg(not(feature = "libheif"))]
        Format::Avif | Format::Heic => return Err(Error::unavailable(format.value(), "input")),
    };
    image::ImageReader::with_format(Cursor::new(data), kind).into_dimensions().map_err(corrupt)
}

impl Canvas {
    /// Reads the header of a JPEG, PNG, GIF, WebP, AVIF or HEIC file.
    ///
    /// Fails here, before any pixel is decoded, for a format that is not one of
    /// `accept`, a header that cannot be read, or a size past `limits`. A file
    /// whose pixel data is damaged fails later, in [`encode`](Canvas::encode).
    ///
    /// PHP: `new Canvas($data, $limits)`.
    pub fn decode(data: &[u8], limits: Limits) -> Result<Canvas> {
        Canvas::decode_accepting(data, limits, &[])
    }

    /// As [`decode`](Canvas::decode), refusing any format not in `accept` (every format when empty).
    pub fn decode_accepting(data: &[u8], limits: Limits, accept: &[Format]) -> Result<Canvas> {
        let format = Format::detect(data)?;
        if !accept.is_empty() && !accept.contains(&format) {
            return Err(Error::UnsupportedFormat(format!("{} input is not accepted", format.value())));
        }
        let (width, height) = dimensions(data, format)?;
        limits.scope(|| check_dimensions(width.into(), height.into()))?;
        Ok(Canvas {
            source: Source::Encoded(data.into()),
            limits,
            steps: Vec::new(),
            width,
            height,
            orientation: decode::orientation(data).unwrap_or(1),
            format: Some(format),
        })
    }

    /// A canvas of one colour.
    ///
    /// PHP: `new Canvas(new Blank($width, $height, $background), $limits)`.
    pub fn blank(width: u32, height: u32, background: &str, limits: Limits) -> Result<Canvas> {
        // Builds and drops a one-pixel image to reject a bad size or colour now.
        limits.scope(|| {
            check_dimensions(width.into(), height.into())?;
            Image::blank(width.min(1), height.min(1), background).map(|_| ())
        })?;
        Ok(Canvas {
            source: Source::Blank { background: background.to_owned() },
            limits,
            steps: Vec::new(),
            width,
            height,
            orientation: 1,
            format: None,
        })
    }

    /// The width of the source, before any step.
    pub fn width(&self) -> u32 {
        self.width
    }

    /// The height of the source, before any step.
    pub fn height(&self) -> u32 {
        self.height
    }

    /// The EXIF orientation of the source, 1 to 8 (1 when it has none).
    pub fn orientation(&self) -> u32 {
        self.orientation
    }

    /// The format of the source; `None` for a blank canvas.
    pub fn format(&self) -> Option<Format> {
        self.format
    }

    /// A canvas with `transform` added to its plan. Fails for a colour that cannot be parsed,
    /// or a size past the canvas's limits.
    pub fn apply(&self, transform: Transform) -> Result<Canvas> {
        if u64::from(transform.width) > self.limits.width || u64::from(transform.height) > self.limits.height {
            return Err(Error::LimitExceeded);
        }
        let colors = [transform.background.as_deref(), transform.border.as_ref().map(|(_, color)| color.as_str())];
        for color in colors.into_iter().flatten() {
            Color::parse(color)?;
        }
        let mut canvas = self.clone();
        canvas.steps.push(Step::Transform(transform));
        Ok(canvas)
    }

    /// A canvas with `drawing` added to its plan. Fails for a colour, a path or a font that
    /// cannot be parsed.
    pub fn draw(&self, drawing: Drawing) -> Result<Canvas> {
        match &drawing {
            Drawing::Path { data, color, .. } => {
                crate::draw::validate(data)?;
                Color::parse(color)?;
            }
            Drawing::Text { font, color, .. } => {
                crate::draw::has_glyph(font, ' ')?;
                Color::parse(color)?;
            }
        }
        let mut canvas = self.clone();
        canvas.steps.push(Step::Drawing(drawing));
        Ok(canvas)
    }

    /// Decodes the source, runs the plan and writes `format`.
    ///
    /// `quality` is 0 to 100; `None` keeps a JPEG source's own quality and
    /// otherwise uses the format's default. Metadata is not written.
    pub fn encode(&self, format: OutputFormat, quality: Option<u8>) -> Result<Vec<u8>> {
        self.limits.scope(|| {
            let mut image = match &self.source {
                Source::Encoded(data) => Image::new(data)?,
                Source::Blank { background } => Image::blank(self.width, self.height, background)?,
            };
            for (index, step) in self.steps.iter().enumerate() {
                if index > 0 {
                    image.settle();
                }
                match step {
                    Step::Transform(transform) => run(&mut image, transform)?,
                    Step::Drawing(Drawing::Path { data, color, x, y, scale }) => {
                        image.fill_path(data, color, *x, *y, *scale)?;
                    }
                    Step::Drawing(Drawing::Text { text, font, size, color, x, y }) => {
                        image.draw_text(text, font, *size, color, *x, *y)?;
                    }
                }
            }
            image.output(format, quality.map_or(-1, i64::from))
        })
    }
}

fn run(image: &mut Image, transform: &Transform) -> Result<()> {
    let cropped = transform.width > 0 || transform.height > 0;
    if cropped || transform.gravity != Gravity::Center || transform.focus.is_some() {
        image.crop(transform.width, transform.height, transform.gravity, transform.focus)?;
    }
    if transform.opacity != 1.0 {
        image.set_opacity(transform.opacity);
    }
    if let Some(background) = &transform.background {
        image.set_background(background)?;
    }
    if let Some((width, color)) = &transform.border {
        image.set_border(*width, color)?;
    }
    if transform.radius > 0 {
        image.set_border_radius(transform.radius)?;
    }
    if transform.rotation != 0 {
        image.set_rotation(transform.rotation)?;
    }
    Ok(())
}
