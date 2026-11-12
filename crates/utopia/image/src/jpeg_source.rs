//! What a JPEG's headers say about how it was written, which ImageMagick
//! keeps on the image and reuses when it writes one: the quality, estimated
//! from the quantization tables (`JPEGSetImageQuality` in `coders/jpeg.c`: the
//! tables' sum and four of their entries are matched against what libjpeg's
//! standard tables give at each quality from 1 to 100), and the chroma
//! subsampling.

/// libjpeg's standard luminance and chrominance tables (`jcparam.c`), in
/// natural order.
const LUMINANCE: [u16; 64] = [
    16, 11, 10, 16, 24, 40, 51, 61, 12, 12, 14, 19, 26, 58, 60, 55, 14, 13, 16, 24, 40, 57, 69, 56, 14, 17, 22, 29, 51,
    87, 80, 62, 18, 22, 37, 56, 68, 109, 103, 77, 24, 35, 55, 64, 81, 104, 113, 92, 49, 64, 78, 87, 103, 121, 120, 101,
    72, 92, 95, 98, 112, 100, 103, 99,
];
const CHROMINANCE: [u16; 64] = [
    17, 18, 24, 47, 99, 99, 99, 99, 18, 21, 26, 66, 99, 99, 99, 99, 24, 26, 56, 99, 99, 99, 99, 99, 47, 66, 99, 99, 99,
    99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99,
    99, 99, 99, 99, 99, 99,
];

/// The order a DQT segment stores a table in: `ZIGZAG[i]` is the natural
/// index of its `i`th value.
const ZIGZAG: [usize; 64] = [
    0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5, 12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21,
    28, 35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51, 58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54,
    47, 55, 62, 63,
];

/// `jpeg_add_quant_table` at `quality` (`jpeg_quality_scaling`, baseline).
fn scaled(base: &[u16; 64], quality: u32) -> [u16; 64] {
    let scale = if quality < 50 { 5000 / quality } else { 200 - quality * 2 };
    base.map(|value| ((u32::from(value) * scale + 50) / 100).clamp(1, 255) as u16)
}

/// The segments of `data` up to the first scan: each marker and its payload.
fn segments(data: &[u8]) -> impl Iterator<Item = (u8, &[u8])> {
    let mut at = 2;
    std::iter::from_fn(move || {
        let &[0xFF, marker, high, low] = data.get(at..at + 4)? else { return None };
        let length = usize::from(u16::from_be_bytes([high, low]));
        let payload = data.get(at + 4..at + 2 + length)?;
        at += 2 + length;
        (marker != 0xDA).then_some((marker, payload))
    })
}

/// The quantization tables of `data`, by table number, in natural order.
fn tables(data: &[u8]) -> [Option<[u16; 64]>; 4] {
    let mut tables = [None; 4];
    for (_, mut payload) in segments(data).filter(|&(marker, _)| marker == 0xDB) {
        while let Some(&info) = payload.first() {
            let (wide, number) = (info >> 4 != 0, usize::from(info & 0x0F));
            let size = if wide { 128 } else { 64 };
            let Some(values) = payload.get(1..1 + size) else { break };
            let mut table = [0u16; 64];
            for (i, &natural) in ZIGZAG.iter().enumerate() {
                table[natural] =
                    if wide { u16::from_be_bytes([values[i * 2], values[i * 2 + 1]]) } else { u16::from(values[i]) };
            }
            if let Some(slot) = tables.get_mut(number) {
                *slot = Some(table);
            }
            payload = &payload[1 + size..];
        }
    }
    tables
}

/// The luma sampling factors of `data` (a JPEG with three components):
/// `(2, 2)` is 4:2:0 and `(1, 1)` 4:4:4.
pub(crate) fn sampling(data: &[u8]) -> Option<(u8, u8)> {
    // A frame header (SOF0 to SOF15, but not the DHT, JPG and DAC markers among
    // them): precision, height, width, then three bytes for each component.
    let frame_header = |marker: u8| (0xC0..=0xCF).contains(&marker) && ![0xC4, 0xC8, 0xCC].contains(&marker);
    let (_, header) = segments(data).find(|&(marker, _)| frame_header(marker))?;
    let (&components, &luma) = (header.get(5)?, header.get(7)?);
    (components == 3).then_some((luma >> 4, luma & 0x0F))
}

/// The quality `data` (a JPEG) was written at, 1 to 100; `None` when its
/// tables match no quality closely enough, as ImageMagick leaves it undefined.
pub(crate) fn quality(data: &[u8]) -> Option<i64> {
    let tables = tables(data);
    let first = tables[0]?;
    let sum: u32 = tables.iter().flatten().flatten().map(|&value| u32::from(value)).sum();
    let entries = |quality: u32| -> (u32, u32) {
        let luminance = scaled(&LUMINANCE, quality);
        let total = |table: &[u16; 64]| table.iter().map(|&value| u32::from(value)).sum::<u32>();
        match tables[1] {
            Some(_) => {
                let chrominance = scaled(&CHROMINANCE, quality);
                let hash = luminance[2] + luminance[53] + chrominance[0] + chrominance[63];
                (u32::from(hash), total(&luminance) + total(&chrominance))
            }
            None => (u32::from(luminance[2] + luminance[53]), total(&luminance)),
        }
    };
    let value = match tables[1] {
        Some(second) => u32::from(first[2] + first[53] + second[0] + second[63]),
        None => u32::from(first[2] + first[53]),
    };
    for quality in 1..=100u32 {
        let (hash, sums) = entries(quality);
        if value < hash && sum < sums {
            continue;
        }
        let close = value <= hash && sum <= sums;
        return (close || quality > 50).then_some(i64::from(quality));
    }
    None
}

#[cfg(test)]
mod tests {
    use super::{quality as estimate, sampling};
    use image::ImageEncoder;

    /// What `Imagick::getImageCompressionQuality()` reports for the package's
    /// own test images (ImageMagick 7.1.2-35).
    #[test]
    fn matches_imagemagick_on_the_test_images() {
        let read = |name: &str| std::fs::read(format!("../../../packages/image/tests/resources/{name}")).unwrap();
        assert_eq!(estimate(&read("disk-a/kitten-1.jpg")), Some(92));
        assert_eq!(estimate(&read("resize/100x100-q30.jpg")), Some(10));
        assert_eq!(estimate(&read("resize/100x100.jpg")), Some(100));
    }

    #[test]
    fn reads_back_the_quality_a_jpeg_was_written_at() {
        let pixels: Vec<u8> = (0..32 * 32 * 3).map(|i| (i * 7 % 251) as u8).collect();
        for quality in [1, 5, 10, 25, 49, 50, 51, 75, 90, 92, 99, 100] {
            let mut jpeg = Vec::new();
            image::codecs::jpeg::JpegEncoder::new_with_quality(&mut jpeg, quality)
                .write_image(&pixels, 32, 32, image::ExtendedColorType::Rgb8)
                .unwrap();
            assert_eq!(estimate(&jpeg), Some(i64::from(quality)), "quality {quality}");
        }
    }

    #[test]
    fn reads_the_chroma_subsampling() {
        let read = |name: &str| std::fs::read(format!("../../../packages/image/tests/resources/{name}")).unwrap();
        assert_eq!(sampling(&read("disk-a/kitten-1.jpg")), Some((2, 2)));
        assert_eq!(sampling(&read("resize/100x100.png")), None);
        assert_eq!(sampling(b""), None);
    }

    #[test]
    fn is_undefined_without_tables() {
        assert_eq!(estimate(b"\xFF\xD8\xFF\xD9"), None);
        assert_eq!(estimate(b""), None);
    }
}
