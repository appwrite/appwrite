use std::io::{Read, Write};

use flate2::{read::DeflateDecoder, write::DeflateEncoder, Compression as FlateLevel};

use crate::error::CompressionError;

pub fn is_supported() -> bool {
    true
}

pub fn compress(data: &[u8]) -> Result<Vec<u8>, CompressionError> {
    let mut encoder = DeflateEncoder::new(Vec::new(), FlateLevel::default());
    encoder
        .write_all(data)
        .map_err(|e| CompressionError::Compress(e.to_string()))?;
    encoder
        .finish()
        .map_err(|e| CompressionError::Compress(e.to_string()))
}

pub fn decompress(data: &[u8]) -> Result<Vec<u8>, CompressionError> {
    let mut decoder = DeflateDecoder::new(data);
    let mut out = Vec::new();
    decoder
        .read_to_end(&mut out)
        .map_err(|e| CompressionError::Decompress(e.to_string()))?;
    Ok(out)
}
