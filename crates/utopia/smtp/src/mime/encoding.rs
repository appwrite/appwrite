//! Body encodings, chosen for the caller: quoted-printable for text and
//! base64 for attachments.

use std::borrow::Cow;

/// Base64 wraps at 76 characters, which is 57 bytes of input.
///
/// PHP: `Encoding::CHUNK`.
pub const CHUNK: usize = 57;

const LINE: usize = 76;
const BASE64: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

/// Whether every byte is ASCII (the empty string included).
///
/// PHP: `Encoding::isAscii()`.
///
/// ```
/// use utopia_smtp::mime::encoding;
///
/// assert!(encoding::is_ascii(b"plain text"));
/// assert!(!encoding::is_ascii("héllo".as_bytes()));
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Encoding;
///
/// var_dump(Encoding::isAscii('plain text')); // bool(true)
/// ```
pub fn is_ascii(value: &[u8]) -> bool {
    value.is_ascii()
}

/// Quoted-printable (RFC 2045 section 6.7) after [`line_endings`], with
/// lines of at most 76 characters.
///
/// PHP: `Encoding::quotedPrintable()` (`quoted_printable_encode`).
///
/// ```
/// use utopia_smtp::mime::encoding;
///
/// assert_eq!(encoding::quoted_printable("1 = 1\nhé".as_bytes()), b"1 =3D 1\r\nh=C3=A9");
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Encoding;
///
/// echo Encoding::quotedPrintable("1 = 1\nhé"), "\n"; // 1 =3D 1 / h=C3=A9
/// ```
pub fn quoted_printable(value: &[u8]) -> Vec<u8> {
    php_std::encoding::quoted_printable_encode(&line_endings(value))
}

/// Base64 in lines of 76 characters, each ended by CRLF (the last one too,
/// so a part always ends on its own line).
///
/// PHP: `Encoding::base64()` (`chunk_split(base64_encode($value), 76, "\r\n")`).
///
/// ```
/// use utopia_smtp::mime::encoding;
///
/// assert_eq!(encoding::base64(b"hello"), b"aGVsbG8=\r\n");
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Encoding;
///
/// echo Encoding::base64('hello'); // aGVsbG8=
/// ```
pub fn base64(value: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(base64_len(value.len()));
    base64_into(value, &mut out);
    out
}

/// The length [`base64`] returns for `len` bytes.
pub(crate) fn base64_len(len: usize) -> usize {
    let chars = len.div_ceil(3) * 4;
    chars + 2 * chars.div_ceil(LINE).max(1)
}

/// [`base64`], appended to `out`.
pub(crate) fn base64_into(value: &[u8], out: &mut Vec<u8>) {
    if value.is_empty() {
        out.extend_from_slice(b"\r\n");
        return;
    }
    // 57 input bytes are exactly one 76 character line.
    for line in value.chunks(CHUNK) {
        let mut groups = line.chunks_exact(3);
        for g in &mut groups {
            let n = (u32::from(g[0]) << 16) | (u32::from(g[1]) << 8) | u32::from(g[2]);
            out.extend_from_slice(&[
                BASE64[(n >> 18) as usize & 63],
                BASE64[(n >> 12) as usize & 63],
                BASE64[(n >> 6) as usize & 63],
                BASE64[n as usize & 63],
            ]);
        }
        match *groups.remainder() {
            [a] => {
                let n = u32::from(a) << 16;
                out.extend_from_slice(&[BASE64[(n >> 18) as usize & 63], BASE64[(n >> 12) as usize & 63], b'=', b'=']);
            }
            [a, b] => {
                let n = (u32::from(a) << 16) | (u32::from(b) << 8);
                out.extend_from_slice(&[
                    BASE64[(n >> 18) as usize & 63],
                    BASE64[(n >> 12) as usize & 63],
                    BASE64[(n >> 6) as usize & 63],
                    b'=',
                ]);
            }
            _ => {}
        }
        out.extend_from_slice(b"\r\n");
    }
}

/// Every line ending, whatever it started as (CRLF, CR or LF), becomes CRLF.
///
/// PHP: `Encoding::lineEndings()`.
///
/// ```
/// use utopia_smtp::mime::encoding;
///
/// assert_eq!(&*encoding::line_endings(b"a\n\rb"), b"a\r\n\r\nb");
/// ```
///
/// ```php
/// use Utopia\SMTP\Mime\Encoding;
///
/// var_dump(Encoding::lineEndings("a\n\rb") === "a\r\n\r\nb"); // bool(true)
/// ```
pub fn line_endings(value: &[u8]) -> Cow<'_, [u8]> {
    if !needs_line_endings(value) {
        return Cow::Borrowed(value);
    }
    let mut out = Vec::with_capacity(value.len() + value.len() / 16 + 2);
    let mut i = 0;
    while i < value.len() {
        match value[i] {
            b'\r' => {
                out.extend_from_slice(b"\r\n");
                i += if value.get(i + 1) == Some(&b'\n') { 2 } else { 1 };
            }
            b'\n' => {
                out.extend_from_slice(b"\r\n");
                i += 1;
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    Cow::Owned(out)
}

/// Whether some line ending is not already CRLF.
fn needs_line_endings(value: &[u8]) -> bool {
    let mut i = 0;
    while let Some(at) = value[i..].iter().position(|b| *b == b'\r' || *b == b'\n') {
        let at = i + at;
        if value[at] == b'\n' || value.get(at + 1) != Some(&b'\n') {
            return true;
        }
        i = at + 2;
    }
    false
}

/// A fresh multipart boundary: `=_` and 32 random hex digits. It needs no
/// quoting and cannot appear in a base64 body.
///
/// PHP: `Encoding::boundary()`.
pub fn boundary() -> String {
    format!("=_{}", random_hex())
}

/// 16 random bytes in hex, as `bin2hex(random_bytes(16))`.
pub(crate) fn random_hex() -> String {
    let mut bytes = [0u8; 16];
    // The OS generator does not fail on supported platforms; a zeroed buffer
    // would still be a valid (if predictable) identifier.
    let _ = getrandom::fill(&mut bytes);
    php_std::encoding::bin2hex(&bytes)
}
