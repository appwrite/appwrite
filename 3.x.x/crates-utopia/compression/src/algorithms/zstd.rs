use crate::error::CompressionError;

pub const LEVEL_MIN: i32 = 1;
pub const LEVEL_MAX: i32 = 22;
pub const LEVEL_DEFAULT: i32 = 3;

pub fn is_supported() -> bool {
    true
}

pub fn compress(data: &[u8], level: i32) -> Result<Vec<u8>, CompressionError> {
    zstd::bulk::compress(data, level).map_err(|e| CompressionError::Compress(e.to_string()))
}

pub fn decompress(data: &[u8]) -> Result<Vec<u8>, CompressionError> {
    zstd::decode_all(data).map_err(|e| CompressionError::Decompress(e.to_string()))
}
