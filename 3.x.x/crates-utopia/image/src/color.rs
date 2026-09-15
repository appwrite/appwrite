//! Color parsing for borders and backgrounds.

use image::Rgba;

use crate::error::{ImageError, Result};

/// Parse a CSS-like color into RGBA.
///
/// Supports `#RGB`, `#RRGGBB`, `#RRGGBBAA`, and a small set of named colors
/// used by Imagick in the PHP library (`transparent`, `white`, `black`,
/// `red`, `green`, `blue`, `none`).
pub fn parse_color(color: &str) -> Result<Rgba<u8>> {
    let trimmed = color.trim();
    if trimmed.is_empty() {
        return Err(ImageError::InvalidColor(color.to_string()));
    }

    if let Some(hex) = trimmed.strip_prefix('#') {
        return parse_hex(hex).map_err(|()| ImageError::InvalidColor(color.to_string()));
    }

    match trimmed.to_ascii_lowercase().as_str() {
        "transparent" | "none" => Ok(Rgba([0, 0, 0, 0])),
        "white" => Ok(Rgba([255, 255, 255, 255])),
        "black" => Ok(Rgba([0, 0, 0, 255])),
        "red" => Ok(Rgba([255, 0, 0, 255])),
        "green" => Ok(Rgba([0, 128, 0, 255])),
        "blue" => Ok(Rgba([0, 0, 255, 255])),
        _ => Err(ImageError::InvalidColor(color.to_string())),
    }
}

fn parse_hex(hex: &str) -> std::result::Result<Rgba<u8>, ()> {
    let bytes = match hex.len() {
        3 => {
            let mut out = [0u8; 4];
            for (i, c) in hex.chars().enumerate() {
                let v = hex_nibble(c)?;
                out[i] = (v << 4) | v;
            }
            out[3] = 255;
            out
        }
        6 => {
            let r = hex_byte(&hex[0..2])?;
            let g = hex_byte(&hex[2..4])?;
            let b = hex_byte(&hex[4..6])?;
            [r, g, b, 255]
        }
        8 => {
            let r = hex_byte(&hex[0..2])?;
            let g = hex_byte(&hex[2..4])?;
            let b = hex_byte(&hex[4..6])?;
            let a = hex_byte(&hex[6..8])?;
            [r, g, b, a]
        }
        _ => return Err(()),
    };
    Ok(Rgba(bytes))
}

fn hex_nibble(c: char) -> std::result::Result<u8, ()> {
    c.to_digit(16).map(|v| v as u8).ok_or(())
}

fn hex_byte(s: &str) -> std::result::Result<u8, ()> {
    u8::from_str_radix(s, 16).map_err(|_| ())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_hex_and_names() {
        assert_eq!(parse_color("#f00").unwrap(), Rgba([255, 0, 0, 255]));
        assert_eq!(parse_color("#ff0000").unwrap(), Rgba([255, 0, 0, 255]));
        assert_eq!(parse_color("#ff000080").unwrap(), Rgba([255, 0, 0, 128]));
        assert_eq!(parse_color("transparent").unwrap(), Rgba([0, 0, 0, 0]));
    }
}
