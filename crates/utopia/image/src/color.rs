//! Colours as ImageMagick reads them from strings.

use crate::error::{Error, Result};

/// An RGBA colour with 8 bits per channel (alpha 255 is opaque).
///
/// Parsed from the strings ImageMagick accepts for borders and backgrounds:
/// `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`, `rgb(r, g, b)`, `rgba(r, g, b, a)`
/// (channels as 0–255 or percentages, alpha as 0–1), the CSS colour names,
/// `transparent` and `none`. The empty string is ImageMagick's background
/// colour, white.
///
/// PHP: the `$borderColor` of `setBorder()` and the `$color` of
/// `setBackground()`, which Imagick parses with `QueryColorCompliance`.
///
/// ```
/// use utopia_image::Color;
///
/// assert_eq!(Color::parse("#ff000080")?, Color::rgba(255, 0, 0, 128));
/// assert_eq!(Color::parse("red")?, Color::rgba(255, 0, 0, 255));
/// assert!(Color::parse("notacolor").is_err());
/// # Ok::<(), utopia_image::Error>(())
/// ```
///
/// ```php
/// $pixel = new \ImagickPixel('#ff000080');
/// print_r($pixel->getColor(1)); // r 1, g 0, b 0, a 0.5
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub struct Color(pub [u8; 4]);

impl Color {
    /// Fully transparent black.
    pub const TRANSPARENT: Color = Color([0, 0, 0, 0]);
    /// Opaque white, ImageMagick's default background colour.
    pub const WHITE: Color = Color([255, 255, 255, 255]);
    /// Opaque black.
    pub const BLACK: Color = Color([0, 0, 0, 255]);

    /// A colour from its channels.
    pub const fn rgba(r: u8, g: u8, b: u8, a: u8) -> Self {
        Color([r, g, b, a])
    }

    /// Whether the colour is not fully opaque.
    pub const fn has_alpha(self) -> bool {
        self.0[3] != 255
    }

    /// Parses a colour string, failing with `Unrecognized color string` as
    /// Imagick does.
    pub fn parse(text: &str) -> Result<Self> {
        Self::try_parse(text).ok_or_else(|| Error::imagick("Unrecognized color string"))
    }

    fn try_parse(text: &str) -> Option<Self> {
        let text = text.trim();
        if text.is_empty() {
            return Some(Color::WHITE);
        }
        if let Some(hex) = text.strip_prefix('#') {
            return parse_hex(hex);
        }
        let lower = text.to_ascii_lowercase();
        if let Some(args) = lower.strip_prefix("rgba(").and_then(|s| s.strip_suffix(')')) {
            return parse_functional(args, true);
        }
        if let Some(args) = lower.strip_prefix("rgb(").and_then(|s| s.strip_suffix(')')) {
            return parse_functional(args, false);
        }
        if lower == "none" || lower == "transparent" {
            return Some(Color::TRANSPARENT);
        }
        NAMES.binary_search_by(|(name, _)| name.cmp(&lower.as_str())).ok().map(|i| {
            let v = NAMES[i].1;
            Color([(v >> 16) as u8, (v >> 8) as u8, v as u8, 255])
        })
    }
}

fn parse_hex(hex: &str) -> Option<Color> {
    if !hex.bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    let nibble = |i: usize| u8::from_str_radix(&hex[i..=i], 16).ok();
    let byte = |i: usize| u8::from_str_radix(&hex[i..i + 2], 16).ok();
    let word = |i: usize| u16::from_str_radix(&hex[i..i + 4], 16).ok().map(|v| (v >> 8) as u8);
    match hex.len() {
        3 => Some(Color([nibble(0)? * 17, nibble(1)? * 17, nibble(2)? * 17, 255])),
        4 => Some(Color([nibble(0)? * 17, nibble(1)? * 17, nibble(2)? * 17, nibble(3)? * 17])),
        6 => Some(Color([byte(0)?, byte(2)?, byte(4)?, 255])),
        8 => Some(Color([byte(0)?, byte(2)?, byte(4)?, byte(6)?])),
        12 => Some(Color([word(0)?, word(4)?, word(8)?, 255])),
        16 => Some(Color([word(0)?, word(4)?, word(8)?, word(12)?])),
        _ => None,
    }
}

fn parse_functional(args: &str, alpha: bool) -> Option<Color> {
    let parts: Vec<&str> = args.split(',').map(str::trim).collect();
    if parts.len() != if alpha { 4 } else { 3 } {
        return None;
    }
    let channel = |s: &str| -> Option<u8> {
        let value = if let Some(p) = s.strip_suffix('%') {
            p.parse::<f64>().ok()? * 255.0 / 100.0
        } else {
            s.parse::<f64>().ok()?
        };
        value.is_finite().then(|| value.clamp(0.0, 255.0).round() as u8)
    };
    let a = if alpha {
        let s = parts[3];
        let value = if let Some(p) = s.strip_suffix('%') { p.parse::<f64>().ok()? / 100.0 } else { s.parse().ok()? };
        if !value.is_finite() {
            return None;
        }
        (value.clamp(0.0, 1.0) * 255.0).round() as u8
    } else {
        255
    };
    Some(Color([channel(parts[0])?, channel(parts[1])?, channel(parts[2])?, a]))
}

/// The CSS colour names (as ImageMagick's SVG-compliant table), sorted.
const NAMES: &[(&str, u32)] = &[
    ("aliceblue", 0xF0F8FF),
    ("antiquewhite", 0xFAEBD7),
    ("aqua", 0x00FFFF),
    ("aquamarine", 0x7FFFD4),
    ("azure", 0xF0FFFF),
    ("beige", 0xF5F5DC),
    ("bisque", 0xFFE4C4),
    ("black", 0x000000),
    ("blanchedalmond", 0xFFEBCD),
    ("blue", 0x0000FF),
    ("blueviolet", 0x8A2BE2),
    ("brown", 0xA52A2A),
    ("burlywood", 0xDEB887),
    ("cadetblue", 0x5F9EA0),
    ("chartreuse", 0x7FFF00),
    ("chocolate", 0xD2691E),
    ("coral", 0xFF7F50),
    ("cornflowerblue", 0x6495ED),
    ("cornsilk", 0xFFF8DC),
    ("crimson", 0xDC143C),
    ("cyan", 0x00FFFF),
    ("darkblue", 0x00008B),
    ("darkcyan", 0x008B8B),
    ("darkgoldenrod", 0xB8860B),
    ("darkgray", 0xA9A9A9),
    ("darkgreen", 0x006400),
    ("darkgrey", 0xA9A9A9),
    ("darkkhaki", 0xBDB76B),
    ("darkmagenta", 0x8B008B),
    ("darkolivegreen", 0x556B2F),
    ("darkorange", 0xFF8C00),
    ("darkorchid", 0x9932CC),
    ("darkred", 0x8B0000),
    ("darksalmon", 0xE9967A),
    ("darkseagreen", 0x8FBC8F),
    ("darkslateblue", 0x483D8B),
    ("darkslategray", 0x2F4F4F),
    ("darkslategrey", 0x2F4F4F),
    ("darkturquoise", 0x00CED1),
    ("darkviolet", 0x9400D3),
    ("deeppink", 0xFF1493),
    ("deepskyblue", 0x00BFFF),
    ("dimgray", 0x696969),
    ("dimgrey", 0x696969),
    ("dodgerblue", 0x1E90FF),
    ("firebrick", 0xB22222),
    ("floralwhite", 0xFFFAF0),
    ("forestgreen", 0x228B22),
    ("fuchsia", 0xFF00FF),
    ("gainsboro", 0xDCDCDC),
    ("ghostwhite", 0xF8F8FF),
    ("gold", 0xFFD700),
    ("goldenrod", 0xDAA520),
    ("gray", 0x808080),
    ("green", 0x008000),
    ("greenyellow", 0xADFF2F),
    ("grey", 0x808080),
    ("honeydew", 0xF0FFF0),
    ("hotpink", 0xFF69B4),
    ("indianred", 0xCD5C5C),
    ("indigo", 0x4B0082),
    ("ivory", 0xFFFFF0),
    ("khaki", 0xF0E68C),
    ("lavender", 0xE6E6FA),
    ("lavenderblush", 0xFFF0F5),
    ("lawngreen", 0x7CFC00),
    ("lemonchiffon", 0xFFFACD),
    ("lightblue", 0xADD8E6),
    ("lightcoral", 0xF08080),
    ("lightcyan", 0xE0FFFF),
    ("lightgoldenrodyellow", 0xFAFAD2),
    ("lightgray", 0xD3D3D3),
    ("lightgreen", 0x90EE90),
    ("lightgrey", 0xD3D3D3),
    ("lightpink", 0xFFB6C1),
    ("lightsalmon", 0xFFA07A),
    ("lightseagreen", 0x20B2AA),
    ("lightskyblue", 0x87CEFA),
    ("lightslategray", 0x778899),
    ("lightslategrey", 0x778899),
    ("lightsteelblue", 0xB0C4DE),
    ("lightyellow", 0xFFFFE0),
    ("lime", 0x00FF00),
    ("limegreen", 0x32CD32),
    ("linen", 0xFAF0E6),
    ("magenta", 0xFF00FF),
    ("maroon", 0x800000),
    ("mediumaquamarine", 0x66CDAA),
    ("mediumblue", 0x0000CD),
    ("mediumorchid", 0xBA55D3),
    ("mediumpurple", 0x9370DB),
    ("mediumseagreen", 0x3CB371),
    ("mediumslateblue", 0x7B68EE),
    ("mediumspringgreen", 0x00FA9A),
    ("mediumturquoise", 0x48D1CC),
    ("mediumvioletred", 0xC71585),
    ("midnightblue", 0x191970),
    ("mintcream", 0xF5FFFA),
    ("mistyrose", 0xFFE4E1),
    ("moccasin", 0xFFE4B5),
    ("navajowhite", 0xFFDEAD),
    ("navy", 0x000080),
    ("oldlace", 0xFDF5E6),
    ("olive", 0x808000),
    ("olivedrab", 0x6B8E23),
    ("orange", 0xFFA500),
    ("orangered", 0xFF4500),
    ("orchid", 0xDA70D6),
    ("palegoldenrod", 0xEEE8AA),
    ("palegreen", 0x98FB98),
    ("paleturquoise", 0xAFEEEE),
    ("palevioletred", 0xDB7093),
    ("papayawhip", 0xFFEFD5),
    ("peachpuff", 0xFFDAB9),
    ("peru", 0xCD853F),
    ("pink", 0xFFC0CB),
    ("plum", 0xDDA0DD),
    ("powderblue", 0xB0E0E6),
    ("purple", 0x800080),
    ("red", 0xFF0000),
    ("rosybrown", 0xBC8F8F),
    ("royalblue", 0x4169E1),
    ("saddlebrown", 0x8B4513),
    ("salmon", 0xFA8072),
    ("sandybrown", 0xF4A460),
    ("seagreen", 0x2E8B57),
    ("seashell", 0xFFF5EE),
    ("sienna", 0xA0522D),
    ("silver", 0xC0C0C0),
    ("skyblue", 0x87CEEB),
    ("slateblue", 0x6A5ACD),
    ("slategray", 0x708090),
    ("slategrey", 0x708090),
    ("snow", 0xFFFAFA),
    ("springgreen", 0x00FF7F),
    ("steelblue", 0x4682B4),
    ("tan", 0xD2B48C),
    ("teal", 0x008080),
    ("thistle", 0xD8BFD8),
    ("tomato", 0xFF6347),
    ("turquoise", 0x40E0D0),
    ("violet", 0xEE82EE),
    ("wheat", 0xF5DEB3),
    ("white", 0xFFFFFF),
    ("whitesmoke", 0xF5F5F5),
    ("yellow", 0xFFFF00),
    ("yellowgreen", 0x9ACD32),
];

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_are_sorted() {
        assert!(NAMES.windows(2).all(|w| w[0].0 < w[1].0));
    }

    #[test]
    fn parses_the_forms_imagemagick_accepts() {
        assert_eq!(Color::parse("").unwrap(), Color::WHITE);
        assert_eq!(Color::parse("#f00").unwrap(), Color::rgba(255, 0, 0, 255));
        assert_eq!(Color::parse("#0000ff80").unwrap(), Color::rgba(0, 0, 255, 128));
        assert_eq!(Color::parse("rgba(0,0,255,0.5)").unwrap(), Color::rgba(0, 0, 255, 128));
        assert_eq!(Color::parse("Transparent").unwrap(), Color::TRANSPARENT);
        assert_eq!(Color::parse("bad").unwrap_err().to_string(), "Unrecognized color string");
    }
}
