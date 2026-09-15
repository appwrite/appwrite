//! Image manipulation - Rust port of [`utopia-php/image`](https://github.com/utopia-php/image).
//!
//! Provides crop-with-gravity, borders, rounded corners, opacity, rotation,
//! background flatten, and encode/save for JPEG, PNG, GIF, WebP, AVIF, and HEIC
//! (HEIC via system libheif + HEVC encoder, enabled by the `heic` feature).

mod color;
mod encode;
mod error;
mod frame;
mod gravity;
mod image;
mod limits;

pub use error::{ImageError, Result};
pub use gravity::{
    GRAVITY_BOTTOM, GRAVITY_BOTTOM_LEFT, GRAVITY_BOTTOM_RIGHT, GRAVITY_CENTER, GRAVITY_LEFT,
    GRAVITY_RIGHT, GRAVITY_TOP, GRAVITY_TOP_LEFT, GRAVITY_TOP_RIGHT,
};
pub use image::Image;

/// Prelude for common image types and gravity constants.
pub mod prelude {
    pub use crate::{
        Image, ImageError, Result, GRAVITY_BOTTOM, GRAVITY_BOTTOM_LEFT, GRAVITY_BOTTOM_RIGHT,
        GRAVITY_CENTER, GRAVITY_LEFT, GRAVITY_RIGHT, GRAVITY_TOP, GRAVITY_TOP_LEFT,
        GRAVITY_TOP_RIGHT,
    };
}
