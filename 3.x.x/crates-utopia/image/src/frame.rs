//! Internal animation frame storage.

use std::sync::Arc;

use image::{RgbImage, Rgba, RgbaImage};

/// Pixel buffer: RGB until an alpha-aware op promotes to RGBA.
#[derive(Clone, Debug)]
pub enum Raster {
    Rgba(Arc<RgbaImage>),
    Rgb(Arc<RgbImage>),
}

impl Raster {
    pub fn from_rgba(image: RgbaImage) -> Self {
        Self::Rgba(Arc::new(image))
    }

    pub fn from_rgb(image: RgbImage) -> Self {
        Self::Rgb(Arc::new(image))
    }

    pub fn width(&self) -> u32 {
        match self {
            Self::Rgba(img) => img.width(),
            Self::Rgb(img) => img.width(),
        }
    }

    pub fn height(&self) -> u32 {
        match self {
            Self::Rgba(img) => img.height(),
            Self::Rgb(img) => img.height(),
        }
    }

    pub fn is_rgb(&self) -> bool {
        matches!(self, Self::Rgb(_))
    }

    /// Borrow RGBA, converting from RGB when needed (no cache into self).
    pub fn to_rgba_image(&self) -> RgbaImage {
        match self {
            Self::Rgba(img) => img.as_ref().clone(),
            Self::Rgb(img) => rgb_to_rgba(img.as_ref()),
        }
    }

    pub fn as_rgba_arc(&self) -> Arc<RgbaImage> {
        match self {
            Self::Rgba(img) => Arc::clone(img),
            Self::Rgb(img) => Arc::new(rgb_to_rgba(img.as_ref())),
        }
    }

    /// Ensure RGBA storage for in-place alpha / mask ops.
    pub fn promote_rgba(&mut self) {
        if let Self::Rgb(rgb) = self {
            *self = Self::Rgba(Arc::new(rgb_to_rgba(rgb.as_ref())));
        }
    }

    pub fn make_rgba_mut(&mut self) -> &mut RgbaImage {
        self.promote_rgba();
        match self {
            Self::Rgba(img) => Arc::make_mut(img),
            Self::Rgb(_) => unreachable!("promote_rgba"),
        }
    }
}

fn rgb_to_rgba(rgb: &RgbImage) -> RgbaImage {
    let (w, h) = rgb.dimensions();
    let mut rgba = Vec::with_capacity((w * h * 4) as usize);
    for px in rgb.as_raw().chunks_exact(3) {
        rgba.extend_from_slice(&[px[0], px[1], px[2], 255]);
    }
    RgbaImage::from_raw(w, h, rgba)
        .unwrap_or_else(|| RgbaImage::from_pixel(w, h, Rgba([0, 0, 0, 0])))
}

/// One coalesced raster frame plus playback delay.
#[derive(Clone, Debug)]
pub struct Frame {
    pub raster: Raster,
    /// Frame delay in milliseconds.
    pub delay_ms: u32,
}

impl Frame {
    pub fn still(image: RgbaImage) -> Self {
        Self {
            raster: Raster::from_rgba(image),
            delay_ms: 0,
        }
    }

    pub fn still_rgb(image: RgbImage) -> Self {
        Self {
            raster: Raster::from_rgb(image),
            delay_ms: 0,
        }
    }
}
