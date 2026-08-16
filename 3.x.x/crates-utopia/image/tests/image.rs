//! Integration tests mirroring utopia-php/image `PHPUnit` suite coverage.

use std::path::PathBuf;

use image::{ImageEncoder, ImageFormat, Rgba, RgbaImage};
use tempfile::tempdir;
use utopia_image::prelude::*;

fn fixture(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/resources/disk-a")
        .join(name)
}

fn read_fixture(name: &str) -> Vec<u8> {
    std::fs::read(fixture(name)).unwrap_or_else(|e| panic!("read {name}: {e}"))
}

fn jpeg_with_exif_orientation(orientation: u16) -> Vec<u8> {
    // Minimal JPEG (red 20×10) with an EXIF Orientation APP1 segment injected.
    let mut img = RgbaImage::from_pixel(20, 10, Rgba([255, 0, 0, 255]));
    let mut jpeg = Vec::new();
    {
        let mut enc = image::codecs::jpeg::JpegEncoder::new_with_quality(&mut jpeg, 90);
        let rgb = image::DynamicImage::ImageRgba8(img.clone()).to_rgb8();
        enc.encode(
            rgb.as_raw(),
            rgb.width(),
            rgb.height(),
            image::ExtendedColorType::Rgb8,
        )
        .unwrap();
    }
    let _ = &mut img;

    let mut exif = Vec::new();
    exif.extend_from_slice(b"Exif\0\0");
    exif.extend_from_slice(b"II*\0"); // little-endian TIFF
    exif.extend_from_slice(&8u32.to_le_bytes()); // IFD0 offset
    exif.extend_from_slice(&1u16.to_le_bytes()); // one entry
    exif.extend_from_slice(&0x0112u16.to_le_bytes()); // Orientation
    exif.extend_from_slice(&3u16.to_le_bytes()); // SHORT
    exif.extend_from_slice(&1u32.to_le_bytes()); // count
    exif.extend_from_slice(&u32::from(orientation).to_le_bytes());
    exif.extend_from_slice(&0u32.to_le_bytes()); // next IFD

    let mut segment = Vec::new();
    segment.extend_from_slice(&[0xFF, 0xE1]);
    segment.extend_from_slice(&((exif.len() + 2) as u16).to_be_bytes());
    segment.extend_from_slice(&exif);

    let mut out = Vec::with_capacity(jpeg.len() + segment.len());
    out.extend_from_slice(&jpeg[..2]); // SOI
    out.extend_from_slice(&segment);
    out.extend_from_slice(&jpeg[2..]);
    out
}

fn probe_dims(bytes: &[u8]) -> (u32, u32) {
    let img = image::load_from_memory(bytes).expect("probe decode");
    (img.width(), img.height())
}

#[test]
fn gravity_types() {
    let types = Image::get_gravity_types();
    assert!(types.contains(&GRAVITY_CENTER));
    assert!(types.contains(&GRAVITY_TOP_LEFT));
    assert_eq!(types.len(), 9);
}

#[test]
fn jpeg_crop_100() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(100, 100, GRAVITY_CENTER).unwrap();
    let dir = tempdir().unwrap();
    let target = dir.path().join("100x100.jpg");
    image.save_path(&target, "jpg", 100).unwrap();
    assert!(target.is_file());
    let bytes = std::fs::read(&target).unwrap();
    assert!(!bytes.is_empty());
    let (w, h) = probe_dims(&bytes);
    assert_eq!((w, h), (100, 100));
    assert_eq!(image::guess_format(&bytes).unwrap(), ImageFormat::Jpeg);
}

#[test]
fn png_crop_100() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(100, 100, GRAVITY_CENTER).unwrap();
    let blob = image.output("png", 100).unwrap();
    let (w, h) = probe_dims(&blob);
    assert_eq!((w, h), (100, 100));
    assert_eq!(image::guess_format(&blob).unwrap(), ImageFormat::Png);
}

#[test]
fn crop_gravities_dimensions() {
    let cases = [
        (GRAVITY_TOP_LEFT, 50, 200),
        (GRAVITY_TOP_RIGHT, 50, 200),
        (GRAVITY_BOTTOM_LEFT, 50, 200),
        (GRAVITY_BOTTOM_RIGHT, 50, 200),
        (GRAVITY_RIGHT, 50, 200),
        (GRAVITY_CENTER, 150, 200),
    ];
    for (gravity, w, h) in cases {
        let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
        image.crop(w, h, gravity).unwrap();
        let blob = image.output("jpg", 100).unwrap();
        assert_eq!(probe_dims(&blob), (w, h), "gravity {gravity}");
    }
}

#[test]
fn crop_gravity_positions_horizontal() {
    // 6×2 strip: red | green | blue
    let mut src = RgbaImage::new(6, 2);
    for y in 0..2 {
        for x in 0..6 {
            let color = match x {
                0 | 1 => Rgba([255, 0, 0, 255]),
                2 | 3 => Rgba([0, 255, 0, 255]),
                _ => Rgba([0, 0, 255, 255]),
            };
            src.put_pixel(x, y, color);
        }
    }
    let png = {
        let mut buf = Vec::new();
        image::codecs::png::PngEncoder::new(&mut buf)
            .write_image(src.as_raw(), 6, 2, image::ExtendedColorType::Rgba8)
            .unwrap();
        buf
    };

    let cases = [
        (GRAVITY_TOP_LEFT, 0usize),
        (GRAVITY_TOP, 1),
        (GRAVITY_TOP_RIGHT, 2),
        (GRAVITY_LEFT, 0),
        (GRAVITY_CENTER, 1),
        (GRAVITY_RIGHT, 2),
        (GRAVITY_BOTTOM_LEFT, 0),
        (GRAVITY_BOTTOM, 1),
        (GRAVITY_BOTTOM_RIGHT, 2),
    ];
    for (gravity, expected) in cases {
        let mut image = Image::new(&png).unwrap();
        image.crop(2, 2, gravity).unwrap();
        let blob = image.output("png", 100).unwrap();
        let out = image::load_from_memory(&blob).unwrap().to_rgba8();
        assert_eq!(out.dimensions(), (2, 2));
        let c = out.get_pixel(1, 1).0;
        match expected {
            0 => assert!(c[0] > c[1] && c[0] > c[2], "{gravity:?} => {c:?}"),
            1 => assert!(c[1] > c[0] && c[1] > c[2], "{gravity:?} => {c:?}"),
            _ => assert!(c[2] > c[0] && c[2] > c[1], "{gravity:?} => {c:?}"),
        }
    }
}

#[test]
fn webp_blob_output() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(100, 100, GRAVITY_CENTER).unwrap();
    let blob = image.output("webp", 75).unwrap();
    assert!(blob.starts_with(b"RIFF"));
    assert_eq!(&blob[8..12], b"WEBP");
    assert_eq!(probe_dims(&blob), (100, 100));
}

#[test]
fn repeated_output_applies_exif_rotation_once() {
    let mut image = Image::new(&jpeg_with_exif_orientation(6)).unwrap();
    let first = image.output("png", 100).unwrap();
    let second = image.output("png", 100).unwrap();
    // Orientation 6 → 90° CW: 20×10 becomes 10×20.
    assert_eq!(probe_dims(&first), (10, 20));
    assert_eq!(probe_dims(&second), (10, 20));
}

#[test]
fn save_preserves_image_for_subsequent_exports() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    let dir = tempdir().unwrap();
    let target = dir.path().join("reusable.jpg");
    image.save_path(&target, "jpg", 75).unwrap();
    let blob = image.output("png", 75).unwrap();
    assert_eq!(image::guess_format(&blob).unwrap(), ImageFormat::Png);
}

#[test]
fn save_writes_filename_zero() {
    let dir = tempdir().unwrap();
    let target = dir.path().join("0");
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    assert!(image
        .save(Some(target.as_path()), "jpg", 75)
        .unwrap()
        .is_none());
    assert!(target.is_file());
    assert!(!std::fs::read(&target).unwrap().is_empty());
}

#[test]
fn border_rotate_opacity_roundtrip() {
    let data = read_fixture("kitten-1.jpg");

    let mut bordered = Image::new(&data).unwrap();
    bordered.set_border(5, "#ff0000").unwrap();
    let bordered_bytes = bordered.output("jpg", 100).unwrap();
    assert!(probe_dims(&bordered_bytes).0 > 1837);

    let mut rotated = Image::new(&data).unwrap();
    rotated.set_rotation(45).unwrap();
    let rotated_bytes = rotated.output("jpg", 100).unwrap();
    let (width, height) = probe_dims(&rotated_bytes);
    assert!(width > 1837 && height > 1920);

    let mut faded = Image::new(&data).unwrap();
    faded.set_opacity(0.2).unwrap();
    let faded_bytes = faded.output("png", 100).unwrap();
    assert_eq!(image::guess_format(&faded_bytes).unwrap(), ImageFormat::Png);
}

#[test]
fn border_radius_and_crop_opacity() {
    let data = read_fixture("kitten-1.jpg");
    let mut image = Image::new(&data).unwrap();
    image.set_border_radius(500).unwrap();
    let blob = image.output("png", 100).unwrap();
    assert_eq!(image::guess_format(&blob).unwrap(), ImageFormat::Png);

    let mut image = Image::new(&data).unwrap();
    image.crop(100, 100, GRAVITY_CENTER).unwrap();
    image.set_opacity(0.5).unwrap();
    let blob = image.output("png", 100).unwrap();
    assert_eq!(probe_dims(&blob), (100, 100));
}

#[test]
fn gif_first_frame_dimensions() {
    let mut image = Image::new(&read_fixture("last-frame-1px.gif")).unwrap();
    image.crop(0, 0, GRAVITY_CENTER).unwrap();
    let blob = image.output("gif", 100).unwrap();
    assert_eq!(probe_dims(&blob), (329, 274));
}

#[test]
fn gif_crop_100() {
    let mut image = Image::new(&read_fixture("kitten-3.gif")).unwrap();
    image.crop(100, 100, GRAVITY_CENTER).unwrap();
    let blob = image.output("gif", 100).unwrap();
    assert_eq!(probe_dims(&blob), (100, 100));
    assert!(image.frame_count() >= 1);
}

#[test]
fn crop_animated_webp_preserves_frames() {
    let mut image = Image::new(&read_fixture("anim-delta.webp")).unwrap();
    assert!(image.frame_count() > 1);
    image.crop(32, 32, GRAVITY_CENTER).unwrap();
    let blob = image.output("webp", 100).unwrap();
    assert!(blob.starts_with(b"RIFF"));

    // Re-decode with utopia-image to inspect frames.
    let out = Image::new(&blob).unwrap();
    assert!(out.frame_count() > 1);
    assert_eq!((out.width(), out.height()), (32, 32));
}

#[test]
fn crop_animated_webp_preserves_hold_frame_delay() {
    // Build a 3-frame GIF (red, red, blue) with 400ms delays → 120 GIF delay units.
    use image::codecs::gif::{GifEncoder, Repeat};
    use image::{Delay, Frame};

    let mut gif_bytes = Vec::new();
    {
        let mut enc = GifEncoder::new_with_speed(&mut gif_bytes, 10);
        enc.set_repeat(Repeat::Infinite).unwrap();
        for color in [
            Rgba([255, 0, 0, 255]),
            Rgba([255, 0, 0, 255]),
            Rgba([0, 0, 255, 255]),
        ] {
            let frame_img = RgbaImage::from_pixel(40, 40, color);
            let delay = Delay::from_numer_denom_ms(400, 1);
            enc.encode_frame(Frame::from_parts(frame_img, 0, 0, delay))
                .unwrap();
        }
    }

    let mut image = Image::new(&gif_bytes).unwrap();
    image.crop(20, 20, GRAVITY_CENTER).unwrap();
    let blob = image.output("webp", 100).unwrap();
    let out = Image::new(&blob).unwrap();
    assert!(out.frame_count() >= 2);
    // Playback length: ~1200ms (3×400). Hold frames must not collapse the timeline.
    let anim = webp::AnimDecoder::new(&blob).decode().unwrap();
    assert!(anim.len() >= 2);
    let mut stamps: Vec<i32> = (&anim).into_iter().map(|f| f.get_time_ms()).collect();
    stamps.sort_unstable();
    let total = stamps.last().copied().unwrap_or(0) - stamps.first().copied().unwrap_or(0);
    assert!(
        total >= 1100,
        "total timeline {total}ms too short (want ≥1100): {stamps:?}"
    );
}

#[test]
fn avif_encode_when_available() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(64, 64, GRAVITY_CENTER).unwrap();
    match image.output("avif", 60) {
        Ok(blob) => {
            assert!(!blob.is_empty());
            // AVIF is ISOBMFF - typically starts with ftyp box.
            assert!(blob.len() > 12);
        }
        Err(ImageError::Unsupported("avif")) => {
            // Feature disabled in this build.
        }
        Err(e) => panic!("unexpected avif error: {e}"),
    }
}

#[test]
fn heic_encode_when_available() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(64, 64, GRAVITY_CENTER).unwrap();
    match image.output("heic", 80) {
        Ok(blob) => {
            assert!(blob.len() > 16);
            assert_eq!(&blob[4..8], b"ftyp");
            // Major / compatible brands should advertise HEVC HEIF, not AVIF.
            let box_size = u32::from_be_bytes(blob[0..4].try_into().unwrap()) as usize;
            let brands = &blob[8..box_size.min(blob.len())];
            let has_heic = brands
                .chunks_exact(4)
                .any(|b| matches!(b, b"heic" | b"heix" | b"heim" | b"heis" | b"hevc" | b"hevx"));
            assert!(
                has_heic,
                "missing heic brand in {:?}",
                String::from_utf8_lossy(brands)
            );
        }
        Err(ImageError::Unsupported("heic")) => {
            // Feature disabled in this build.
        }
        Err(e) => panic!("unexpected heic error: {e}"),
    }
}

#[cfg(feature = "heic")]
#[test]
fn heic_roundtrip_decode() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    image.crop(48, 48, GRAVITY_CENTER).unwrap();
    let blob = image.output("heic", 70).expect("heic encode");
    let round = Image::new(&blob).expect("heic decode");
    assert_eq!(round.width(), 48);
    assert_eq!(round.height(), 48);
    assert_eq!(round.frame_count(), 1);
}

#[cfg(feature = "heic")]
#[test]
fn heic_quality_affects_size() {
    let mut hi = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    hi.crop(128, 128, GRAVITY_CENTER).unwrap();
    let mut lo = hi.clone();
    let high = hi.output("heic", 95).unwrap();
    let low = lo.output("heic", 20).unwrap();
    assert!(
        low.len() < high.len(),
        "q20 ({} B) should be smaller than q95 ({} B)",
        low.len(),
        high.len()
    );
}

#[test]
fn invalid_type() {
    let mut image = Image::new(&read_fixture("kitten-1.jpg")).unwrap();
    let err = image.output("bmp", 80).unwrap_err();
    assert!(matches!(err, ImageError::InvalidType));
}

#[test]
fn set_background_flattens() {
    let mut src = RgbaImage::from_pixel(4, 4, Rgba([255, 0, 0, 128]));
    src.put_pixel(0, 0, Rgba([0, 0, 255, 0]));
    let mut png = Vec::new();
    image::codecs::png::PngEncoder::new(&mut png)
        .write_image(src.as_raw(), 4, 4, image::ExtendedColorType::Rgba8)
        .unwrap();
    let mut image = Image::new(&png).unwrap();
    image.set_background("#ffffff").unwrap();
    let blob = image.output("png", 100).unwrap();
    let out = image::load_from_memory(&blob).unwrap().to_rgba8();
    assert_eq!(out.get_pixel(0, 0).0[3], 255);
}

#[test]
fn crop_100x400_and_400x100() {
    let data = read_fixture("kitten-1.jpg");
    let mut a = Image::new(&data).unwrap();
    a.crop(100, 400, GRAVITY_CENTER).unwrap();
    assert_eq!(probe_dims(&a.output("jpg", 100).unwrap()), (100, 400));

    let mut b = Image::new(&data).unwrap();
    b.crop(400, 100, GRAVITY_CENTER).unwrap();
    assert_eq!(probe_dims(&b.output("jpg", 100).unwrap()), (400, 100));
}
