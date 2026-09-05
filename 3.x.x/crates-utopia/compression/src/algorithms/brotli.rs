use brotli::enc::BrotliEncoderParams;

use crate::error::CompressionError;

pub const LEVEL_MIN: u32 = 0;
pub const LEVEL_MAX: u32 = 11;
pub const LEVEL_DEFAULT: u32 = 11;

pub fn is_supported() -> bool {
    true
}

pub fn compress(data: &[u8], level: u32) -> Result<Vec<u8>, CompressionError> {
    let params = BrotliEncoderParams {
        quality: level as i32,
        ..Default::default()
    };

    let mut out = Vec::new();
    brotli::BrotliCompress(&mut std::io::Cursor::new(data), &mut out, &params)
        .map_err(|e| CompressionError::Compress(e.to_string()))?;
    Ok(out)
}

pub fn decompress(data: &[u8]) -> Result<Vec<u8>, CompressionError> {
    let mut out = Vec::new();
    brotli::BrotliDecompress(&mut std::io::Cursor::new(data), &mut out)
        .map_err(|e| CompressionError::Decompress(e.to_string()))?;
    Ok(out)
}
