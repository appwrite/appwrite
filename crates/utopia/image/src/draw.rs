//! Filled paths and text, drawn onto an image's current frame.

use image::RgbaImage;
use skrifa::instance::{LocationRef, Size};
use skrifa::outline::{DrawSettings, OutlinePen};
use skrifa::{FontRef, GlyphId, MetadataProvider};
use zeno::{Command, Fill, Mask, PathData, Transform, Vector};

use crate::color::Color;
use crate::error::{Error, Result};

/// Fills `path` (SVG path data, or commands), transformed, with `color`,
/// anti-aliased and composited over what is there.
pub(crate) fn fill(pixels: &mut RgbaImage, path: impl PathData, transform: Transform, color: Color) {
    let (mask, placement) = Mask::new(path).style(Fill::NonZero).transform(Some(transform)).render();
    let [red, green, blue, alpha] = color.0.map(f32::from);
    for (index, coverage) in mask.iter().enumerate().filter(|(_, coverage)| **coverage > 0) {
        let x = i64::from(placement.left) + (index as u32 % placement.width) as i64;
        let y = i64::from(placement.top) + (index as u32 / placement.width) as i64;
        if x < 0 || y < 0 || x >= i64::from(pixels.width()) || y >= i64::from(pixels.height()) {
            continue;
        }
        // Source-over on straight alpha.
        let pixel = pixels.get_pixel_mut(x as u32, y as u32);
        let source = f32::from(*coverage) / 255.0 * alpha / 255.0;
        let under = f32::from(pixel.0[3]) / 255.0 * (1.0 - source);
        let out = source + under;
        for (channel, value) in [red, green, blue].into_iter().enumerate() {
            pixel.0[channel] = ((value * source + f32::from(pixel.0[channel]) * under) / out).round() as u8;
        }
        pixel.0[3] = (out * 255.0).round() as u8;
    }
}

/// Fails unless `path` is SVG path data.
pub(crate) fn validate(path: &str) -> Result<()> {
    zeno::validate_svg(path).map_err(|at| Error::InvalidArgument(format!("Invalid path at offset {at}")))
}

fn face(font: &[u8]) -> Result<FontRef<'_>> {
    FontRef::new(font).map_err(|error| Error::InvalidArgument(format!("Invalid font: {error}")))
}

/// A glyph's outline as path commands, with y up.
#[derive(Default)]
struct Outline(Vec<Command>);

impl OutlinePen for Outline {
    fn move_to(&mut self, x: f32, y: f32) {
        self.0.push(Command::MoveTo(Vector::new(x, y)));
    }

    fn line_to(&mut self, x: f32, y: f32) {
        self.0.push(Command::LineTo(Vector::new(x, y)));
    }

    fn quad_to(&mut self, x1: f32, y1: f32, x: f32, y: f32) {
        self.0.push(Command::QuadTo(Vector::new(x1, y1), Vector::new(x, y)));
    }

    fn curve_to(&mut self, x1: f32, y1: f32, x2: f32, y2: f32, x: f32, y: f32) {
        self.0.push(Command::CurveTo(Vector::new(x1, y1), Vector::new(x2, y2), Vector::new(x, y)));
    }

    fn close(&mut self) {
        self.0.push(Command::Close);
    }
}

/// The outline of `glyph` at `size` pixels an em; empty for a glyph that draws nothing.
fn outline(face: &FontRef<'_>, glyph: GlyphId, size: Size) -> Vec<Command> {
    let mut outline = Outline::default();
    if let Some(glyph) = face.outline_glyphs().get(glyph) {
        // A glyph that fails to draw is one that draws nothing.
        let _ = glyph.draw(DrawSettings::unhinted(size, LocationRef::default()), &mut outline);
    }
    outline.0
}

/// Whether `font` (TrueType or OpenType data) draws something for `character`:
/// a missing glyph, or one with no outline, draws nothing.
pub fn has_glyph(font: &[u8], character: char) -> Result<bool> {
    let face = face(font)?;
    Ok(face.charmap().map(character).is_some_and(|glyph| !outline(&face, glyph, Size::unscaled()).is_empty()))
}

/// Draws `text` in `font` at `size` pixels an em, centred on `x` with its
/// baseline at `y`. Glyphs are placed by their advances, without kerning or
/// shaping; a character the font has no glyph for takes no space.
pub(crate) fn text(
    pixels: &mut RgbaImage,
    text: &str,
    font: &[u8],
    size: f32,
    color: Color,
    x: f32,
    y: f32,
) -> Result<()> {
    let face = face(font)?;
    let size = Size::new(size);
    let metrics = face.glyph_metrics(size, LocationRef::default());
    let glyphs: Vec<_> = text.chars().filter_map(|character| face.charmap().map(character)).collect();
    let advance = |glyph| metrics.advance_width(glyph).unwrap_or(0.0);
    let mut pen = x - glyphs.iter().map(|glyph| advance(*glyph)).sum::<f32>() / 2.0;
    for glyph in glyphs {
        fill(pixels, &outline(&face, glyph, size), Transform::scale(1.0, -1.0).then_translate(pen, y), color);
        pen += advance(glyph);
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use image::Rgba;

    use super::*;

    const FONT: &[u8] = include_bytes!("../../../../app/assets/fonts/inter-v8-latin-regular.ttf");

    fn canvas() -> RgbaImage {
        RgbaImage::from_pixel(40, 40, Rgba([0, 0, 255, 255]))
    }

    #[test]
    fn a_path_is_scaled_then_moved() {
        let mut pixels = canvas();
        fill(
            &mut pixels,
            "M0 0h5v5h-5Z",
            Transform::scale(2.0, 2.0).then_translate(10.0, 20.0),
            Color::rgba(255, 0, 0, 255),
        );
        assert_eq!(pixels.get_pixel(15, 25).0, [255, 0, 0, 255]);
        assert_eq!(pixels.get_pixel(9, 25).0, [0, 0, 255, 255]);
        assert_eq!(pixels.get_pixel(20, 25).0, [0, 0, 255, 255]);
        assert_eq!(pixels.get_pixel(15, 30).0, [0, 0, 255, 255]);
    }

    #[test]
    fn a_translucent_fill_blends_and_what_falls_outside_is_dropped() {
        let mut pixels = canvas();
        fill(&mut pixels, "M-10 -10h20v20h-20Z", Transform::IDENTITY, Color::rgba(255, 0, 0, 128));
        assert_eq!(pixels.get_pixel(5, 5).0, [128, 0, 127, 255]);
        assert_eq!(pixels.get_pixel(15, 15).0, [0, 0, 255, 255]);
    }

    #[test]
    fn a_fill_on_a_transparent_pixel_keeps_its_own_colour() {
        let mut pixels = RgbaImage::new(4, 4);
        fill(&mut pixels, "M0 0h4v4h-4Z", Transform::IDENTITY, Color::rgba(10, 20, 30, 128));
        assert_eq!(pixels.get_pixel(1, 1).0, [10, 20, 30, 128]);
    }

    #[test]
    fn path_data_is_validated() {
        assert!(validate("M7.5 6a4.5 4.5 0 1 1 9 0a4.5 4.5 0 0 1-9 0Z").is_ok());
        assert!(matches!(validate("M0 0 Q bogus"), Err(Error::InvalidArgument(_))));
    }

    #[test]
    fn a_glyph_needs_an_outline() {
        assert!(has_glyph(FONT, 'A').unwrap());
        assert!(!has_glyph(FONT, ' ').unwrap());
        assert!(!has_glyph(FONT, '山').unwrap());
        assert!(matches!(has_glyph(b"not a font", 'A'), Err(Error::InvalidArgument(_))));
    }

    #[test]
    fn text_is_centred_on_x_above_the_baseline() {
        let mut pixels = canvas();
        text(&mut pixels, "II", FONT, 20.0, Color::WHITE, 20.0, 30.0).unwrap();
        let drawn: Vec<(u32, u32)> = pixels
            .enumerate_pixels()
            .filter(|(_, _, pixel)| pixel.0 != [0, 0, 255, 255])
            .map(|(x, y, _)| (x, y))
            .collect();
        assert!(!drawn.is_empty());
        let (left, right) = (drawn.iter().map(|p| p.0).min().unwrap(), drawn.iter().map(|p| p.0).max().unwrap());
        assert!((i64::from(left) + i64::from(right) - 40).abs() <= 2, "{left}..{right} is not centred on 20");
        assert!(drawn.iter().all(|p| p.1 <= 30), "nothing of a capital I is below the baseline");
        // A character without a glyph draws nothing and takes no space.
        let mut same = canvas();
        text(&mut same, "I山I", FONT, 20.0, Color::WHITE, 20.0, 30.0).unwrap();
        assert_eq!(pixels, same);
    }
}
