//! One frame of an image, with what ImageMagick tracks per frame.

use image::RgbaImage;

use crate::color::Color;

/// The format a frame was read in or last set to.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Format {
    Jpeg,
    Png,
    Gif,
    Webp,
    Avif,
    Heic,
}

impl Format {
    /// ImageMagick's name for the format.
    pub(crate) fn magick(self) -> &'static str {
        match self {
            Format::Jpeg => "JPEG",
            Format::Png => "PNG",
            Format::Gif => "GIF",
            Format::Webp => "WEBP",
            Format::Avif => "AVIF",
            Format::Heic => "HEIC",
        }
    }
}

/// The virtual canvas a frame sits on: its size and the frame's offset.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Page {
    pub width: u32,
    pub height: u32,
    pub x: i64,
    pub y: i64,
}

impl Page {
    pub(crate) fn at_origin(width: u32, height: u32) -> Self {
        Page { width, height, x: 0, y: 0 }
    }
}

/// A frame: straight (not premultiplied) RGBA pixels, whether it has an alpha
/// channel (ImageMagick's alpha trait: without one, compositing a mask darkens
/// instead of making pixels transparent), its delay in centiseconds, page,
/// format and background colour.
#[derive(Debug, Clone)]
pub(crate) struct Frame {
    pub pixels: RgbaImage,
    pub alpha: bool,
    pub delay: u32,
    pub page: Page,
    pub format: Format,
    pub background: Color,
    /// `setImageCompressionQuality()`, as ImageMagick stores it.
    pub quality: Option<i64>,
}

impl Frame {
    pub(crate) fn new(pixels: RgbaImage, alpha: bool, delay: u32, format: Format) -> Self {
        let page = Page::at_origin(pixels.width(), pixels.height());
        Frame { pixels, alpha, delay, page, format, background: Color::WHITE, quality: None }
    }

    pub(crate) fn width(&self) -> u32 {
        self.pixels.width()
    }

    pub(crate) fn height(&self) -> u32 {
        self.pixels.height()
    }

    /// Replaces the pixels, keeping everything else.
    pub(crate) fn with_pixels(&self, pixels: RgbaImage) -> Self {
        Frame { pixels, ..self.clone_meta() }
    }

    fn clone_meta(&self) -> Frame {
        Frame {
            pixels: RgbaImage::new(0, 0),
            alpha: self.alpha,
            delay: self.delay,
            page: self.page,
            format: self.format,
            background: self.background,
            quality: self.quality,
        }
    }
}
