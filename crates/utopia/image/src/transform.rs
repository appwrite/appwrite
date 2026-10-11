//! Pixel work on one frame: resampling, cropping, rotating, borders, masks.
//!
//! Each function mirrors the ImageMagick call `Utopia\Image\Image` makes, at
//! the level that is observable: sizes, pages and alpha exactly, pixels up to
//! resampling differences.

use fast_image_resize::{FilterType, PixelType, ResizeAlg, ResizeOptions, Resizer, images};
use image::{Rgba, RgbaImage};

use crate::color::Color;
use crate::error::{Error, Result};
use crate::frame::{Format, Frame, Page};
use crate::options::check_dimensions;

/// Resamples `src` to `width` x `height` with `filter`.
fn resample(src: &RgbaImage, width: u32, height: u32, filter: FilterType, alpha: bool) -> Result<RgbaImage> {
    check_dimensions(width.into(), height.into())?;
    if width == 0 || height == 0 {
        return Err(Error::imagick("negative or zero image size `'"));
    }
    if (width, height) == src.dimensions() {
        return Ok(src.clone());
    }
    let view = images::ImageRef::new(src.width(), src.height(), src.as_raw(), PixelType::U8x4)
        .map_err(|e| Error::imagick(e.to_string()))?;
    let mut dst = images::Image::new(width, height, PixelType::U8x4);
    let options = ResizeOptions::new().resize_alg(ResizeAlg::Convolution(filter)).use_alpha(alpha);
    Resizer::new().resize(&view, &mut dst, &options).map_err(|e| Error::imagick(e.to_string()))?;
    RgbaImage::from_raw(width, height, dst.into_vec()).ok_or_else(|| Error::imagick("resize failed"))
}

/// `Imagick::thumbnailImage()`: a resize with ImageMagick's default filter
/// choice (Mitchell when enlarging, for palette images or with alpha,
/// Lanczos otherwise).
pub(crate) fn thumbnail(frame: &mut Frame, width: u32, height: u32) -> Result<()> {
    let enlarging = u64::from(width) * u64::from(height) > u64::from(frame.width()) * u64::from(frame.height());
    let filter = if enlarging || frame.alpha || frame.format == Format::Gif {
        FilterType::Mitchell
    } else {
        FilterType::Lanczos3
    };
    frame.pixels = resample(&frame.pixels, width, height, filter, frame.alpha)?;
    frame.page = Page::at_origin(width, height);
    Ok(())
}

/// `Imagick::scaleImage()`: an area average (box filter).
pub(crate) fn scale(frame: &mut Frame, width: u32, height: u32) -> Result<()> {
    frame.pixels = resample(&frame.pixels, width, height, FilterType::Box, frame.alpha)?;
    frame.page = Page::at_origin(width, height);
    Ok(())
}

/// `Imagick::cropImage()`: the rectangle clipped to the frame; a zero width or
/// height leaves the frame as it is (MagickCropImage returns early). The page
/// keeps the offset.
pub(crate) fn crop(frame: &mut Frame, width: u32, height: u32, x: i64, y: i64) {
    if width == 0 || height == 0 {
        return;
    }
    let (fw, fh) = (i64::from(frame.width()), i64::from(frame.height()));
    let x0 = x.clamp(0, fw);
    let y0 = y.clamp(0, fh);
    let x1 = (x + i64::from(width)).clamp(0, fw);
    let y1 = (y + i64::from(height)).clamp(0, fh);
    if x1 <= x0 || y1 <= y0 {
        frame.pixels = RgbaImage::new(1, 1);
        frame.alpha = true;
        return;
    }
    let cropped =
        image::imageops::crop_imm(&frame.pixels, x0 as u32, y0 as u32, (x1 - x0) as u32, (y1 - y0) as u32).to_image();
    frame.pixels = cropped;
    frame.page.x = x0;
    frame.page.y = y0;
}

/// Imagick's `cropThumbnailImage()`: scale to cover `width` x `height`, then
/// crop the centre.
pub(crate) fn crop_thumbnail(frame: &mut Frame, width: u32, height: u32) -> Result<()> {
    let (ow, oh) = (frame.width(), frame.height());
    if (ow, oh) == (width, height) {
        return Ok(());
    }
    let ratio_x = f64::from(width) / f64::from(ow);
    let ratio_y = f64::from(height) / f64::from(oh);
    let round = |v: f64| -> i64 { if v >= 0.0 { (v + 0.5).floor() as i64 } else { (v - 0.5).ceil() as i64 } };
    let (new_w, new_h) = if ratio_x > ratio_y {
        (i64::from(width), round(ratio_x * f64::from(oh)))
    } else {
        (round(ratio_y * f64::from(ow)), i64::from(height))
    };
    if new_w <= 0 || new_h <= 0 {
        return Err(Error::imagick("negative or zero image size `' @ error/resize.c/SampleImage/3956"));
    }
    thumbnail(frame, new_w as u32, new_h as u32)?;
    if new_w == i64::from(width) && new_h == i64::from(height) {
        return Ok(());
    }
    let (mut crop_x, mut crop_y) = (0, 0);
    if new_w > i64::from(width) {
        crop_x = (new_w - i64::from(width)) / 2;
    } else if new_h > i64::from(height) {
        crop_y = (new_h - i64::from(height)) / 2;
    }
    crop(frame, width, height, crop_x, crop_y);
    frame.page = Page::at_origin(width, height);
    Ok(())
}

/// `Imagick::borderImage($color, $width, $width)`.
pub(crate) fn border(frame: &mut Frame, width: u32, color: Color) -> Result<()> {
    let (w, h) = (u64::from(frame.width()) + 2 * u64::from(width), u64::from(frame.height()) + 2 * u64::from(width));
    check_dimensions(w, h)?;
    let mut canvas = RgbaImage::from_pixel(w as u32, h as u32, Rgba(color.0));
    for (x, y, px) in frame.pixels.enumerate_pixels() {
        let dst = canvas.get_pixel_mut(x + width, y + width);
        *dst = if frame.alpha { over(*px, *dst) } else { *px };
    }
    frame.pixels = canvas;
    frame.alpha = frame.alpha || color.has_alpha();
    // The virtual canvas grows by the border too, keeping its offset.
    let grow = |v: u32| v.saturating_add(width.saturating_mul(2));
    frame.page = Page { width: grow(frame.page.width), height: grow(frame.page.height), ..frame.page };
    Ok(())
}

/// Porter-Duff `src` over `dst` on straight RGBA.
fn over(src: Rgba<u8>, dst: Rgba<u8>) -> Rgba<u8> {
    let sa = f32::from(src[3]) / 255.0;
    let da = f32::from(dst[3]) / 255.0;
    let a = sa + da * (1.0 - sa);
    if a <= 0.0 {
        return Rgba([0, 0, 0, 0]);
    }
    let c = |i: usize| -> u8 {
        let v = (f32::from(src[i]) * sa + f32::from(dst[i]) * da * (1.0 - sa)) / a;
        v.round().clamp(0.0, 255.0) as u8
    };
    Rgba([c(0), c(1), c(2), (a * 255.0).round() as u8])
}

/// Coverage (0 or 1, as ImageMagick's mask comes out) of pixel (px, py) by the
/// rounded rectangle `roundRectangle(x0, y0, x1, y1, r, r)`: it spans the
/// pixels x0..=x1 and y0..=y1 (edges x0 to x1 + 1), sampled at pixel centres.
/// A zero radius draws nothing, as in ImageMagick.
fn coverage(px: u32, py: u32, x0: f64, y0: f64, x1: f64, y1: f64, r: f64) -> f32 {
    if r <= 0.0 || x1 < x0 || y1 < y0 {
        return 0.0;
    }
    let (x, y) = (f64::from(px) + 0.5, f64::from(py) + 0.5);
    if in_round_rect(x, y, x0, y0, x1 + 1.0, y1 + 1.0, r) { 1.0 } else { 0.0 }
}

fn in_round_rect(x: f64, y: f64, x0: f64, y0: f64, x1: f64, y1: f64, r: f64) -> bool {
    if x < x0 || x > x1 || y < y0 || y > y1 {
        return false;
    }
    let r = r.min((x1 - x0) / 2.0).min((y1 - y0) / 2.0).max(0.0);
    let cx = if x < x0 + r {
        x0 + r
    } else if x > x1 - r {
        x1 - r
    } else {
        return true;
    };
    let cy = if y < y0 + r {
        y0 + r
    } else if y > y1 - r {
        y1 - r
    } else {
        return true;
    };
    (x - cx).powi(2) + (y - cy).powi(2) <= r * r
}

/// The geometry of `Image::setBorderRadius()`'s rounded rectangle.
pub(crate) struct RoundRect {
    pub x0: f64,
    pub y0: f64,
    pub x1: f64,
    pub y1: f64,
    pub radius: f64,
}

/// Composites the rounded-rectangle mask (a `width` x `height` canvas) onto the
/// frame with `DstIn`: outside the shape, pixels turn transparent, or black
/// when the frame has no alpha channel.
pub(crate) fn mask(frame: &mut Frame, width: u32, height: u32, shape: &RoundRect) {
    let (w, h) = (width.min(frame.width()), height.min(frame.height()));
    for y in 0..h {
        for x in 0..w {
            let c = coverage(x, y, shape.x0, shape.y0, shape.x1, shape.y1, shape.radius);
            if c >= 1.0 {
                continue;
            }
            let px = frame.pixels.get_pixel_mut(x, y);
            if frame.alpha {
                px[3] = (f32::from(px[3]) * c).round() as u8;
            } else {
                for i in 0..3 {
                    px[i] = (f32::from(px[i]) * c).round() as u8;
                }
            }
        }
    }
}

/// The stroke of the rounded rectangle (`stroke_width` wide, centred on the
/// outline) on a transparent `width` x `height` canvas, with `frame` composited
/// over it: the frame `Image::setBorderRadius()` ends with when a border is set.
pub(crate) fn stroke(
    frame: &Frame,
    width: u32,
    height: u32,
    shape: &RoundRect,
    stroke_width: u32,
    color: Color,
) -> Frame {
    let half = f64::from(stroke_width) / 2.0;
    let mut canvas = RgbaImage::new(width.max(1), height.max(1));
    for (x, y, px) in canvas.enumerate_pixels_mut() {
        let outer =
            coverage(x, y, shape.x0 - half, shape.y0 - half, shape.x1 + half, shape.y1 + half, shape.radius + half);
        let inner = if shape.x1 - shape.x0 > 2.0 * half && shape.y1 - shape.y0 > 2.0 * half {
            coverage(
                x,
                y,
                shape.x0 + half,
                shape.y0 + half,
                shape.x1 - half,
                shape.y1 - half,
                (shape.radius - half).max(0.5),
            )
        } else {
            0.0
        };
        let c = (outer - inner).clamp(0.0, 1.0);
        if c > 0.0 {
            *px = Rgba([color.0[0], color.0[1], color.0[2], (f32::from(color.0[3]) * c).round() as u8]);
        }
    }
    for (x, y, src) in frame.pixels.enumerate_pixels() {
        if x >= canvas.width() || y >= canvas.height() {
            continue;
        }
        let dst = canvas.get_pixel_mut(x, y);
        *dst = if frame.alpha { over(*src, *dst) } else { *src };
    }
    let mut out = Frame::new(canvas, true, 0, Format::Png);
    out.page = Page::at_origin(width.max(1), height.max(1));
    out
}

/// `Imagick::setImageAlphaChannel(OPAQUE)` then `evaluateImage()` on alpha:
/// every pixel gets the same alpha, `opacity` of opaque (set to zero for
/// exactly `0.0`; `1 / opacity` as a divisor otherwise, clamped).
pub(crate) fn opacity(frame: &mut Frame, opacity: f64) {
    let quantum = if opacity == 0.0 {
        0.0
    } else {
        let divisor = 1.0 / opacity;
        let divisor = if divisor == 0.0 { 1.0 } else { divisor };
        65535.0 / divisor
    };
    let alpha = if quantum.is_nan() { 0.0 } else { (quantum / 257.0 + 0.5).floor().clamp(0.0, 255.0) };
    let alpha = alpha as u8;
    for px in frame.pixels.pixels_mut() {
        px[3] = alpha;
    }
    frame.alpha = true;
}

/// `Imagick::rotateImage('transparent', $degrees)`: quarter turns are exact;
/// any other angle is ImageMagick's best-fit distortion (the bounding box of
/// the rotated corners, with the page offset it ends at), sampled bilinearly
/// with transparency outside.
pub(crate) fn rotate(frame: &mut Frame, degrees: f64) -> Result<()> {
    let mut angle = degrees % 360.0;
    while angle < -45.0 {
        angle += 360.0;
    }
    let mut rotations = 0;
    while angle > 45.0 {
        angle -= 90.0;
        rotations += 1;
    }
    rotations %= 4;
    let shear_x = -(angle.to_radians() / 2.0).tan();
    let shear_y = angle.to_radians().sin();
    let page = frame.page;
    if shear_x.abs() < 1e-12 && shear_y.abs() < 1e-12 {
        frame.pixels = match rotations {
            1 => image::imageops::rotate90(&frame.pixels),
            2 => image::imageops::rotate180(&frame.pixels),
            3 => image::imageops::rotate270(&frame.pixels),
            _ => return Ok(()),
        };
        let (w, h) = (i64::from(frame.width()), i64::from(frame.height()));
        let (pw, ph) = (i64::from(page.width), i64::from(page.height));
        // ImageMagick's IntegralRotateImage turns the page with the pixels.
        frame.page = match rotations {
            1 => Page { width: page.height, height: page.width, x: ph - w - page.y, y: page.x },
            2 => Page { x: pw - w - page.x, y: ph - h - page.y, ..page },
            _ => Page { width: page.height, height: page.width, x: page.y, y: pw - h - page.x },
        };
        return Ok(());
    }
    let (w, h) = (f64::from(frame.width()), f64::from(frame.height()));
    let (sin, cos) = degrees.to_radians().sin_cos();
    let (cx, cy) = (w / 2.0, h / 2.0);
    let forward = |x: f64, y: f64| (cx + (x - cx) * cos - (y - cy) * sin, cy + (x - cx) * sin + (y - cy) * cos);
    let corners = [forward(0.0, 0.0), forward(w, 0.0), forward(0.0, h), forward(w, h)];
    let (mut min_x, mut min_y, mut max_x, mut max_y) = (f64::MAX, f64::MAX, f64::MIN, f64::MIN);
    for (x, y) in corners {
        min_x = min_x.min(x);
        min_y = min_y.min(y);
        max_x = max_x.max(x);
        max_y = max_y.max(y);
    }
    let gx = (min_x - 0.5).floor();
    let gy = (min_y - 0.5).floor();
    let out_w = (max_x - gx + 0.5).ceil();
    let out_h = (max_y - gy + 0.5).ceil();
    check_dimensions(out_w as u64, out_h as u64)?;
    let (out_w, out_h) = (out_w as u32, out_h as u32);
    let src = &frame.pixels;
    let mut out = RgbaImage::new(out_w, out_h);
    let fetch = |x: i64, y: i64| -> [f32; 4] {
        if x < 0 || y < 0 || x >= i64::from(src.width()) || y >= i64::from(src.height()) {
            return [0.0; 4];
        }
        let p = src.get_pixel(x as u32, y as u32);
        let a = if frame.alpha { f32::from(p[3]) / 255.0 } else { 1.0 };
        [f32::from(p[0]) * a, f32::from(p[1]) * a, f32::from(p[2]) * a, a]
    };
    for (ox, oy, px) in out.enumerate_pixels_mut() {
        let dx = f64::from(ox) + 0.5 + gx - cx;
        let dy = f64::from(oy) + 0.5 + gy - cy;
        let sx = cx + dx * cos + dy * sin - 0.5;
        let sy = cy - dx * sin + dy * cos - 0.5;
        let (fx, fy) = (sx.floor(), sy.floor());
        let (tx, ty) = ((sx - fx) as f32, (sy - fy) as f32);
        let (ix, iy) = (fx as i64, fy as i64);
        let mut acc = [0.0f32; 4];
        for (wx, wy, x, y) in [
            (1.0 - tx, 1.0 - ty, ix, iy),
            (tx, 1.0 - ty, ix + 1, iy),
            (1.0 - tx, ty, ix, iy + 1),
            (tx, ty, ix + 1, iy + 1),
        ] {
            let p = fetch(x, y);
            for i in 0..4 {
                acc[i] += p[i] * wx * wy;
            }
        }
        let a = acc[3];
        if a > 0.0 {
            *px = Rgba([
                (acc[0] / a).round().clamp(0.0, 255.0) as u8,
                (acc[1] / a).round().clamp(0.0, 255.0) as u8,
                (acc[2] / a).round().clamp(0.0, 255.0) as u8,
                (a * 255.0).round().clamp(0.0, 255.0) as u8,
            ]);
        }
    }
    // The distortion scales the virtual canvas by the output's growth and
    // shifts it by the best-fit offset.
    let scale = |out: u32, canvas: u32, size: u32| -> u32 {
        if canvas == 0 || size == 0 {
            return out;
        }
        let scaled = (f64::from(out) * f64::from(canvas) / f64::from(size) + 0.5).floor();
        if scaled >= f64::from(u32::MAX) { u32::MAX } else { scaled as u32 }
    };
    let (w0, h0) = (frame.width(), frame.height());
    frame.pixels = out;
    frame.alpha = true;
    frame.page = Page {
        width: scale(out_w, page.width, w0),
        height: scale(out_h, page.height, h0),
        x: gx as i64 + page.x,
        y: gy as i64 + page.y,
    };
    Ok(())
}

/// `mergeImageLayers(LAYERMETHOD_FLATTEN)`: every frame composited at its page
/// offset over a canvas of the first frame's page size, filled with the first
/// frame's background colour.
pub(crate) fn flatten(frames: &[Frame]) -> Result<Frame> {
    let first = &frames[0];
    let width = if first.page.width == 0 { first.width() } else { first.page.width };
    let height = if first.page.height == 0 { first.height() } else { first.page.height };
    check_dimensions(width.into(), height.into())?;
    let background = first.background;
    let mut canvas = RgbaImage::from_pixel(width, height, Rgba(background.0));
    for frame in frames {
        for (x, y, src) in frame.pixels.enumerate_pixels() {
            let (cx, cy) = (i64::from(x) + frame.page.x, i64::from(y) + frame.page.y);
            if cx < 0 || cy < 0 || cx >= i64::from(width) || cy >= i64::from(height) {
                continue;
            }
            let dst = canvas.get_pixel_mut(cx as u32, cy as u32);
            *dst = if frame.alpha { over(*src, *dst) } else { *src };
        }
    }
    let mut out = first.with_pixels(canvas);
    out.alpha = background.has_alpha();
    out.page = Page::at_origin(width, height);
    Ok(out)
}
