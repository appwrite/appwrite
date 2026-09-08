//! Core [`Image`] type - Rust port of `Utopia\Image\Image`.

use std::cell::RefCell;
use std::f32::consts::PI;
use std::io::Cursor;
use std::path::Path;

use exif::{Exif, In, Reader as ExifReader, Tag};
use fast_image_resize::images::{Image as FirImage, ImageRef};
use fast_image_resize::{FilterType, PixelType, ResizeAlg, ResizeOptions, Resizer};
use image::{RgbImage, Rgba, RgbaImage};

use crate::color::parse_color;
use crate::encode::{decode_frames, encode_frames};
use crate::error::{ImageError, Result};
use crate::frame::{Frame, Raster};
use crate::gravity::{self, GRAVITY_CENTER};
use crate::limits;

/// Lightweight image manipulator (crop, border, opacity, rotate, encode).
#[derive(Clone, Debug)]
pub struct Image {
    frames: Vec<Frame>,
    width: u32,
    height: u32,
    corner_radius: u32,
    border_width: u32,
    border_color: String,
    /// Pending EXIF orientation rotation applied on first `save`/`output`.
    rotation: i32,
}

impl Image {
    /// Decode an image blob (JPEG/PNG/GIF/WebP/AVIF/HEIC).
    ///
    /// Mirrors `new Image($data)` - GIF/WebP animations are coalesced and first-frame
    /// dimensions are used (Imagick `setFirstIterator` parity).
    pub fn new(data: &[u8]) -> Result<Self> {
        let (frames, width, height) = decode_frames(data)?;
        let rotation = read_exif_rotation(data);
        Ok(Self {
            frames,
            width,
            height,
            corner_radius: 0,
            border_width: 0,
            border_color: String::new(),
            rotation,
        })
    }

    /// Gravity type strings (`center`, `top-left`, …).
    pub fn get_gravity_types() -> &'static [&'static str] {
        gravity::gravity_types()
    }

    /// Current width in pixels (first/current frame).
    pub fn width(&self) -> u32 {
        self.width
    }

    /// Current height in pixels (first/current frame).
    pub fn height(&self) -> u32 {
        self.height
    }

    /// Number of animation frames (1 for still images).
    pub fn frame_count(&self) -> usize {
        self.frames.len()
    }

    /// Cover-crop to `width`×`height` using Utopia gravity.
    ///
    /// Passing `0` for a dimension preserves aspect ratio. `0, 0` keeps the
    /// current size (still runs through the resize path when gravity is not a
    /// no-op early-return).
    pub fn crop(&mut self, width: u32, height: u32, gravity: &str) -> Result<&mut Self> {
        if gravity == GRAVITY_CENTER
            && width != 0
            && height != 0
            && width == self.width
            && height == self.height
        {
            return Ok(self);
        }

        let original_aspect = f64::from(self.width) / f64::from(self.height.max(1));
        let mut width = width;
        let mut height = height;

        if width == 0 && height != 0 {
            width = (f64::from(height) * original_aspect) as u32;
        }
        if height == 0 && width != 0 {
            height = (f64::from(width) / original_aspect) as u32;
        }
        if width == 0 && height == 0 {
            width = self.width;
            height = self.height;
        }
        width = width.max(1);
        height = height.max(1);
        limits::check_area(width, height)?;

        let centering = gravity::centering(gravity);
        // Box filter + no alpha premultiply: huge win vs Imagick for JPEG thumbs.
        // Bilinear only when the scale factor is mild (upsamples / small downs).
        let scale = (f64::from(width) / f64::from(self.width.max(1)))
            .min(f64::from(height) / f64::from(self.height.max(1)));
        let filter = if scale < 0.75 {
            FilterType::Box
        } else {
            FilterType::Bilinear
        };
        let options = ResizeOptions::new()
            .resize_alg(ResizeAlg::Convolution(filter))
            .fit_into_destination(Some(centering))
            .use_alpha(false);

        with_resizer(|resizer| {
            if self.frames.len() <= 1 {
                for frame in &mut self.frames {
                    frame.raster = fir_fit_raster(resizer, &frame.raster, width, height, &options)?;
                }
                Ok(())
            } else {
                use rayon::prelude::*;
                let results: Result<Vec<_>> = self
                    .frames
                    .par_iter()
                    .map(|frame| {
                        let mut local = Resizer::new();
                        fir_fit_raster(&mut local, &frame.raster, width, height, &options)
                    })
                    .collect();
                for (frame, out) in self.frames.iter_mut().zip(results?) {
                    frame.raster = out;
                }
                Ok(())
            }
        })?;

        self.width = width;
        self.height = height;
        Ok(self)
    }

    /// Add a solid border. When a corner radius is already set, the border is
    /// deferred and drawn by [`Self::set_border_radius`] (PHP parity).
    pub fn set_border(&mut self, border_width: u32, border_color: &str) -> Result<&mut Self> {
        self.border_width = border_width;
        self.border_color = border_color.to_string();
        if self.corner_radius != 0 || border_width == 0 {
            return Ok(self);
        }

        let color = parse_color(border_color)?;
        let new_w = self.width + border_width * 2;
        let new_h = self.height + border_width * 2;
        limits::check_area(new_w, new_h)?;

        for frame in &mut self.frames {
            match &frame.raster {
                Raster::Rgb(src) => {
                    let rgb_border = image::Rgb([color[0], color[1], color[2]]);
                    let mut canvas = RgbImage::from_pixel(new_w, new_h, rgb_border);
                    let row_bytes = (src.width() as usize) * 3;
                    let src_raw = src.as_raw();
                    let dst_raw = canvas.as_mut();
                    let dst_stride = (new_w as usize) * 3;
                    let x_off = (border_width as usize) * 3;
                    for y in 0..src.height() as usize {
                        let src_off = y * row_bytes;
                        let dst_off = (y + border_width as usize) * dst_stride + x_off;
                        dst_raw[dst_off..dst_off + row_bytes]
                            .copy_from_slice(&src_raw[src_off..src_off + row_bytes]);
                    }
                    frame.raster = Raster::from_rgb(canvas);
                }
                Raster::Rgba(src) => {
                    let mut canvas = RgbaImage::from_pixel(new_w, new_h, color);
                    let row_bytes = (src.width() as usize) * 4;
                    let src_raw = src.as_raw();
                    let dst_raw = canvas.as_mut();
                    let dst_stride = (new_w as usize) * 4;
                    let x_off = (border_width as usize) * 4;
                    for y in 0..src.height() as usize {
                        let src_off = y * row_bytes;
                        let dst_off = (y + border_width as usize) * dst_stride + x_off;
                        dst_raw[dst_off..dst_off + row_bytes]
                            .copy_from_slice(&src_raw[src_off..src_off + row_bytes]);
                    }
                    frame.raster = Raster::from_rgba(canvas);
                }
            }
        }
        self.width = new_w;
        self.height = new_h;
        Ok(self)
    }

    /// Apply rounded corners (DSTIN mask). Draws a stroked border when
    /// [`Self::set_border`] was called with a non-zero width beforehand.
    pub fn set_border_radius(&mut self, corner_radius: u32) -> Result<&mut Self> {
        self.corner_radius = corner_radius;
        let bw = self.border_width;
        let border_color = if bw > 0 && !self.border_color.is_empty() {
            Some(parse_color(&self.border_color)?)
        } else {
            None
        };

        for frame in &mut self.frames {
            let img = frame.raster.make_rgba_mut();
            apply_rounded_corners(img, corner_radius, bw, border_color);
        }
        Ok(self)
    }

    /// Multiply the alpha channel by `opacity` (`0.0`–`1.0`). `1.0` is a no-op.
    pub fn set_opacity(&mut self, opacity: f64) -> Result<&mut Self> {
        if (opacity - 1.0).abs() < f64::EPSILON {
            return Ok(self);
        }
        let opacity = opacity.clamp(0.0, 1.0);
        // Fixed-point scale: out = (alpha * opacity).round()
        let scale = (opacity * 256.0).round() as u32;
        for frame in &mut self.frames {
            let img = frame.raster.make_rgba_mut();
            let raw = img.as_mut();
            // Fast path: fully opaque source (JPEG/most stills) → constant alpha.
            if raw.chunks_exact(4).all(|p| p[3] == 255) {
                let a = ((255u32 * scale + 128) >> 8).min(255) as u8;
                for px in raw.chunks_exact_mut(4) {
                    px[3] = a;
                }
            } else if opacity == 0.0 {
                for px in raw.chunks_exact_mut(4) {
                    px[3] = 0;
                }
            } else {
                for px in raw.chunks_exact_mut(4) {
                    px[3] = ((u32::from(px[3]) * scale + 128) >> 8).min(255) as u8;
                }
            }
        }
        Ok(self)
    }

    /// Rotate by `degree` degrees around the center with a transparent fill.
    pub fn set_rotation(&mut self, degree: i32) -> Result<&mut Self> {
        if degree == 0 {
            return Ok(self);
        }
        // Map to `[0, 360)` for orthogonal fast paths (`-90` → `270`).
        let deg = degree.rem_euclid(360);
        for frame in &mut self.frames {
            if deg == 0 {
                continue;
            }
            // Transparent fill requires RGBA.
            frame.raster.promote_rgba();
            let src = frame.raster.as_rgba_arc();
            let rotated = match deg {
                90 => image::imageops::rotate90(src.as_ref()),
                180 => image::imageops::rotate180(src.as_ref()),
                270 => image::imageops::rotate270(src.as_ref()),
                _ => rotate_rgba(src.as_ref(), degree as f32),
            };
            frame.raster = Raster::from_rgba(rotated);
        }
        if let Some(first) = self.frames.first() {
            self.width = first.raster.width();
            self.height = first.raster.height();
            limits::check_area(self.width, self.height)?;
        }
        Ok(self)
    }

    /// Flatten transparent pixels onto a solid background color.
    pub fn set_background(&mut self, color: &str) -> Result<&mut Self> {
        let bg = parse_color(color)?;
        for frame in &mut self.frames {
            // Opaque RGB needs no flatten.
            if frame.raster.is_rgb() {
                continue;
            }
            let img = frame.raster.make_rgba_mut();
            for px in img.as_mut().chunks_exact_mut(4) {
                let a = px[3];
                if a == 255 {
                    continue;
                }
                if a == 0 {
                    px[0] = bg[0];
                    px[1] = bg[1];
                    px[2] = bg[2];
                    px[3] = 255;
                    continue;
                }
                let ai = u32::from(a);
                let inv = 255 - ai;
                px[0] = ((u32::from(px[0]) * ai + u32::from(bg[0]) * inv + 127) / 255) as u8;
                px[1] = ((u32::from(px[1]) * ai + u32::from(bg[1]) * inv + 127) / 255) as u8;
                px[2] = ((u32::from(px[2]) * ai + u32::from(bg[2]) * inv + 127) / 255) as u8;
                px[3] = 255;
            }
        }
        Ok(self)
    }

    /// Encode to an in-memory blob (`save(null, …)` parity).
    pub fn output(&mut self, format: &str, quality: i32) -> Result<Vec<u8>> {
        self.save_to(None, format, quality)?
            .ok_or_else(|| ImageError::Encode("output produced no bytes".into()))
    }

    /// Save to `path`, or return encoded bytes when `path` is `None` / empty.
    pub fn save(
        &mut self,
        path: Option<&Path>,
        format: &str,
        quality: i32,
    ) -> Result<Option<Vec<u8>>> {
        self.save_to(path, format, quality)
    }

    /// Convenience: save to a path string (PHP `save($path, …)`).
    pub fn save_path(&mut self, path: impl AsRef<Path>, format: &str, quality: i32) -> Result<()> {
        self.save_to(Some(path.as_ref()), format, quality)?;
        Ok(())
    }

    /// Imagick `setResourceLimit` parity.
    pub fn set_resource_limit(limit_type: &str, value: i64) {
        limits::set_resource_limit(limit_type, value);
    }

    fn save_to(
        &mut self,
        path: Option<&Path>,
        format: &str,
        quality: i32,
    ) -> Result<Option<Vec<u8>>> {
        self.apply_pending_rotation()?;
        let format = format.trim().to_ascii_lowercase();
        let bytes = encode_frames(&self.frames, &format, quality)?;

        match path {
            None => Ok(Some(bytes)),
            Some(p) if p.as_os_str().is_empty() => Ok(Some(bytes)),
            Some(p) => {
                if let Some(parent) = p.parent() {
                    if !parent.as_os_str().is_empty() && !parent.exists() {
                        std::fs::create_dir_all(parent)?;
                    }
                }
                std::fs::write(p, &bytes)?;
                Ok(None)
            }
        }
    }

    fn apply_pending_rotation(&mut self) -> Result<()> {
        if self.rotation == 0 {
            return Ok(());
        }
        let deg = self.rotation;
        self.rotation = 0;
        self.set_rotation(deg)?;
        Ok(())
    }
}

thread_local! {
    static RESIZER: RefCell<Resizer> = RefCell::new(Resizer::new());
}

fn with_resizer<T>(f: impl FnOnce(&mut Resizer) -> Result<T>) -> Result<T> {
    RESIZER.with(|slot| {
        let mut resizer = slot.borrow_mut();
        f(&mut resizer)
    })
}

fn fir_fit_raster(
    resizer: &mut Resizer,
    src: &Raster,
    width: u32,
    height: u32,
    options: &ResizeOptions,
) -> Result<Raster> {
    match src {
        Raster::Rgb(img) => {
            let src_ref =
                ImageRef::new(img.width(), img.height(), img.as_raw(), PixelType::U8x3)
                    .map_err(|e| ImageError::Message(format!("invalid source buffer: {e:?}")))?;
            let mut dst = FirImage::new(width, height, PixelType::U8x3);
            resizer
                .resize(&src_ref, &mut dst, Some(options))
                .map_err(|e| ImageError::Message(e.to_string()))?;
            let out = RgbImage::from_raw(width, height, dst.into_vec())
                .ok_or_else(|| ImageError::Message("resize produced invalid buffer".into()))?;
            Ok(Raster::from_rgb(out))
        }
        Raster::Rgba(img) => {
            let src_ref =
                ImageRef::new(img.width(), img.height(), img.as_raw(), PixelType::U8x4)
                    .map_err(|e| ImageError::Message(format!("invalid source buffer: {e:?}")))?;
            let mut dst = FirImage::new(width, height, PixelType::U8x4);
            resizer
                .resize(&src_ref, &mut dst, Some(options))
                .map_err(|e| ImageError::Message(e.to_string()))?;
            let out = RgbaImage::from_raw(width, height, dst.into_vec())
                .ok_or_else(|| ImageError::Message("resize produced invalid buffer".into()))?;
            Ok(Raster::from_rgba(out))
        }
    }
}

fn has_exif_app1(data: &[u8]) -> bool {
    // Cheap JPEG scan for APP1 before invoking the full Exif parser.
    if data.len() < 4 || data[0] != 0xFF || data[1] != 0xD8 {
        return true; // non-JPEG: let the parser decide
    }
    let mut i = 2usize;
    while i + 4 < data.len() && data[i] == 0xFF {
        let marker = data[i + 1];
        if marker == 0xDA {
            break; // SOS
        }
        if marker == 0xE1 {
            return true;
        }
        let len = u16::from_be_bytes([data[i + 2], data[i + 3]]) as usize;
        if len < 2 {
            break;
        }
        i += 2 + len;
    }
    false
}

fn read_exif_rotation(data: &[u8]) -> i32 {
    if !has_exif_app1(data) {
        return 0;
    }
    let mut cursor = Cursor::new(data);
    let exif: Exif = match ExifReader::new().read_from_container(&mut cursor) {
        Ok(e) => e,
        Err(_) => return 0,
    };
    let field = match exif.get_field(Tag::Orientation, In::PRIMARY) {
        Some(f) => f,
        None => return 0,
    };
    let orientation = match field.value.get_uint(0) {
        Some(v) => v,
        None => return 0,
    };
    // Mirror orientations ignored (PHP parity).
    match orientation {
        3 => 180,
        6 => 90,
        8 => -90,
        _ => 0,
    }
}

fn apply_rounded_corners(
    img: &mut RgbaImage,
    radius: u32,
    border_width: u32,
    border_color: Option<Rgba<u8>>,
) {
    let w = img.width();
    let h = img.height();
    let radius = radius.min(w / 2).min(h / 2);
    let r2 = i64::from(radius) * i64::from(radius);

    let left = border_width as i32;
    let top = border_width as i32;
    let right = (w - border_width.saturating_add(1)) as i32;
    let bottom = (h - border_width.saturating_add(1)) as i32;

    // DSTIN soft mask: keep pixels inside rounded rect (integer corner tests).
    let raw = img.as_mut();
    let stride = (w as usize) * 4;
    for y in 0..h as i32 {
        for x in 0..w as i32 {
            if !inside_rounded_rect_i(x, y, left, top, right, bottom, radius as i32, r2) {
                let i = y as usize * stride + x as usize * 4 + 3;
                raw[i] = 0;
            }
        }
    }

    if let (Some(color), true) = (border_color, border_width > 0) {
        let mut stroke = RgbaImage::from_pixel(w, h, Rgba([0, 0, 0, 0]));
        let bw = border_width as i32;
        let inner_r = radius as i32;
        let outer_r2 = i64::from(inner_r + bw) * i64::from(inner_r + bw);
        let inner_r2 = i64::from(inner_r) * i64::from(inner_r);
        for y in 0..h as i32 {
            for x in 0..w as i32 {
                let outer = inside_rounded_rect_i(
                    x,
                    y,
                    0,
                    0,
                    w as i32 - 1,
                    h as i32 - 1,
                    inner_r + bw,
                    outer_r2,
                );
                let inner = inside_rounded_rect_i(
                    x,
                    y,
                    bw,
                    bw,
                    w as i32 - bw - 1,
                    h as i32 - bw - 1,
                    inner_r,
                    inner_r2,
                );
                if outer && !inner {
                    stroke.put_pixel(x as u32, y as u32, color);
                }
            }
        }
        for (x, y, p) in img.enumerate_pixels() {
            let s = *stroke.get_pixel(x, y);
            stroke.put_pixel(x, y, flatten_over(s, *p));
        }
        *img = stroke;
    }
}

fn flatten_over(dst: Rgba<u8>, src: Rgba<u8>) -> Rgba<u8> {
    let sa = u32::from(src[3]);
    let da = u32::from(dst[3]);
    if sa == 0 {
        return dst;
    }
    if sa == 255 {
        return src;
    }
    let out_a = sa + (da * (255 - sa) + 127) / 255;
    if out_a == 0 {
        return Rgba([0, 0, 0, 0]);
    }
    let mut out = [0u8; 4];
    for i in 0..3 {
        let v = (u32::from(src[i]) * sa + u32::from(dst[i]) * da * (255 - sa) / 255 + out_a / 2)
            / out_a;
        out[i] = v.min(255) as u8;
    }
    out[3] = out_a.min(255) as u8;
    Rgba(out)
}

fn inside_rounded_rect_i(
    x: i32,
    y: i32,
    left: i32,
    top: i32,
    right: i32,
    bottom: i32,
    radius: i32,
    r2: i64,
) -> bool {
    if x < left || y < top || x > right || y > bottom {
        return false;
    }
    if radius <= 0 {
        return true;
    }
    // Only the four corner squares need a circle test.
    if x <= left + radius && y <= top + radius {
        let dx = i64::from(x - (left + radius));
        let dy = i64::from(y - (top + radius));
        return dx * dx + dy * dy <= r2;
    }
    if x >= right - radius && y <= top + radius {
        let dx = i64::from(x - (right - radius));
        let dy = i64::from(y - (top + radius));
        return dx * dx + dy * dy <= r2;
    }
    if x <= left + radius && y >= bottom - radius {
        let dx = i64::from(x - (left + radius));
        let dy = i64::from(y - (bottom - radius));
        return dx * dx + dy * dy <= r2;
    }
    if x >= right - radius && y >= bottom - radius {
        let dx = i64::from(x - (right - radius));
        let dy = i64::from(y - (bottom - radius));
        return dx * dx + dy * dy <= r2;
    }
    true
}

fn rotate_rgba(src: &RgbaImage, degrees: f32) -> RgbaImage {
    use rayon::prelude::*;

    let (w, h) = (src.width() as f32, src.height() as f32);
    let rad = degrees * PI / 180.0;
    let (sin, cos) = rad.sin_cos();
    let new_w = (w * cos.abs() + h * sin.abs()).ceil().max(1.0) as u32;
    let new_h = (w * sin.abs() + h * cos.abs()).ceil().max(1.0) as u32;
    let mut dst = RgbaImage::from_pixel(new_w, new_h, Rgba([0, 0, 0, 0]));

    let cx = (w - 1.0) / 2.0;
    let cy = (h - 1.0) / 2.0;
    let ncx = (new_w as f32 - 1.0) / 2.0;
    let ncy = (new_h as f32 - 1.0) / 2.0;

    let src_w = src.width() as i32;
    let src_h = src.height() as i32;
    let src_raw = src.as_raw();
    let src_stride = (src.width() as usize) * 4;
    let dst_stride = (new_w as usize) * 4;

    // Parallel bilinear inverse-map over destination rows.
    dst.as_mut()
        .par_chunks_mut(dst_stride)
        .enumerate()
        .for_each(|(y, row)| {
            let dy = y as f32 - ncy;
            for x in 0..new_w {
                let dx = x as f32 - ncx;
                let sx = cos * dx + sin * dy + cx;
                let sy = -sin * dx + cos * dy + cy;
                if let Some(p) = sample_bilinear_raw(src_raw, src_stride, src_w, src_h, sx, sy) {
                    let off = x as usize * 4;
                    row[off..off + 4].copy_from_slice(&p);
                }
            }
        });
    dst
}

fn sample_bilinear_raw(
    raw: &[u8],
    stride: usize,
    width: i32,
    height: i32,
    x: f32,
    y: f32,
) -> Option<[u8; 4]> {
    if x < -0.5 || y < -0.5 || x > width as f32 - 0.5 || y > height as f32 - 0.5 {
        return None;
    }
    let x0 = x.floor() as i32;
    let y0 = y.floor() as i32;
    let x1 = x0 + 1;
    let y1 = y0 + 1;
    let fx = x - x0 as f32;
    let fy = y - y0 as f32;

    let mut acc = [0f32; 4];
    let mut weight = 0f32;
    for (ix, wx) in [(x0, 1.0 - fx), (x1, fx)] {
        for (iy, wy) in [(y0, 1.0 - fy), (y1, fy)] {
            if ix >= 0 && iy >= 0 && ix < width && iy < height {
                let off = iy as usize * stride + ix as usize * 4;
                let w = wx * wy;
                acc[0] += f32::from(raw[off]) * w;
                acc[1] += f32::from(raw[off + 1]) * w;
                acc[2] += f32::from(raw[off + 2]) * w;
                acc[3] += f32::from(raw[off + 3]) * w;
                weight += w;
            }
        }
    }
    if weight <= f32::EPSILON {
        return None;
    }
    Some([
        (acc[0] / weight).round() as u8,
        (acc[1] / weight).round() as u8,
        (acc[2] / weight).round() as u8,
        (acc[3] / weight).round() as u8,
    ])
}
