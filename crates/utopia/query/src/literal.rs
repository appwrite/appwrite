//! How a dialect writes and reads a string literal.

use crate::error::{Error, Result};

/// The rules a dialect's string literals follow.
///
/// PHP: `Utopia\Query\StringLiteral`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum StringLiteral {
    /// MySQL and MariaDB: a backslash escapes in every literal, and one after a
    /// byte of 0x80 or above is refused.
    #[default]
    Backslash,
    /// ClickHouse: a backslash escapes, and the connection is always UTF-8.
    BackslashUtf8,
    /// PostgreSQL: a backslash escapes only in `E'...'`.
    Extended,
    /// SQLite: a backslash is a character like any other.
    Standard,
}

fn refused(message: &str) -> Error {
    Error::Validation(message.into())
}

impl StringLiteral {
    /// The names of the cases, as PHP declares them.
    pub const CASES: [(StringLiteral, &'static str); 4] = [
        (StringLiteral::Backslash, "Backslash"),
        (StringLiteral::BackslashUtf8, "BackslashUtf8"),
        (StringLiteral::Extended, "Extended"),
        (StringLiteral::Standard, "Standard"),
    ];

    /// The letter before the quote of a literal whose backslashes escape: `E`
    /// for PostgreSQL, nothing elsewhere.
    ///
    /// PHP: `escapePrefix()`.
    pub const fn escape_prefix(self) -> &'static str {
        match self {
            StringLiteral::Extended => "E",
            _ => "",
        }
    }

    /// Whether a backslash escapes in a literal without a prefix.
    ///
    /// PHP: `escapesInPlainLiteral()`.
    pub const fn escapes_in_plain_literal(self) -> bool {
        matches!(self, StringLiteral::Backslash | StringLiteral::BackslashUtf8)
    }

    const fn escapes_backslash(self) -> bool {
        !matches!(self, StringLiteral::Standard)
    }

    const fn refuses_backslash_after_high_byte(self) -> bool {
        matches!(self, StringLiteral::Backslash | StringLiteral::Extended)
    }

    const fn refuses_nul(self) -> bool {
        matches!(self, StringLiteral::Extended | StringLiteral::Standard)
    }

    /// `value` as a literal of the dialect. A NUL byte is refused where the
    /// dialect has none, and so is a backslash after a character above 127
    /// where a multibyte connection charset could read the pair as one
    /// character.
    ///
    /// PHP: `quote()`.
    ///
    /// ```
    /// use utopia_query::StringLiteral;
    ///
    /// assert_eq!(StringLiteral::Backslash.quote("it's a\\b")?, "'it''s a\\\\b'");
    /// assert_eq!(StringLiteral::Extended.quote("a\\b")?, "E'a\\\\b'");
    /// assert_eq!(StringLiteral::Standard.quote("a\\b")?, "'a\\b'");
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    pub fn quote(self, value: &str) -> Result<String> {
        if self.refuses_nul() && value.contains('\0') {
            return Err(refused("String literal contains a NUL byte"));
        }
        let escapes = self.escapes_backslash() && value.contains('\\');
        if escapes && self.refuses_backslash_after_high_byte() {
            let mut before = '\0';
            for c in value.chars() {
                if c == '\\' && !before.is_ascii() {
                    return Err(refused(
                        "String literal has a backslash after a byte of 0x80 or above, which a multibyte connection charset can read as part of the preceding character",
                    ));
                }
                before = c;
            }
        }
        let mut out = String::with_capacity(value.len() + 3);
        if escapes {
            out.push_str(self.escape_prefix());
        }
        out.push('\'');
        for c in value.chars() {
            match c {
                '\'' => out.push_str("''"),
                '\\' if escapes => out.push_str("\\\\"),
                c => out.push(c),
            }
        }
        out.push('\'');
        Ok(out)
    }

    /// The bytes a literal of the dialect stands for.
    ///
    /// PHP: `unquote()`.
    ///
    /// ```
    /// use utopia_query::StringLiteral;
    ///
    /// assert_eq!(StringLiteral::Backslash.unquote(b"'a\\nb'")?, b"a\nb");
    /// assert_eq!(StringLiteral::Extended.unquote(b"E'\\u00e9'")?, "é".as_bytes());
    /// assert_eq!(StringLiteral::Standard.unquote(b"'a\\nb'")?, b"a\\nb");
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    pub fn unquote(self, literal: &[u8]) -> Result<Vec<u8>> {
        let malformed = || refused("Malformed string literal");
        let prefix = self.escape_prefix().as_bytes();
        let mut escaped = self.escapes_in_plain_literal();
        let mut literal = literal;
        if !prefix.is_empty()
            && literal.len() > prefix.len()
            && literal[..prefix.len()].eq_ignore_ascii_case(prefix)
            && literal[prefix.len()] == b'\''
        {
            literal = &literal[prefix.len()..];
            escaped = true;
        }
        if literal.len() < 2 || literal[0] != b'\'' || literal[literal.len() - 1] != b'\'' {
            return Err(malformed());
        }
        let body = &literal[1..literal.len() - 1];
        let mut value = Vec::with_capacity(body.len());
        let mut position = 0;
        while position < body.len() {
            let byte = body[position];
            if byte == b'\'' {
                if body.get(position + 1) != Some(&b'\'') {
                    return Err(malformed());
                }
                value.push(b'\'');
                position += 2;
            } else if escaped && byte == b'\\' {
                if position + 1 >= body.len() {
                    return Err(malformed());
                }
                let escape = self.read_escape(body, position + 1);
                self.decode(escape, &mut value)?;
                position += 1 + escape.len();
            } else {
                value.push(byte);
                position += 1;
            }
        }
        if self.refuses_nul() && value.contains(&0) {
            return Err(refused("String literal contains a NUL byte"));
        }
        Ok(value)
    }

    /// The escape that starts at `position`, without its backslash.
    fn read_escape(self, body: &[u8], position: usize) -> &[u8] {
        let one = &body[position..=position];
        match (self, body[position]) {
            (StringLiteral::BackslashUtf8, b'x') => read_hex(body, position, 2, 2),
            (StringLiteral::Extended, b'0'..=b'7') => {
                let digits = body[position..].iter().take(3).take_while(|byte| (b'0'..=b'7').contains(byte)).count();
                &body[position..position + digits]
            }
            (StringLiteral::Extended, b'x') => read_hex(body, position, 1, 2),
            (StringLiteral::Extended, b'u') => read_hex(body, position, 4, 4),
            (StringLiteral::Extended, b'U') => read_hex(body, position, 8, 8),
            _ => one,
        }
    }

    fn decode(self, escape: &[u8], value: &mut Vec<u8>) -> Result<()> {
        let numeric = escape.len() > 1 || (self == StringLiteral::Extended && (b'0'..=b'7').contains(&escape[0]));
        if numeric {
            let digits = std::str::from_utf8(&escape[1..]).unwrap_or_default();
            match escape[0] {
                // PHP keeps the low byte of what the digits spell.
                b'x' => value.push(u32::from_str_radix(digits, 16).unwrap_or(0) as u8),
                b'u' | b'U' => {
                    let point = u32::from_str_radix(digits, 16).ok().and_then(char::from_u32);
                    let point = point.ok_or_else(|| refused("String literal has an invalid Unicode escape"))?;
                    value.extend_from_slice(point.encode_utf8(&mut [0; 4]).as_bytes());
                }
                _ => {
                    let digits = std::str::from_utf8(escape).unwrap_or_default();
                    value.push(u32::from_str_radix(digits, 8).unwrap_or(0) as u8);
                }
            }
            return Ok(());
        }
        let byte = escape[0];
        let decoded = match (self, byte) {
            (StringLiteral::Backslash | StringLiteral::BackslashUtf8, b'0') => 0,
            (StringLiteral::BackslashUtf8, b'a') => 0x07,
            (StringLiteral::Backslash | StringLiteral::BackslashUtf8 | StringLiteral::Extended, b'b') => 0x08,
            (StringLiteral::BackslashUtf8 | StringLiteral::Extended, b'f') => 0x0C,
            (StringLiteral::Backslash | StringLiteral::BackslashUtf8 | StringLiteral::Extended, b'n') => b'\n',
            (StringLiteral::Backslash | StringLiteral::BackslashUtf8 | StringLiteral::Extended, b'r') => b'\r',
            (StringLiteral::Backslash | StringLiteral::BackslashUtf8 | StringLiteral::Extended, b't') => b'\t',
            (StringLiteral::BackslashUtf8, b'v') => 0x0B,
            (StringLiteral::Backslash, b'Z') => 0x1A,
            (StringLiteral::Backslash, b'%' | b'_') | (StringLiteral::Standard, _) => {
                value.push(b'\\');
                byte
            }
            _ => byte,
        };
        value.push(decoded);
        Ok(())
    }
}

/// `x`, `u` or `U` at `position` with the hex digits after it: the letter
/// alone when there are fewer than `minimum`.
fn read_hex(body: &[u8], position: usize, minimum: usize, maximum: usize) -> &[u8] {
    let digits = body[position + 1..].iter().take(maximum).take_while(|byte| byte.is_ascii_hexdigit()).count();
    if digits < minimum { &body[position..=position] } else { &body[position..=position + digits] }
}

/// The bytes of text that holds a character for each byte.
pub(crate) fn narrow(widened: &[u8]) -> Vec<u8> {
    String::from_utf8_lossy(widened).chars().map(|c| u8::try_from(u32::from(c)).unwrap_or(b'?')).collect()
}

/// `bytes` as text that holds a character for each byte.
pub(crate) fn widen(bytes: &[u8]) -> Vec<u8> {
    bytes.iter().map(|&byte| char::from(byte)).collect::<String>().into_bytes()
}
